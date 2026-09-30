# Deploy-time install script

Prefer a **script in the repository** over a long one-liner in the Hestia panel.

`INSTALL_CMD` is a shell command that runs in `git-src/` after fetch and before the release is copied. The simplest, maintainable form is:

```text
INSTALL_CMD = bash .hestia-install.sh
```

(or `bash scripts/deploy.sh`, `make deploy`, etc.)

Everything else — Composer, npm, env wiring, shared storage, seed data — lives in git, versioned with the app, reviewable in PRs.

---

## Why use a script

| One-liner in the panel | Script in the repo |
|---|---|
| Hard to edit / escape quotes | Normal bash in git |
| Not reviewed with code changes | Same PR as app changes |
| Easy to diverge per server | Same path on every environment |
| Secrets mixed into UI command | Script reads `secrets.env` via the environment |

The panel setting stays short and stable; the script can grow.

---

## How it works

1. Deploy engine clones/fetches into `git-src/`
2. Exports variables from `git-deploy/secrets.env` into the environment
3. Runs `INSTALL_CMD` with cwd = `git-src/` (as the site user, under `TIMEOUT_SECONDS`)
4. Copies `OUTPUT_DIR` into a new release → `public_html`

So your script can use `$SESSION_SECRET`, `$NPM_TOKEN`, etc. without hardcoding them.

---

## Panel / CLI settings

```bash
sudo v-plugin-git-set alice example.com \
  INSTALL_CMD='bash .hestia-install.sh' \
  OUTPUT_DIR=. \
  EXCLUDE='.git,.env,.env.*,node_modules,tests,.github' \
  TIMEOUT_SECONDS=600
```

UI: set **Install command** to `bash .hestia-install.sh` (or your path).

Commit the script at the repo root (or under `scripts/`) and make it executable in git if you like (`chmod +x`); `bash script.sh` does not require the executable bit.

---

## Minimal template

Save as `.hestia-install.sh` in the project root:

```bash
#!/usr/bin/env bash
# Hestia git-deploy entrypoint.
# Panel:  INSTALL_CMD='bash .hestia-install.sh'
#         OUTPUT_DIR=…   EXCLUDE=…
set -euo pipefail

# --- build / install ---
# composer install --no-dev --optimize-autoloader
# npm ci && npm run build

# --- durable data beside public_html (survives rsync --delete) ---
# SITE_ROOT="$(cd .. && pwd)"   # …/web/<domain>  (git-src's parent)
# RUNTIME="${APP_RUNTIME:-${SITE_ROOT}/app-runtime}"
# mkdir -p "${RUNTIME}/data"

# --- env file for the release (avoid name .env* if EXCLUDE strips it) ---
# cp -f "${RUNTIME}/dotenv" ./dotenv

echo "Install OK"
```

---

## Patterns the script should own

### 1. Dependency install + build

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

### 2. Runtime directory outside the release

`public_html` is wiped/replaced each deploy. Keep uploads, user DBs, and durable config next to the site root:

```bash
SITE_ROOT="$(cd .. && pwd)"          # /home/<user>/web/<domain>
RUNTIME="${MYAPP_RUNTIME:-${SITE_ROOT}/myapp-runtime}"
mkdir -p "${RUNTIME}/data" "${RUNTIME}/uploads"
```

`git-src` is `…/web/<domain>/git-src`, so `..` is the domain root — stable across releases.

### 3. Env / secrets for PHP (and EXCLUDE)

Default `EXCLUDE` drops `.env` and `.env.*` from the release. Options:

- Write a file **not** matching `.env*` (e.g. `dotenv`) and teach the app to load it, or
- Narrow `EXCLUDE` so a generated `.env` is kept (only if you never commit secrets)

Example: generate once from `secrets.env`, reuse on later deploys:

```bash
RUNTIME_ENV="${RUNTIME}/dotenv"
if [[ ! -f "${RUNTIME_ENV}" || "${FORCE_ENV_REFRESH:-}" == "1" ]]; then
  : "${SESSION_SECRET:?set SESSION_SECRET in git-deploy secrets.env}"
  umask 077
  cat >"${RUNTIME_ENV}" <<EOF
SESSION_SECRET=${SESSION_SECRET}
APP_ENV=production
EOF
  chmod 600 "${RUNTIME_ENV}"
fi
cp -f "${RUNTIME_ENV}" ./dotenv
chmod 600 ./dotenv
```

Put `SESSION_SECRET=…` in the panel **secrets** / `secrets.env` (build-only; not in git).

### 4. One-time seed / migrate

```bash
if [[ "${SEED_CONTENT:-}" == "yes" && ! -e "${CONTENT_ROOT}/index.md" ]]; then
  php bin/migrate-content.php seed
fi
```

Gate with a secret/flag so production re-deploys do not wipe data.

### 5. Framework optimize

```bash
php artisan config:cache
php artisan route:cache
```

---

## Node-only example

`.hestia-install.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
npm ci
npm run build
echo "Static build OK → dist/"
```

Panel:

```text
INSTALL_CMD = bash .hestia-install.sh
OUTPUT_DIR  = dist
```

---

## PHP + shared runtime example (sketch)

```bash
#!/usr/bin/env bash
set -euo pipefail

composer install --no-dev --optimize-autoloader

SITE_ROOT="$(cd .. && pwd)"
RUNTIME="${APP_RUNTIME:-${SITE_ROOT}/app-runtime}"
mkdir -p "${RUNTIME}/storage"

# Persist Laravel-style storage across deploys
rm -rf storage
ln -sfn "${RUNTIME}/storage" storage
mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache

if [[ ! -f "${RUNTIME}/dotenv" ]]; then
  : "${APP_KEY:?set APP_KEY in secrets.env}"
  printf 'APP_KEY=%s\nAPP_ENV=production\n' "$APP_KEY" >"${RUNTIME}/dotenv"
  chmod 600 "${RUNTIME}/dotenv"
fi
cp -f "${RUNTIME}/dotenv" ./dotenv

php artisan config:cache || true
```

Panel: `INSTALL_CMD='bash .hestia-install.sh'`, `OUTPUT_DIR=.`, and set Hestia document root to `public` when the app uses a `public/` front controller.

---

## Tips

- Start the script with `set -euo pipefail` so a failed step fails the deploy (live site stays on the previous release).
- Log clearly (`echo "…"`) — output goes to `git-deploy/deploy.log`.
- Do not embed production secrets in the script; read them from the environment (`secrets.env`).
- Keep `INSTALL_CMD` itself boring: only invoke the script (plus maybe `bash scripts/hestia-install.sh`).
- Raise `TIMEOUT_SECONDS` if Composer/npm regularly exceed 5 minutes.
- Test locally: `cd` into a clone, export dummy secrets, run `bash .hestia-install.sh`.

---

## Related

- [Usage guide](./USAGE.md) — `INSTALL_CMD`, `secrets.env`, release flow
- [PHP application](./scenarios/php-app.md)
- [Node.js static](./scenarios/nodejs-static.md)
- [PHP + frontend](./scenarios/php-with-frontend.md)
