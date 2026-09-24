#!/usr/bin/env bash
# Shared helpers for hestia-cp-git-deploy plugin.
# shellcheck disable=SC2034

set -euo pipefail

PLUGIN_NAME="git-deploy"
PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ---------------------------------------------------------------------------
# Hestia bootstrap (optional — scripts still work with minimal fallbacks)
# ---------------------------------------------------------------------------
hestia_bootstrap() {
	if [[ -f /etc/hestiacp/hestia.conf ]]; then
		# shellcheck disable=SC1091
		source /etc/hestiacp/hestia.conf
	fi
	if [[ -f /usr/local/hestia/func/main.sh ]]; then
		# shellcheck disable=SC1091
		source /usr/local/hestia/func/main.sh
	fi
	HESTIA="${HESTIA:-/usr/local/hestia}"
	BIN="${BIN:-$HESTIA/bin}"
}

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
site_root() {
	local user="$1" domain="$2"
	local home_root="${HESTIA_GIT_HOME:-/home}"
	echo "${home_root}/${user}/web/${domain}"
}

git_src_dir() {
	echo "$(site_root "$1" "$2")/git-src"
}

git_deploy_dir() {
	echo "$(site_root "$1" "$2")/git-deploy"
}

public_html_dir() {
	echo "$(site_root "$1" "$2")/public_html"
}

config_path() { echo "$(git_deploy_dir "$1" "$2")/config.conf"; }
secrets_path() { echo "$(git_deploy_dir "$1" "$2")/secrets.env"; }
status_path() { echo "$(git_deploy_dir "$1" "$2")/status.json"; }
log_path() { echo "$(git_deploy_dir "$1" "$2")/deploy.log"; }
lock_path() { echo "$(git_deploy_dir "$1" "$2")/deploy.lock"; }
releases_dir() { echo "$(git_deploy_dir "$1" "$2")/releases"; }
current_link() { echo "$(git_deploy_dir "$1" "$2")/current"; }
deploy_key_path() { echo "$(git_deploy_dir "$1" "$2")/deploy_key"; }
deploy_key_pub_path() { echo "$(git_deploy_dir "$1" "$2")/deploy_key.pub"; }
known_hosts_path() { echo "$(git_deploy_dir "$1" "$2")/known_hosts"; }

# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------
require_root_or_owner() {
	local user="$1"
	if [[ $(id -u) -eq 0 ]]; then
		return 0
	fi
	if [[ "$(id -un)" == "$user" ]]; then
		return 0
	fi
	echo "Error: must run as root or as user '$user'" >&2
	exit 1
}

validate_user_domain() {
	local user="$1" domain="$2"
	if [[ ! "$user" =~ ^[a-zA-Z0-9._-]+$ ]]; then
		echo "Error: invalid user name" >&2
		exit 1
	fi
	if [[ ! "$domain" =~ ^[a-zA-Z0-9._-]+$ ]]; then
		echo "Error: invalid domain name" >&2
		exit 1
	fi
	local root
	root="$(site_root "$user" "$domain")"
	if [[ ! -d "$root" ]]; then
		echo "Error: site root does not exist: $root" >&2
		exit 1
	fi
	if [[ ! -d "$(public_html_dir "$user" "$domain")" ]]; then
		echo "Error: public_html missing for $domain" >&2
		exit 1
	fi
	# Prefer Hestia object checks when available
	if declare -F is_object_valid >/dev/null 2>&1; then
		is_object_valid 'user' 'USER' "$user" || exit 1
		is_object_valid 'web' 'DOMAIN' "$domain" || exit 1
	fi
}

require_git_deploy() {
	local user="$1" domain="$2"
	if [[ ! -f "$(config_path "$user" "$domain")" ]]; then
		echo "Error: git deploy not configured for $domain (run v-plugin-git-add first)" >&2
		exit 1
	fi
}

# ---------------------------------------------------------------------------
# Config load / save
# ---------------------------------------------------------------------------
# shellcheck disable=SC1090
load_config() {
	local user="$1" domain="$2"
	local cfg
	cfg="$(config_path "$user" "$domain")"
	# Defaults
	REPO_URL=""
	BRANCH="main"
	AUTH_METHOD="ssh"
	INSTALL_CMD=""
	OUTPUT_DIR="dist"
	EXCLUDE=".git,.env,.env.*,node_modules"
	DEPLOY_KEY_PATH="$(deploy_key_path "$user" "$domain")"
	WEBHOOK_SECRET=""
	AUTO_DEPLOY="yes"
	TIMEOUT_SECONDS="300"
	MAX_RELEASES="5"
	HEALTHCHECK_URL=""
	HEALTHCHECK_EXPECT="200"
	GIT_SUBMODULES="no"
	LAST_DEPLOYED_COMMIT=""
	# shellcheck source=/dev/null
	source "$cfg"
}

set_config_value() {
	local cfg="$1" key="$2" value="$3"
	if grep -q "^${key}=" "$cfg" 2>/dev/null; then
		# Escape for sed; use | delimiter
		local esc
		esc="$(printf '%s' "$value" | sed -e 's/[\\&|]/\\&/g')"
		sed -i "s|^${key}=.*|${key}=${esc}|" "$cfg"
	else
		printf '%s=%s\n' "$key" "$value" >>"$cfg"
	fi
}

# ---------------------------------------------------------------------------
# Run as site user
# ---------------------------------------------------------------------------
run_as_user() {
	local user="$1"
	shift
	if [[ $(id -u) -eq 0 ]]; then
		if command -v runuser >/dev/null 2>&1; then
			runuser -u "$user" -- "$@"
		else
			su -s /bin/bash "$user" -c "$(printf '%q ' "$@")"
		fi
	else
		"$@"
	fi
}

# Run a bash -c snippet as user (for complex pipelines)
run_as_user_bash() {
	local user="$1"
	local script="$2"
	if [[ $(id -u) -eq 0 ]]; then
		if command -v runuser >/dev/null 2>&1; then
			runuser -u "$user" -- /bin/bash -c "$script"
		else
			su -s /bin/bash "$user" -c "$script"
		fi
	else
		/bin/bash -c "$script"
	fi
}

chown_site() {
	local user="$1"
	shift
	if [[ $(id -u) -eq 0 ]]; then
		chown -R "${user}:${user}" "$@"
	fi
}

# ---------------------------------------------------------------------------
# Logging (sanitized)
# ---------------------------------------------------------------------------
sanitize_line() {
	local line="$1"
	# Redact common secret patterns
	line="$(printf '%s' "$line" | sed -E \
		-e 's/(WEBHOOK_SECRET=)[^[:space:]]+/\1***REDACTED***/g' \
		-e 's/(GIT_TOKEN=)[^[:space:]]+/\1***REDACTED***/g' \
		-e 's/(token[=:])[^[:space:]]+/\1***REDACTED***/gi' \
		-e 's/(Authorization:[[:space:]]*Bearer[[:space:]]+)[^[:space:]]+/\1***REDACTED***/gi' \
		-e 's/(x-access-token:)[^@[:space:]]+/\1***REDACTED***/gi')"
	printf '%s' "$line"
}

log_msg() {
	local user="$1" domain="$2"
	shift 2
	local logfile msg ts
	logfile="$(log_path "$user" "$domain")"
	ts="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
	msg="$(sanitize_line "$*")"
	mkdir -p "$(dirname "$logfile")"
	printf '[%s] %s\n' "$ts" "$msg" | tee -a "$logfile" >/dev/null
	# Also echo to stderr for CLI visibility when interactive
	if [[ -t 2 ]] || [[ "${GIT_DEPLOY_VERBOSE:-}" == "1" ]]; then
		printf '[%s] %s\n' "$ts" "$msg" >&2
	fi
}

# ---------------------------------------------------------------------------
# status.json
# ---------------------------------------------------------------------------
json_escape() {
	printf '%s' "$1" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()), end="")' 2>/dev/null \
		|| printf '"%s"' "${1//\"/\\\"}"
}

write_status() {
	local user="$1" domain="$2"
	local state="$3" last_status="$4" last_commit="$5" last_release="$6"
	local started_at="$7" finished_at="$8" duration="$9" message="${10:-}"
	local sp
	sp="$(status_path "$user" "$domain")"
	cat >"$sp" <<EOF
{
  "state": $(json_escape "$state"),
  "last_status": $(json_escape "$last_status"),
  "last_commit": $(json_escape "$last_commit"),
  "last_release": $(json_escape "$last_release"),
  "started_at": $( [[ -n "$started_at" ]] && json_escape "$started_at" || echo null ),
  "finished_at": $( [[ -n "$finished_at" ]] && json_escape "$finished_at" || echo null ),
  "duration_seconds": ${duration:-0},
  "message": $(json_escape "$message")
}
EOF
	chown_site "$user" "$sp"
}

read_status_field() {
	local user="$1" domain="$2" field="$3"
	local sp
	sp="$(status_path "$user" "$domain")"
	[[ -f "$sp" ]] || { echo ""; return; }
	python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d.get(sys.argv[2],'') or '')" "$sp" "$field" 2>/dev/null \
		|| true
}

# ---------------------------------------------------------------------------
# Lock
# ---------------------------------------------------------------------------
acquire_lock() {
	local user="$1" domain="$2"
	local timeout="${3:-300}"
	local lp now age pid
	lp="$(lock_path "$user" "$domain")"

	if [[ -f "$lp" ]]; then
		# shellcheck disable=SC1090
		# lock file: pid=... started=...
		# shellcheck source=/dev/null
		source "$lp" 2>/dev/null || true
		pid="${pid:-}"
		started="${started:-0}"
		now="$(date +%s)"
		age=$((now - started))
		if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
			if (( age < timeout + 60 )); then
				echo "Error: deploy already running (pid $pid, age ${age}s)" >&2
				return 1
			fi
			log_msg "$user" "$domain" "WARN: reclaiming stale lock (pid $pid still alive but age ${age}s > timeout)"
		elif (( age < timeout + 60 )) && [[ -n "$pid" ]]; then
			# process gone but lock fresh-ish — reclaim
			log_msg "$user" "$domain" "WARN: reclaiming lock from dead pid $pid"
		elif (( age >= timeout + 60 )); then
			log_msg "$user" "$domain" "WARN: reclaiming stale lock (age ${age}s)"
		fi
		rm -f "$lp"
	fi

	printf 'pid=%s\nstarted=%s\n' "$$" "$(date +%s)" >"$lp"
	chown_site "$user" "$lp"
	return 0
}

release_lock() {
	local user="$1" domain="$2"
	rm -f "$(lock_path "$user" "$domain")"
}

# ---------------------------------------------------------------------------
# SSH / known_hosts / keys
# ---------------------------------------------------------------------------
generate_deploy_key() {
	local user="$1" domain="$2"
	local key pub comment
	key="$(deploy_key_path "$user" "$domain")"
	pub="$(deploy_key_pub_path "$user" "$domain")"
	comment="hestia-git-deploy:${user}@${domain}"
	rm -f "$key" "$pub"
	ssh-keygen -t ed25519 -N "" -C "$comment" -f "$key" >/dev/null
	chmod 600 "$key"
	chmod 644 "$pub"
	chown_site "$user" "$key" "$pub"
}

update_known_hosts() {
	local user="$1" domain="$2"
	local kh hosts host
	kh="$(known_hosts_path "$user" "$domain")"
	: >"$kh"
	hosts=("github.com" "gitlab.com" "bitbucket.org")
	for host in "${hosts[@]}"; do
		if command -v ssh-keyscan >/dev/null 2>&1; then
			ssh-keyscan -t rsa,ecdsa,ed25519 "$host" >>"$kh" 2>/dev/null || true
		fi
	done
	chmod 644 "$kh"
	chown_site "$user" "$kh"
}

git_ssh_command() {
	local user="$1" domain="$2"
	local key kh
	key="$(deploy_key_path "$user" "$domain")"
	kh="$(known_hosts_path "$user" "$domain")"
	printf 'ssh -i %q -o IdentitiesOnly=yes -o UserKnownHostsFile=%q -o StrictHostKeyChecking=yes' "$key" "$kh"
}

# Build GIT_ASKPASS helper for HTTPS token auth
ensure_askpass_helper() {
	local user="$1" domain="$2"
	local helper secrets
	helper="$(git_deploy_dir "$user" "$domain")/git-askpass.sh"
	secrets="$(secrets_path "$user" "$domain")"
	cat >"$helper" <<'ASKPASS'
#!/usr/bin/env bash
# Reads GIT_TOKEN from secrets.env sibling
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck disable=SC1091
set -a
source "$DIR/secrets.env" 2>/dev/null || true
set +a
case "$1" in
	*Username*) echo "${GIT_USERNAME:-git}" ;;
	*Password*) echo "${GIT_TOKEN:-}" ;;
	*) echo "${GIT_TOKEN:-}" ;;
esac
ASKPASS
	chmod 700 "$helper"
	chown_site "$user" "$helper"
	# ensure secrets exists
	if [[ ! -f "$secrets" ]]; then
		printf '# Build-only secrets (never deployed to webroot)\n# GIT_TOKEN=\n# GIT_USERNAME=git\n' >"$secrets"
		chmod 600 "$secrets"
		chown_site "$user" "$secrets"
	fi
	echo "$helper"
}

# ---------------------------------------------------------------------------
# Path validation for OUTPUT_DIR
# ---------------------------------------------------------------------------
resolve_output_dir() {
	local git_src="$1" output_dir="$2"
	local resolved
	# Normalize empty to .
	[[ -n "$output_dir" ]] || output_dir="."
	if [[ "$output_dir" == "." ]]; then
		resolved="$(cd "$git_src" && pwd -P)"
	else
		if [[ "$output_dir" = /* ]]; then
			echo "Error: OUTPUT_DIR must be relative to git-src" >&2
			return 1
		fi
		if [[ ! -d "$git_src/$output_dir" ]]; then
			echo "Error: OUTPUT_DIR does not exist: $output_dir" >&2
			return 1
		fi
		resolved="$(cd "$git_src/$output_dir" && pwd -P)"
	fi
	local src_real
	src_real="$(cd "$git_src" && pwd -P)"
	case "$resolved" in
		"$src_real"|"$src_real"/*) echo "$resolved"; return 0 ;;
		*)
			echo "Error: OUTPUT_DIR escapes git-src (path traversal blocked)" >&2
			return 1
			;;
	esac
}

# Fill RSYNC_EXCLUDES array from comma-separated EXCLUDE
# Always enforces minimum: .git, .env, .env.*
build_rsync_excludes() {
	local exclude="${1:-.git,.env,.env.*,node_modules}"
	local IFS=',' part
	RSYNC_EXCLUDES=(--exclude='.git' --exclude='.env' --exclude='.env.*')
	for part in $exclude; do
		part="${part#"${part%%[![:space:]]*}"}"
		part="${part%"${part##*[![:space:]]}"}"
		[[ -n "$part" ]] || continue
		case "$part" in
			.git | .env | .env.*) continue ;;
		esac
		RSYNC_EXCLUDES+=(--exclude="$part")
	done
}
