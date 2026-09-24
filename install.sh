#!/usr/bin/env bash
# Install Git Deploy plugin into a HestiaCP host.
# Run as root on the panel server.

set -euo pipefail

if [[ $(id -u) -ne 0 ]]; then
	echo "Error: run as root" >&2
	exit 1
fi

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HESTIA="${HESTIA:-/usr/local/hestia}"
PLUGIN_DST="${HESTIA}/plugins/git-deploy"
BIN_DST="${HESTIA}/bin"
# Primary UI path (matches Hestia /edit/web/... layout)
UI_DST="${HESTIA}/web/edit/web/git-deploy"
# Legacy/short path + webhook
UI_SHORT="${HESTIA}/web/git-deploy"
TPL_DST="${HESTIA}/web/templates/pages/git_deploy.php"

if [[ ! -d "${HESTIA}/web" ]]; then
	echo "Error: ${HESTIA}/web not found — is HestiaCP installed on this host?" >&2
	exit 1
fi

for dep in git rsync ssh-keygen openssl; do
	if ! command -v "$dep" >/dev/null 2>&1; then
		echo "Error: missing dependency: $dep" >&2
		exit 1
	fi
done

echo "Installing git-deploy plugin → ${PLUGIN_DST}"
mkdir -p "${HESTIA}/plugins"
rm -rf "$PLUGIN_DST"
mkdir -p "$PLUGIN_DST"

cp -a "$SRC/bin" "$SRC/func" "$SRC/webhook" "$SRC/hooks" "$SRC/web" "$SRC/pages" "$PLUGIN_DST/"
cp -a "$SRC/spec.md" "$SRC/plugin.json" "$SRC/plugin.php" "$SRC/git-deploy-plugin.php" "$PLUGIN_DST/"
[[ -f "$SRC/README.md" ]] && cp -a "$SRC/README.md" "$PLUGIN_DST/"

chmod 755 "$PLUGIN_DST"/bin/v-plugin-git-*
chmod 755 "$PLUGIN_DST"/hooks/*.sh
chmod 644 "$PLUGIN_DST"/func/*.sh
chmod 644 "$PLUGIN_DST"/webhook/*.php
chmod 644 "$PLUGIN_DST"/web/templates/pages/*.php
chmod 644 "$PLUGIN_DST"/web/lib/*.php
chmod 644 "$PLUGIN_DST"/web/git-deploy/*.php
chmod 644 "$PLUGIN_DST"/pages/*.php
chmod 644 "$PLUGIN_DST"/plugin.php "$PLUGIN_DST"/git-deploy-plugin.php

# Symlink CLI into Hestia bin
mkdir -p "$BIN_DST"
for cmd in "$PLUGIN_DST"/bin/v-plugin-git-*; do
	name="$(basename "$cmd")"
	ln -sfn "$cmd" "${BIN_DST}/${name}"
	echo "  linked ${BIN_DST}/${name}"
done

# --- Panel UI: real files under Hestia web root (symlinks to outside web/ often 404) ---
install_ui_wrapper() {
	local dest_dir="$1"
	mkdir -p "$dest_dir"
	# index.php — load plugin controller
	cat >"${dest_dir}/index.php" <<EOF
<?php
/**
 * Git Deploy UI bootstrap (installed by install.sh)
 * Do not edit — reinstall overwrites this file.
 */
define("GIT_DEPLOY_PLUGIN_ROOT", "${PLUGIN_DST}");
require GIT_DEPLOY_PLUGIN_ROOT . "/web/git-deploy/index.php";
EOF
	# webhook.php
	cat >"${dest_dir}/webhook.php" <<EOF
<?php
define("GIT_DEPLOY_PLUGIN_ROOT", "${PLUGIN_DST}");
require GIT_DEPLOY_PLUGIN_ROOT . "/web/git-deploy/webhook.php";
EOF
	chown -R hestiaweb:hestiaweb "$dest_dir" 2>/dev/null || chown -R www-data:www-data "$dest_dir" 2>/dev/null || true
	chmod 755 "$dest_dir"
	chmod 644 "${dest_dir}/index.php" "${dest_dir}/webhook.php"
	echo "  installed ${dest_dir}/index.php"
}

# Ensure parent exists (Hestia ships edit/web/)
mkdir -p "${HESTIA}/web/edit/web"
install_ui_wrapper "$UI_DST"
install_ui_wrapper "$UI_SHORT"

# Template must live where render_page() looks
mkdir -p "$(dirname "$TPL_DST")"
cp -f "$PLUGIN_DST/web/templates/pages/git_deploy.php" "$TPL_DST"
chown hestiaweb:hestiaweb "$TPL_DST" 2>/dev/null || chown www-data:www-data "$TPL_DST" 2>/dev/null || true
chmod 644 "$TPL_DST"
echo "  installed ${TPL_DST}"

# Allow direct GET to UI (Hestia CSRF blocks bookmark/no-Referer otherwise)
chmod 755 "$PLUGIN_DST"/hooks/*.sh
"$PLUGIN_DST/hooks/patch-csrf-whitelist.sh" || true

# Inject Git Deploy button into Edit Web + List Web (no Pluginable needed)
"$PLUGIN_DST/hooks/patch-ui-links.sh" || true

# Verify
if [[ ! -f "${UI_DST}/index.php" || ! -f "$TPL_DST" ]]; then
	echo "Error: UI install incomplete" >&2
	exit 1
fi
if [[ ! -f "${PLUGIN_DST}/web/git-deploy/index.php" ]]; then
	echo "Error: plugin controller missing" >&2
	exit 1
fi

echo ""
echo "Installed Git Deploy."
echo ""
echo "Open from the panel:"
echo "  Web → domain row → Git Deploy icon"
echo "  or Web → Edit domain → Git Deploy button"
echo ""
echo "Direct URL:"
echo "  https://<panel-host>:8083/edit/web/git-deploy/?domain=example.com"
echo ""
echo "Webhook:"
echo "  https://<panel-host>:8083/git-deploy/webhook.php?user=USER&domain=DOMAIN"
echo ""
echo "CLI: v-plugin-git-add|deploy|rollback|list|delete|set|..."
echo "OK"
