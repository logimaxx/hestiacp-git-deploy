# Usage guide

This guide explains how Git Deploy works, how to configure it, and how to operate it day to day. For concrete recipes, see [scenarios](./scenarios/).

---

## Mental model

Each enabled domain gets three areas under `/home/<user>/web/<domain>/`:

```
public_html/     ← live site (Hestia document root; rsync’d from current release)
git-src/         ← private git working copy (never served)
git-deploy/      ← config, keys, secrets, releases, logs
```

A deploy does **not** sync `git-src` into the webroot. It:

1. Fetches the configured branch into `git-src/`
2. Runs your `INSTALL_CMD` there (optional)
3. Copies **only** `git-src/<OUTPUT_DIR>/` into a new release folder
4. Points `git-deploy/current` at that release and rsyncs it into `public_html/`

If install fails, the previous live site is left untouched.

```
Repo ──► git-src/ ──► INSTALL_CMD ──► OUTPUT_DIR ──► releases/<id>/ ──► public_html/
```

Two knobs control almost every scenario:

| Setting | Meaning |
|---|---|
| `INSTALL_CMD` | Shell command run in `git-src/` (build / composer / nothing) |
| `OUTPUT_DIR` | Relative folder whose contents become the live site |

---

## Prerequisites on the Hestia host

Always required:

- `git`, `rsync`, `ssh-keygen`, `openssl`, `curl`, `timeout`

Install what your sites need (must be on PATH for the **site user**, not only root):

```bash
# Node.js builds
# (use NodeSource, nvm system-wide, or distro packages)
node -v && npm -v

# PHP apps with Composer
composer --version
php -v
```

Verify as the site user:

```bash
sudo -u alice -H bash -lc 'which node npm composer php; node -v; composer -V'
```

If the site user cannot see `node`/`composer`, deploys will fail even when root works.

---

## First-time setup

### From the panel (recommended)

1. Install the plugin on the **panel host**: `sudo ./install.sh`
2. Open **Web → domain → Git Deploy** (or Edit domain → Git Deploy)
3. Enter SSH repo URL + branch → **Enable Git Deploy**
4. Follow the checklist:
   - Copy deploy public key → add as a **read-only Deploy Key** on GitHub/GitLab
   - **Test connection**
   - Set `INSTALL_CMD` / `OUTPUT_DIR` for your stack (see scenarios)
   - Add build secrets if needed
   - **Deploy now**

### From the CLI

```bash
# Enable (prints deploy public key + webhook secret once)
sudo v-plugin-git-add alice example.com git@github.com:org/site.git main

# Add the printed public key as a Deploy Key on GitHub/GitLab
sudo v-plugin-git-test alice example.com

# Configure for your stack (examples below)
sudo v-plugin-git-set alice example.com \
  INSTALL_CMD='npm ci && npm run build' \
  OUTPUT_DIR=dist

sudo v-plugin-git-deploy alice example.com
sudo v-plugin-git-list alice example.com json
```

---

## Choosing OUTPUT_DIR and INSTALL_CMD

| Project type | INSTALL_CMD | OUTPUT_DIR |
|---|---|---|
| Vite / CRA / Vue / Astro (static) | `npm ci && npm run build` | `dist` (or `build`, `out`, …) |
| Next.js static export | `npm ci && npm run build` | `out` |
| Plain HTML/CSS/JS in repo | _(empty)_ | `.` or `public` |
| PHP app (Laravel/Symfony root) | `composer install --no-dev --optimize-autoloader` | `.` |
| PHP + Vite assets | `composer install --no-dev && npm ci && npm run build` | `.` |
| Static site generator (Hugo, etc.) | `hugo --minify` | `public` |

**Rules:**

- `OUTPUT_DIR` is relative to `git-src/` (never absolute).
- Default is `dist`. If your build writes elsewhere, set it explicitly.
- `OUTPUT_DIR=.` publishes the repo root (minus excludes). Use a careful `EXCLUDE`.
- Empty `INSTALL_CMD` skips the build step (clone + copy only).

---

## Configuration reference (`config.conf`)

Path: `/home/<user>/web/<domain>/git-deploy/config.conf`

Update via UI or:

```bash
sudo v-plugin-git-set USER DOMAIN KEY=VALUE [KEY=VALUE...]
```

| Key | Default | Description |
|---|---|---|
| `REPO_URL` | — | Git remote (`git@…` or `https://…`) |
| `BRANCH` | `main` | Branch to deploy |
| `AUTH_METHOD` | `ssh` | `ssh` or `https` |
| `INSTALL_CMD` | _(empty)_ | Shell run in `git-src/` under timeout |
| `OUTPUT_DIR` | `dist` | Folder to publish (relative) |
| `EXCLUDE` | `.git,.env,.env.*,node_modules` | Extra rsync excludes (comma-separated) |
| `AUTO_DEPLOY` | `yes` | Webhook triggers deploy when `yes` |
| `TIMEOUT_SECONDS` | `300` | Max seconds for `INSTALL_CMD` |
| `MAX_RELEASES` | `5` | How many releases to keep |
| `HEALTHCHECK_URL` | _(empty)_ | Optional URL after switch; empty = skip |
| `HEALTHCHECK_EXPECT` | `200` | Expected HTTP status |
| `GIT_SUBMODULES` | `no` | `yes` = full clone + `submodule update` |
| `SETUP_DONE` | `no` | UI checklist marker (set automatically) |

Not writable via `v-plugin-git-set` (managed by other commands):

- `WEBHOOK_SECRET` — `v-plugin-git-secret-regenerate`
- `DEPLOY_KEY_PATH` / keys — `v-plugin-git-key-generate`
- `LAST_DEPLOYED_COMMIT` — updated on successful deploy

---

## Build secrets (`secrets.env`)

Path: `/home/<user>/web/<domain>/git-deploy/secrets.env` (mode `600`)

Loaded **only during `INSTALL_CMD`**. Never copied into releases / `public_html`.

Use for:

- `GIT_TOKEN` / `GIT_USERNAME` when `AUTH_METHOD=https`
- Private npm registries (`NPM_TOKEN`)
- Composer auth (`COMPOSER_AUTH`)
- Any build-time API keys

UI: Git Deploy → Environment / secrets (masked).  
CLI:

```bash
printf 'NPM_TOKEN=ghp_xxx\nCOMPOSER_AUTH={"github-oauth":{"github.com":"…"}}\n' \
  | sudo v-plugin-git-secrets-write alice example.com -
```

Secrets are redacted from `deploy.log`.

---

## Authentication

### SSH (recommended)

1. Enable Git Deploy → copy public key from UI (or `deploy_key.pub`)
2. GitHub/GitLab → repo → **Settings → Deploy keys** → add key (read-only)
3. `v-plugin-git-test USER DOMAIN`

Repo URL form: `git@github.com:org/repo.git`

### HTTPS + token

```bash
sudo v-plugin-git-set alice example.com AUTH_METHOD=https \
  REPO_URL='https://github.com/org/repo.git'

printf 'GIT_USERNAME=git\nGIT_TOKEN=ghp_xxxxxxxx\n' \
  | sudo v-plugin-git-secrets-write alice example.com -
```

---

## Deploy lifecycle

| Step | On failure |
|---|---|
| Acquire lock | Abort (“already running”) |
| `git clone` / `fetch` + `reset --hard` | Live site unchanged |
| Debounce (same commit, not forced) | No-op success |
| `INSTALL_CMD` | Live site unchanged |
| Copy `OUTPUT_DIR` → new release | Incomplete release deleted |
| Switch `current` + rsync `public_html` | Attempt revert to previous release |
| Healthcheck (if set) | Auto-rollback to previous release |
| Keep ≤ `MAX_RELEASES` | — |

Force redeploy of the same commit:

```bash
sudo v-plugin-git-deploy alice example.com force
# or UI: Force deploy
```

Async (UI / webhook):

```bash
sudo v-plugin-git-deploy-async alice example.com
```

Status:

```bash
sudo v-plugin-git-list alice example.com json
tail -f /home/alice/web/example.com/git-deploy/deploy.log
```

`status.json` states: `idle` | `running` | `failed` (plus `last_status`: `success`, `failed`, `failed_rolled_back`).

---

## Rollback

Does **not** need network, git, or a rebuild — switches an existing release:

```bash
# Previous release
sudo v-plugin-git-rollback alice example.com

# Specific release id
sudo v-plugin-git-rollback alice example.com 20260922-143015-abc1234
```

List releases via UI or:

```bash
ls -1 /home/alice/web/example.com/git-deploy/releases | sort -r
```

---

## Webhook auto-deploy

Point the webhook at the **panel host**, not the website.

Example URL:

```
https://<panel-host>:8083/git-deploy/webhook.php?user=alice&domain=example.com
```

1. Copy URL + secret from Git Deploy UI (secret shown once; regenerate if lost)
2. GitHub: Settings → Webhooks → payload URL above, secret = `WEBHOOK_SECRET`, content type `application/json`, event **push**
3. GitLab: Settings → Webhooks → URL + secret token, push events
4. Keep `AUTO_DEPLOY=yes`

Behavior:

- Valid signature → `200` immediately, deploy queued (`systemd-run` / `at`)
- Wrong/missing signature → `403`
- Push to another branch → no-op
- Same commit already live → debounce no-op

---

## What never reaches `public_html`

Always excluded (hard safety + defaults):

- `.git`
- `.env`, `.env.*`
- `node_modules` (default `EXCLUDE`)
- `secrets.env` (lives only under `git-deploy/`)

Extend excludes:

```bash
sudo v-plugin-git-set alice example.com \
  EXCLUDE='.git,.env,.env.*,node_modules,tests,phpunit.xml,.github'
```

---

## Persistent runtime files (important for PHP)

Each deploy **rsyncs with `--delete`** into `public_html`. Anything that only exists under `public_html` and is not in the release will be removed.

Put durable data **outside** the published tree, or regenerate it in `INSTALL_CMD`:

| Data | Recommendation |
|---|---|
| Uploads / media | Directory outside `public_html`, or symlink from a fixed path under `/home/user/` |
| `.env` (Laravel etc.) | Keep next to app **outside** release, or inject via `secrets.env` + generate during install — do **not** rely on editing `public_html/.env` |
| Caches / compiled views | Rebuild in `INSTALL_CMD` (`php artisan optimize`, etc.) |
| SQLite DB | Store outside `OUTPUT_DIR` / use managed DB |

Pattern for a PHP app with `OUTPUT_DIR=.`:

```bash
# Example INSTALL_CMD fragment — adjust paths to your layout
composer install --no-dev --optimize-autoloader \
  && php artisan config:cache \
  && php artisan route:cache
```

Keep the real `.env` in e.g. `/home/alice/web/example.com/app-env/.env` and copy or symlink it in `INSTALL_CMD` into `git-src/` **before** the release is created (so it is part of the release), **or** symlink from a path that is not wiped — prefer copy-into-`git-src` during install so the release is self-contained for that deploy.

---

## CLI command summary

| Command | Purpose |
|---|---|
| `v-plugin-git-add USER DOMAIN REPO [BRANCH]` | Enable + generate key/secret |
| `v-plugin-git-test USER DOMAIN` | `git ls-remote` check |
| `v-plugin-git-set USER DOMAIN KEY=VALUE…` | Update config |
| `v-plugin-git-secrets-write USER DOMAIN [-]` | Write `secrets.env` |
| `v-plugin-git-deploy USER DOMAIN [force]` | Deploy now |
| `v-plugin-git-deploy-async USER DOMAIN [force]` | Background deploy |
| `v-plugin-git-rollback USER DOMAIN [release_id]` | Rollback |
| `v-plugin-git-list USER [DOMAIN] [json\|shell]` | Status / config |
| `v-plugin-git-key-generate USER DOMAIN` | Rotate SSH key |
| `v-plugin-git-secret-regenerate USER DOMAIN` | Rotate webhook secret |
| `v-plugin-git-delete USER DOMAIN` | Remove git-deploy setup (keeps live `public_html`) |

---

## Staging vs production

The plugin maps **one domain ↔ one branch**. For staging:

1. Add a second domain (e.g. `staging.example.com`)
2. Enable Git Deploy on it with `BRANCH=develop` (or `staging`)
3. Use the same or different `INSTALL_CMD` / secrets

---

## Disable / uninstall

- **Disable one site:** `v-plugin-git-delete USER DOMAIN` — removes `git-src` + `git-deploy`, leaves current `public_html` files
- **After deleting a domain in Hestia:** run `hooks/cleanup-domain.sh USER DOMAIN`
- **Remove plugin from server:** `sudo ./uninstall.sh` (per-site `git-deploy/` dirs left in place)

---

## Next: scenarios

- [PHP application](./scenarios/php-app.md)
- [Node.js static site](./scenarios/nodejs-static.md)
- [PHP + frontend build](./scenarios/php-with-frontend.md)
- [Plain static HTML](./scenarios/plain-static.md)
- [Troubleshooting](./TROUBLESHOOTING.md)
