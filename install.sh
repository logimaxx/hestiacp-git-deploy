#!/usr/bin/env bash
# Install Git Deploy plugin into a HestiaCP host.
# Run as root.

set -euo pipefail

if [[ $(id -u) -ne 0 ]]; then
	echo "Error: run as root" >&2
	exit 1
fi

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HESTIA="${HESTIA:-/usr/local/hestia}"
PLUGIN_DST="${HESTIA}/plugins/git-deploy"
BIN_DST="${HESTIA}/bin"
WEB_DST="${HESTIA}/web/git-deploy"
TPL_DST="${HESTIA}/web/templates/pages/git_deploy.php"

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

# Native UI under panel web root
rm -rf "$WEB_DST"
ln -sfn "$PLUGIN_DST/web/git-deploy" "$WEB_DST"
ln -sfn "$PLUGIN_DST/web/templates/pages/git_deploy.php" "$TPL_DST"
# lib is referenced as ../lib from git-deploy/ — ensure sibling exists
mkdir -p "${HESTIA}/web"
# When WEB_DST is symlink to plugin/web/git-deploy, ../lib resolves to plugin/web/lib — OK
echo "  linked ${WEB_DST}"
echo "  linked ${TPL_DST}"

# Allow hestia-php/www-data to sudo the plugin commands if sudoers snippet missing
SUDOERS="/etc/sudoers.d/hestia-git-deploy"
if [[ ! -f "$SUDOERS" ]] && [[ -d /etc/sudoers.d ]]; then
	cat >"$SUDOERS" <<EOF
# Allow Hestia panel to invoke Git Deploy CLI
Defaults:admin !requiretty
Cmnd_Alias HESTIA_GIT_DEPLOY = ${BIN_DST}/v-plugin-git-add, ${BIN_DST}/v-plugin-git-deploy, ${BIN_DST}/v-plugin-git-rollback, ${BIN_DST}/v-plugin-git-delete, ${BIN_DST}/v-plugin-git-list, ${BIN_DST}/v-plugin-git-key-generate, ${BIN_DST}/v-plugin-git-set, ${BIN_DST}/v-plugin-git-secret-regenerate, ${BIN_DST}/v-plugin-git-secrets-write
# Hestia already uses sudo for bin/*; this documents the plugin commands.
EOF
	chmod 440 "$SUDOERS"
	echo "  wrote ${SUDOERS} (informational; Hestia sudoers usually covers /usr/local/hestia/bin/*)"
fi

echo ""
echo "Installed Git Deploy."
echo ""
echo "UI:  https://<panel>/git-deploy/?domain=example.com"
echo "CLI: v-plugin-git-add|deploy|rollback|list|delete|key-generate|set|secret-regenerate"
echo ""
echo "Optional: install hestiacp-pluginable for Edit/List Web buttons."
echo "Webhook: ${WEB_DST}/webhook.php?user=USER&domain=DOMAIN"
echo "OK"
