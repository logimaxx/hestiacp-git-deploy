#!/usr/bin/env bash
# Patch Hestia prevent_csrf.php to allow Git Deploy UI GET without Referer
# (same treatment as /list/web/index.php). Idempotent.

set -euo pipefail

HESTIA="${HESTIA:-/usr/local/hestia}"
CSRF="${HESTIA}/web/inc/prevent_csrf.php"
MARKER="git-deploy-csrf-whitelist"

if [[ ! -f "$CSRF" ]]; then
	echo "Warning: $CSRF not found — skip CSRF patch" >&2
	exit 0
fi

if grep -q "$MARKER" "$CSRF"; then
	echo "CSRF whitelist already patched"
	exit 0
fi

# Backup once
if [[ ! -f "${CSRF}.bak-git-deploy" ]]; then
	cp -a "$CSRF" "${CSRF}.bak-git-deploy"
fi

python3 - "$CSRF" <<'PY'
import pathlib, sys
path = pathlib.Path(sys.argv[1])
text = path.read_text()
needle = '"/reset/index.php",'
extra = '''"/reset/index.php",
					# git-deploy-csrf-whitelist
					"/edit/web/git-deploy/index.php",
					"/git-deploy/index.php",'''
if needle not in text:
    # fallback: insert before closing of in_array
    raise SystemExit(f"Could not find insertion point in {path}")
if "git-deploy-csrf-whitelist" in text:
    raise SystemExit(0)
text = text.replace(needle, extra, 1)
path.write_text(text)
print(f"Patched {path}")
PY
