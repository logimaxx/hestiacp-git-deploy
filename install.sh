#!/usr/bin/env bash
# Install hestia-cp-git-deploy plugin into a HestiaCP host.
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
cp -a "$SRC/bin" "$SRC/func" "$SRC/webhook" "$SRC/hooks" "$SRC/spec.md" "$PLUGIN_DST/"
if [[ -f "$SRC/README.md" ]]; then
	cp -a "$SRC/README.md" "$PLUGIN_DST/"
fi
if [[ -f "$SRC/plugin.json" ]]; then
	cp -a "$SRC/plugin.json" "$PLUGIN_DST/"
fi

chmod 755 "$PLUGIN_DST"/bin/v-plugin-git-*
chmod 755 "$PLUGIN_DST"/hooks/*.sh
chmod 644 "$PLUGIN_DST"/func/*.sh
chmod 644 "$PLUGIN_DST"/webhook/*.php

# Symlink CLI into Hestia bin (v-plugin-* prefix)
mkdir -p "$BIN_DST"
for cmd in "$PLUGIN_DST"/bin/v-plugin-git-*; do
	name="$(basename "$cmd")"
	ln -sfn "$cmd" "${BIN_DST}/${name}"
	echo "  linked ${BIN_DST}/${name}"
done

echo ""
echo "Installed. Commands:"
echo "  v-plugin-git-add <user> <domain> <repo_url> [branch]"
echo "  v-plugin-git-deploy <user> <domain> [force]"
echo "  v-plugin-git-rollback <user> <domain> [release_id]"
echo "  v-plugin-git-list <user> [domain] [json|shell]"
echo "  v-plugin-git-delete <user> <domain>"
echo "  v-plugin-git-key-generate <user> <domain>"
echo ""
echo "Webhook script: ${PLUGIN_DST}/webhook/listener.php"
echo "Domain cleanup hook: ${PLUGIN_DST}/hooks/cleanup-domain.sh"
echo "OK"
