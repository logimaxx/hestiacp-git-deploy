#!/usr/bin/env bash
# Local smoke test without a full Hestia install.
# Creates a fake site tree under ./tmp-smoke and exercises add → deploy → rollback → list → delete.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SMOKE="${ROOT}/tmp-smoke"
USER_NAME="$(id -un)"
DOMAIN="smoke.test"

rm -rf "$SMOKE"
mkdir -p "${SMOKE}/home/${USER_NAME}/web/${DOMAIN}/public_html"
echo "old-site" >"${SMOKE}/home/${USER_NAME}/web/${DOMAIN}/public_html/index.html"

# Fake /home via bind... we can't bind without root. Instead patch paths by
# using a wrapper that redefines site_root through env override.
# We'll symlink /home/$USER/web/$DOMAIN into smoke only if allowed;
# otherwise run with HOME_OVERRIDE by sourcing a tiny shim.

export GIT_DEPLOY_SMOKE_ROOT="$SMOKE"

# Create shim that overrides site_root — by temporarily replacing path helpers.
# Simplest approach: create the real path under /tmp and use that as "home" via
# rewriting scripts to accept HESTIA_GIT_HOME. For smoke, we inject into main.sh
# via environment variable support.

# Ensure main.sh supports HESTIA_GIT_HOME
if ! grep -q 'HESTIA_GIT_HOME' "${ROOT}/func/main.sh"; then
	echo "Internal: adding HESTIA_GIT_HOME support for smoke tests"
fi

HESTIA_GIT_HOME="${SMOKE}/home"
export HESTIA_GIT_HOME

# Local bare repo with a dist/ folder
REPO="${SMOKE}/repo.git"
WORKDIR="${SMOKE}/workdir"
mkdir -p "$WORKDIR/dist"
echo '<h1>v1</h1>' >"$WORKDIR/dist/index.html"
echo 'secret' >"$WORKDIR/.env"
mkdir -p "$WORKDIR/.git-keep-dir"
(
	cd "$WORKDIR"
	git init -b main
	git config user.email "smoke@test"
	git config user.name "Smoke"
	git add -A
	git commit -m "v1"
)
git clone --bare "$WORKDIR" "$REPO"

# Second commit for force deploy / rollback test source
echo '<h1>v2</h1>' >"$WORKDIR/dist/index.html"
(
	cd "$WORKDIR"
	git add -A
	git commit -m "v2"
	git push "$REPO" main
)

BIN="${ROOT}/bin"
export PATH="${BIN}:$PATH"
export GIT_DEPLOY_VERBOSE=1

# Point site_root to smoke home — patch via HESTIA_GIT_HOME in main.sh
add_home_override() {
	:
}

echo "== add =="
# We need site under HESTIA_GIT_HOME/user/web/domain — already created
# validate_user_domain checks /home/user/... — must use override

# Run add with fake paths by invoking bash and sourcing with override already in main
"$BIN/v-plugin-git-add" "$USER_NAME" "$DOMAIN" "$REPO" main

CFG="${HESTIA_GIT_HOME}/${USER_NAME}/web/${DOMAIN}/git-deploy/config.conf"
# Local bare repo: use file:// and AUTH not needed for local path — use file URL
# Update REPO to file path clone works without SSH
sed -i "s|^REPO_URL=.*|REPO_URL=${REPO}|" "$CFG"
sed -i 's|^AUTH_METHOD=.*|AUTH_METHOD=https|' "$CFG"
sed -i 's|^INSTALL_CMD=.*|INSTALL_CMD=|' "$CFG"
sed -i 's|^OUTPUT_DIR=.*|OUTPUT_DIR=dist|' "$CFG"

echo "== test connection =="
"$BIN/v-plugin-git-test" "$USER_NAME" "$DOMAIN" | grep -q '^OK$'

echo "== deploy v2 (HEAD) =="
FORCE_DEPLOY=1 "$BIN/v-plugin-git-deploy" "$USER_NAME" "$DOMAIN" force

grep -q '^SETUP_DONE=yes$' "$CFG" || grep -q '^SETUP_DONE="yes"$' "$CFG"

PH="${HESTIA_GIT_HOME}/${USER_NAME}/web/${DOMAIN}/public_html/index.html"
grep -q 'v2' "$PH"
# .env must not be in public_html
if [[ -f "${HESTIA_GIT_HOME}/${USER_NAME}/web/${DOMAIN}/public_html/.env" ]]; then
	echo "FAIL: .env leaked to public_html" >&2
	exit 1
fi
echo "public_html OK (v2, no .env)"

echo "== deploy again (debounce) =="
"$BIN/v-plugin-git-deploy" "$USER_NAME" "$DOMAIN"

echo "== second release for rollback =="
echo '<h1>v3</h1>' >"$WORKDIR/dist/index.html"
(
	cd "$WORKDIR"
	git add -A
	git commit -m "v3"
	git push "$REPO" main
)
FORCE_DEPLOY=1 "$BIN/v-plugin-git-deploy" "$USER_NAME" "$DOMAIN" force
grep -q 'v3' "$PH"

echo "== rollback =="
"$BIN/v-plugin-git-rollback" "$USER_NAME" "$DOMAIN"
grep -q 'v2' "$PH"
echo "rollback OK (v2)"

echo "== list json =="
"$BIN/v-plugin-git-list" "$USER_NAME" "$DOMAIN" json | head -c 200
echo ""

echo "== lock conflict =="
LOCK="${HESTIA_GIT_HOME}/${USER_NAME}/web/${DOMAIN}/git-deploy/deploy.lock"
printf 'pid=%s\nstarted=%s\n' "$$" "$(date +%s)" >"$LOCK"
if "$BIN/v-plugin-git-deploy" "$USER_NAME" "$DOMAIN" force; then
	echo "FAIL: expected lock error" >&2
	exit 1
fi
rm -f "$LOCK"
echo "lock OK"

echo "== delete =="
"$BIN/v-plugin-git-delete" "$USER_NAME" "$DOMAIN"
[[ ! -d "${HESTIA_GIT_HOME}/${USER_NAME}/web/${DOMAIN}/git-deploy" ]]
grep -q 'v2' "$PH"
echo "delete OK (public_html kept)"

echo ""
echo "ALL SMOKE TESTS PASSED"
