#!/usr/bin/env bash
# Uninstall Git Deploy plugin (does not remove per-site git-deploy dirs).

set -euo pipefail

if [[ $(id -u) -ne 0 ]]; then
	echo "Error: run as root" >&2
	exit 1
fi

HESTIA="${HESTIA:-/usr/local/hestia}"
PLUGIN_DST="${HESTIA}/plugins/git-deploy"
BIN_DST="${HESTIA}/bin"
UI_DST="${HESTIA}/web/edit/web/git-deploy"
UI_SHORT="${HESTIA}/web/git-deploy"
TPL_DST="${HESTIA}/web/templates/pages/git_deploy.php"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for name in v-plugin-git-add v-plugin-git-deploy v-plugin-git-rollback \
	v-plugin-git-delete v-plugin-git-list v-plugin-git-key-generate \
	v-plugin-git-set v-plugin-git-secret-regenerate v-plugin-git-secrets-write \
	v-plugin-git-test; do
	if [[ -L "${BIN_DST}/${name}" || -f "${BIN_DST}/${name}" ]]; then
		rm -f "${BIN_DST}/${name}"
		echo "removed ${BIN_DST}/${name}"
	fi
done

rm -f "$TPL_DST"
rm -rf "$UI_DST" "$UI_SHORT"
rm -f /etc/sudoers.d/hestia-git-deploy

# Undo UI + CSRF patches before removing plugin files
if [[ -f "${PLUGIN_DST}/hooks/unpatch-ui-links.sh" ]]; then
	bash "${PLUGIN_DST}/hooks/unpatch-ui-links.sh" || true
elif [[ -f "${SRC}/hooks/unpatch-ui-links.sh" ]]; then
	bash "${SRC}/hooks/unpatch-ui-links.sh" || true
fi

if [[ -f "${PLUGIN_DST}/hooks/unpatch-csrf-whitelist.sh" ]]; then
	bash "${PLUGIN_DST}/hooks/unpatch-csrf-whitelist.sh" || true
elif [[ -f "${SRC}/hooks/unpatch-csrf-whitelist.sh" ]]; then
	bash "${SRC}/hooks/unpatch-csrf-whitelist.sh" || true
fi

if [[ -d "$PLUGIN_DST" ]]; then
	rm -rf "$PLUGIN_DST"
	echo "removed ${PLUGIN_DST}"
fi

echo "OK — per-site /home/*/web/*/git-deploy directories were left intact"
