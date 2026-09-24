#!/usr/bin/env bash
# Restore CSRF whitelist entries added for Git Deploy.

set -euo pipefail

HESTIA="${HESTIA:-/usr/local/hestia}"
CSRF="${HESTIA}/web/inc/prevent_csrf.php"
BAK="${CSRF}.bak-git-deploy"

if [[ -f "$BAK" ]]; then
	# Only restore if our marker is present (avoid clobbering newer Hestia updates wrongly)
	if grep -q "git-deploy-csrf-whitelist" "$CSRF" 2>/dev/null; then
		# Prefer surgical removal over full restore (Hestia may have been updated)
		python3 - "$CSRF" <<'PY'
import pathlib, re, sys
path = pathlib.Path(sys.argv[1])
text = path.read_text()
text2 = re.sub(
    r'\n\s*# git-deploy-csrf-whitelist\n\s*"/edit/web/git-deploy/index\.php",\n\s*"/git-deploy/index\.php",',
    "",
    text,
    count=1,
)
path.write_text(text2)
print(f"Unpatched {path}")
PY
	fi
fi
