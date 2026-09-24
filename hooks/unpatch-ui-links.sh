#!/usr/bin/env bash
# Remove Git Deploy links from Hestia templates.

set -euo pipefail

HESTIA="${HESTIA:-/usr/local/hestia}"

unpatch_file() {
	local f="$1"
	[[ -f "$f" ]] || return 0
	if ! grep -q "git-deploy-ui-link" "$f" 2>/dev/null; then
		return 0
	fi
	python3 - "$f" <<'PY'
import pathlib, re, sys
path = pathlib.Path(sys.argv[1])
text = path.read_text()

# edit_web: HTML comment + <a>...</a>
text = re.sub(
    r'\n?\s*<!-- git-deploy-ui-link -->\s*\n\s*<a href="/edit/web/git-deploy/\?[\s\S]*?</a>\s*',
    "\n",
    text,
    count=1,
)

# list_web: comment + <li>...</li>
text = re.sub(
    r'\n?\s*<!-- git-deploy-ui-link -->\s*\n\s*<li class="units-table-row-action"[\s\S]*?</li>\s*',
    "\n",
    text,
    count=1,
)

path.write_text(text)
print(f"Unpatched {path}")
PY
}

unpatch_file "${HESTIA}/web/templates/pages/edit_web.php"
unpatch_file "${HESTIA}/web/templates/pages/list_web.php"
echo "OK — Git Deploy UI links removed"
