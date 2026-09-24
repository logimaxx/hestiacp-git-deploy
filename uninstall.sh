#!/usr/bin/env bash
# Uninstall hestia-cp-git-deploy plugin (does not remove per-site git-deploy dirs).

set -euo pipefail

if [[ $(id -u) -ne 0 ]]; then
	echo "Error: run as root" >&2
	exit 1
fi

HESTIA="${HESTIA:-/usr/local/hestia}"
PLUGIN_DST="${HESTIA}/plugins/git-deploy"
BIN_DST="${HESTIA}/bin"

for name in v-plugin-git-add v-plugin-git-deploy v-plugin-git-rollback \
	v-plugin-git-delete v-plugin-git-list v-plugin-git-key-generate; do
	if [[ -L "${BIN_DST}/${name}" || -f "${BIN_DST}/${name}" ]]; then
		rm -f "${BIN_DST}/${name}"
		echo "removed ${BIN_DST}/${name}"
	fi
done

if [[ -d "$PLUGIN_DST" ]]; then
	rm -rf "$PLUGIN_DST"
	echo "removed ${PLUGIN_DST}"
fi

echo "OK — per-site /home/*/web/*/git-deploy directories were left intact"
