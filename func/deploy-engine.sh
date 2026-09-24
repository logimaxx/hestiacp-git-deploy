#!/usr/bin/env bash
# Deploy engine — sourced by v-plugin-git-deploy / rollback helpers.
# Requires func/main.sh already sourced.
# shellcheck disable=SC2034

# ---------------------------------------------------------------------------
# Atomic symlink update: ln -sfn new tmp && mv -Tf tmp link
# ---------------------------------------------------------------------------
atomic_symlink() {
	local target="$1" linkpath="$2"
	local tmp="${linkpath}.new.$$"
	ln -sfn "$target" "$tmp"
	mv -Tf "$tmp" "$linkpath"
}

# Sync release into public_html (rsync fallback — Hestia-friendly)
# --checksum: avoid skipping updates when size+mtime collide (common in fast deploys)
sync_public_html() {
	local user="$1" domain="$2" release_path="$3"
	local ph
	ph="$(public_html_dir "$user" "$domain")"
	mkdir -p "$ph"
	rsync -a --delete --checksum \
		--exclude='.hestia*' \
		"${release_path}/" "${ph}/"
	chown_site "$user" "$ph"
}

# Switch current → release_id and sync public_html
switch_release() {
	local user="$1" domain="$2" release_id="$3"
	local rel_dir cur
	rel_dir="$(releases_dir "$user" "$domain")/${release_id}"
	cur="$(current_link "$user" "$domain")"
	if [[ ! -d "$rel_dir" ]]; then
		echo "Error: release not found: $release_id" >&2
		return 1
	fi
	atomic_symlink "$rel_dir" "$cur"
	chown_site "$user" "$cur"
	sync_public_html "$user" "$domain" "$rel_dir"
}

current_release_id() {
	local user="$1" domain="$2"
	local cur
	cur="$(current_link "$user" "$domain")"
	if [[ -L "$cur" ]]; then
		basename "$(readlink -f "$cur" 2>/dev/null || readlink "$cur")"
	else
		echo ""
	fi
}

list_release_ids() {
	local user="$1" domain="$2"
	local rd
	rd="$(releases_dir "$user" "$domain")"
	[[ -d "$rd" ]] || return 0
	# newest first
	find "$rd" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | sort -r
}

previous_release_id() {
	local user="$1" domain="$2"
	local current="$3"
	local id
	while IFS= read -r id; do
		[[ -n "$id" ]] || continue
		if [[ "$id" != "$current" ]]; then
			echo "$id"
			return 0
		fi
	done < <(list_release_ids "$user" "$domain")
	return 1
}

cleanup_old_releases() {
	local user="$1" domain="$2"
	local max="${MAX_RELEASES:-5}"
	local cur_id count id rd
	cur_id="$(current_release_id "$user" "$domain")"
	rd="$(releases_dir "$user" "$domain")"
	count=0
	while IFS= read -r id; do
		[[ -n "$id" ]] || continue
		count=$((count + 1))
		if (( count > max )) && [[ "$id" != "$cur_id" ]]; then
			log_msg "$user" "$domain" "Cleanup old release: $id"
			rm -rf "${rd}/${id}"
		fi
	done < <(list_release_ids "$user" "$domain")
}

# ---------------------------------------------------------------------------
# Git fetch / clone
# ---------------------------------------------------------------------------
git_env_prefix() {
	local user="$1" domain="$2"
	local auth="${AUTH_METHOD:-ssh}"
	local url="${REPO_URL:-}"

	# Local path / file:// — no remote auth helpers
	if [[ "$url" == /* || "$url" == file://* || "$url" == /*.git ]]; then
		printf 'GIT_TERMINAL_PROMPT=0'
		return 0
	fi

	if [[ "$auth" == "ssh" ]]; then
		local sshcmd
		sshcmd="$(git_ssh_command "$user" "$domain")"
		printf 'GIT_SSH_COMMAND=%q' "$sshcmd"
	else
		local helper
		helper="$(ensure_askpass_helper "$user" "$domain")"
		printf 'GIT_ASKPASS=%q GIT_TERMINAL_PROMPT=0' "$helper"
	fi
}

do_git_fetch() {
	local user="$1" domain="$2"
	local src branch depth_args
	src="$(git_src_dir "$user" "$domain")"
	branch="${BRANCH:-main}"

	if [[ "${GIT_SUBMODULES:-no}" == "yes" ]]; then
		depth_args=()
	else
		depth_args=(--depth=1)
	fi

	local envp
	envp="$(git_env_prefix "$user" "$domain")"

	if [[ ! -d "$src/.git" ]]; then
		log_msg "$user" "$domain" "Cloning ${REPO_URL} (branch ${branch})"
		mkdir -p "$(dirname "$src")"
		# Clone into temp then move, so partial clones don't leave broken git-src
		local tmp
		tmp="${src}.clone.$$"
		rm -rf "$tmp"
		# shellcheck disable=SC2086
		if ! run_as_user_bash "$user" "export ${envp}; git clone ${depth_args[*]} --branch $(printf %q "$branch") --single-branch $(printf %q "$REPO_URL") $(printf %q "$tmp")"; then
			rm -rf "$tmp"
			return 1
		fi
		rm -rf "$src"
		mv "$tmp" "$src"
		chown_site "$user" "$src"
	else
		log_msg "$user" "$domain" "Fetching origin/${branch}"
		# shellcheck disable=SC2086
		if ! run_as_user_bash "$user" "export ${envp}; cd $(printf %q "$src") && git fetch ${depth_args[*]} origin $(printf %q "$branch") && git reset --hard $(printf %q "origin/${branch}")"; then
			return 1
		fi
	fi

	if [[ "${GIT_SUBMODULES:-no}" == "yes" ]]; then
		# shellcheck disable=SC2086
		run_as_user_bash "$user" "export ${envp}; cd $(printf %q "$src") && git submodule update --init --recursive" || return 1
	fi
	return 0
}

current_commit() {
	local user="$1" domain="$2"
	local src
	src="$(git_src_dir "$user" "$domain")"
	run_as_user "$user" git -C "$src" rev-parse --short HEAD 2>/dev/null || echo ""
}

# ---------------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------------
do_install() {
	local user="$1" domain="$2"
	local cmd="${INSTALL_CMD:-}"
	local src secrets timeout
	src="$(git_src_dir "$user" "$domain")"
	secrets="$(secrets_path "$user" "$domain")"
	timeout="${TIMEOUT_SECONDS:-300}"

	if [[ -z "$cmd" ]]; then
		log_msg "$user" "$domain" "INSTALL_CMD empty — skipping install step"
		return 0
	fi

	log_msg "$user" "$domain" "Running INSTALL_CMD (timeout ${timeout}s)"
	local logfile
	logfile="$(log_path "$user" "$domain")"

	# Export secrets into environment for the build only
	local script
	script="$(
		cat <<EOF
set -euo pipefail
cd $(printf %q "$src")
if [[ -f $(printf %q "$secrets") ]]; then
  set -a
  # shellcheck disable=SC1091
  source $(printf %q "$secrets")
  set +a
fi
export HOME=$(printf %q "/home/$user")
export PATH="/usr/local/bin:/usr/bin:/bin:\$PATH"
timeout ${timeout} bash -c $(printf %q "$cmd")
EOF
	)"

	if ! run_as_user_bash "$user" "$script" >>"$logfile" 2>&1; then
		log_msg "$user" "$domain" "INSTALL_CMD failed (see deploy.log)"
		return 1
	fi
	log_msg "$user" "$domain" "INSTALL_CMD completed"
	return 0
}

# ---------------------------------------------------------------------------
# Create release from OUTPUT_DIR
# ---------------------------------------------------------------------------
create_release() {
	local user="$1" domain="$2" shortsha="$3"
	local src out_resolved release_id release_path excl_args
	src="$(git_src_dir "$user" "$domain")"

	out_resolved="$(resolve_output_dir "$src" "${OUTPUT_DIR:-dist}")" || return 1

	release_id="$(date -u +%Y%m%d-%H%M%S)-${shortsha}"
	release_path="$(releases_dir "$user" "$domain")/${release_id}"
	mkdir -p "$release_path"

	log_msg "$user" "$domain" "Creating release ${release_id} from ${OUTPUT_DIR}"

	build_rsync_excludes "${EXCLUDE}"
	rsync -a "${RSYNC_EXCLUDES[@]}" "${out_resolved}/" "${release_path}/"

	# Extra safety: never ship .git
	rm -rf "${release_path}/.git"

	chown_site "$user" "$release_path"
	echo "$release_id"
}

# ---------------------------------------------------------------------------
# Healthcheck
# ---------------------------------------------------------------------------
do_healthcheck() {
	local user="$1" domain="$2"
	local url="${HEALTHCHECK_URL:-}"
	local expect="${HEALTHCHECK_EXPECT:-200}"

	if [[ -z "$url" ]]; then
		return 0
	fi

	log_msg "$user" "$domain" "Healthcheck ${url} (expect ${expect})"
	local code
	code="$(curl -sS -o /dev/null -w '%{http_code}' \
		--max-time 15 \
		-H "Host: ${domain}" \
		"$url" 2>/dev/null || echo "000")"

	if [[ "$code" != "$expect" ]]; then
		log_msg "$user" "$domain" "Healthcheck failed: got HTTP ${code}, expected ${expect}"
		return 1
	fi
	log_msg "$user" "$domain" "Healthcheck OK"
	return 0
}

# ---------------------------------------------------------------------------
# Full deploy
# FORCE_DEPLOY=1 skips debounce
# ---------------------------------------------------------------------------
run_deploy() {
	local user="$1" domain="$2"
	local force="${FORCE_DEPLOY:-0}"
	local started finished duration shortsha release_id prev_id cfg
	local start_epoch end_epoch

	load_config "$user" "$domain"
	cfg="$(config_path "$user" "$domain")"

	if ! acquire_lock "$user" "$domain" "${TIMEOUT_SECONDS:-300}"; then
		write_status "$user" "$domain" "idle" "failed" \
			"$(read_status_field "$user" "$domain" last_commit)" \
			"$(read_status_field "$user" "$domain" last_release)" \
			"" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" 0 "deploy already running"
		return 1
	fi

	started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	start_epoch="$(date +%s)"
	write_status "$user" "$domain" "running" "" "" "" "$started" "" 0 "Deploy in progress"
	log_msg "$user" "$domain" "==== Deploy started ===="

	# Ensure dirs
	mkdir -p "$(releases_dir "$user" "$domain")"
	chown_site "$user" "$(git_deploy_dir "$user" "$domain")"

	fail() {
		local msg="$1"
		finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
		end_epoch="$(date +%s)"
		duration=$((end_epoch - start_epoch))
		log_msg "$user" "$domain" "FAIL: $msg"
		write_status "$user" "$domain" "idle" "failed" "${shortsha:-}" \
			"$(current_release_id "$user" "$domain")" "$started" "$finished" "$duration" "$msg"
		release_lock "$user" "$domain"
		return 1
	}

	if ! do_git_fetch "$user" "$domain"; then
		fail "git fetch/clone failed"
		return 1
	fi

	shortsha="$(current_commit "$user" "$domain")"
	log_msg "$user" "$domain" "Commit: ${shortsha}"

	if [[ "$force" != "1" && -n "${LAST_DEPLOYED_COMMIT:-}" && "$shortsha" == "$LAST_DEPLOYED_COMMIT" ]]; then
		finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
		end_epoch="$(date +%s)"
		duration=$((end_epoch - start_epoch))
		log_msg "$user" "$domain" "Already deployed commit ${shortsha} — noop"
		set_config_value "$cfg" "SETUP_DONE" "yes"
		write_status "$user" "$domain" "idle" "success" "$shortsha" \
			"$(current_release_id "$user" "$domain")" "$started" "$finished" "$duration" "already deployed"
		release_lock "$user" "$domain"
		return 0
	fi

	if ! do_install "$user" "$domain"; then
		fail "install script failed"
		return 1
	fi

	prev_id="$(current_release_id "$user" "$domain")"

	if ! release_id="$(create_release "$user" "$domain" "$shortsha")"; then
		fail "create release failed"
		return 1
	fi

	if ! switch_release "$user" "$domain" "$release_id"; then
		log_msg "$user" "$domain" "Switch failed — attempting revert to ${prev_id}"
		if [[ -n "$prev_id" ]]; then
			switch_release "$user" "$domain" "$prev_id" || true
		fi
		rm -rf "$(releases_dir "$user" "$domain")/${release_id}"
		fail "atomic switch failed"
		return 1
	fi

	if ! do_healthcheck "$user" "$domain"; then
		if [[ -n "$prev_id" ]]; then
			log_msg "$user" "$domain" "Auto-rollback to ${prev_id}"
			switch_release "$user" "$domain" "$prev_id" || true
			finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
			end_epoch="$(date +%s)"
			duration=$((end_epoch - start_epoch))
			write_status "$user" "$domain" "idle" "failed_rolled_back" "$shortsha" \
				"$prev_id" "$started" "$finished" "$duration" "healthcheck failed; rolled back"
			release_lock "$user" "$domain"
			return 1
		fi
		fail "healthcheck failed (no previous release to roll back)"
		return 1
	fi

	cleanup_old_releases "$user" "$domain"
	set_config_value "$cfg" "LAST_DEPLOYED_COMMIT" "$shortsha"
	set_config_value "$cfg" "SETUP_DONE" "yes"

	finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	end_epoch="$(date +%s)"
	duration=$((end_epoch - start_epoch))
	log_msg "$user" "$domain" "==== Deploy OK: ${release_id} (${duration}s) ===="
	write_status "$user" "$domain" "idle" "success" "$shortsha" "$release_id" \
		"$started" "$finished" "$duration" "Deploy OK"
	fix_git_deploy_perms "$user" "$domain"
	release_lock "$user" "$domain"
	return 0
}

run_rollback() {
	local user="$1" domain="$2" target="${3:-}"
	local cur prev

	require_git_deploy "$user" "$domain"
	load_config "$user" "$domain"

	if ! acquire_lock "$user" "$domain" 60; then
		echo "Error: cannot rollback while deploy is running" >&2
		return 1
	fi

	cur="$(current_release_id "$user" "$domain")"
	if [[ -z "$target" ]]; then
		if ! target="$(previous_release_id "$user" "$domain" "$cur")"; then
			release_lock "$user" "$domain"
			echo "Error: no previous release available" >&2
			return 1
		fi
	fi

	log_msg "$user" "$domain" "Rollback: ${cur} → ${target}"
	if ! switch_release "$user" "$domain" "$target"; then
		release_lock "$user" "$domain"
		return 1
	fi

	write_status "$user" "$domain" "idle" "success" \
		"$(read_status_field "$user" "$domain" last_commit)" \
		"$target" "" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" 0 "Rollback to ${target}"
	log_msg "$user" "$domain" "Rollback OK: ${target}"
	release_lock "$user" "$domain"
	return 0
}
