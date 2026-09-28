# Troubleshooting

Logs and status live per domain:

```bash
tail -100 /home/<user>/web/<domain>/git-deploy/deploy.log
cat /home/<user>/web/<domain>/git-deploy/status.json
sudo v-plugin-git-list <user> <domain> json
```

---

## Install / UI

| Symptom | Likely cause | Fix |
|---|---|---|
| 404 on Git Deploy URL | Plugin UI not installed on panel host | `sudo ./install.sh` on the **panel** server |
| 500 on Git Deploy page | Stale/incomplete web copy | Re-run `sudo ./install.sh`; check `/var/log/hestia/nginx-error.log` |
| Enable fails / config not readable | Directory perms `750` | Re-run install; `chmod 755 …/git-deploy` and `644` on `config.conf` / `status.json` / `deploy_key.pub` |

---

## Git / auth

| Symptom | Likely cause | Fix |
|---|---|---|
| `git clone` / `fetch` failed | Deploy key not added, wrong repo URL, or key perms | Add read-only deploy key; `chmod 600 deploy_key`; `v-plugin-git-test` |
| `Permission denied (publickey)` | Key not on remote or wrong key | Regenerate: `v-plugin-git-key-generate`; update GitHub/GitLab |
| HTTPS auth fails | Missing `GIT_TOKEN` | Write `secrets.env` via UI or `v-plugin-git-secrets-write` |
| Submodule errors | Shallow clone | `GIT_SUBMODULES=yes` |

---

## Build / INSTALL_CMD

| Symptom | Likely cause | Fix |
|---|---|---|
| `command not found: npm` / `composer` | Not on site-user PATH | Install tools; verify `sudo -u USER -H bash -lc 'which npm'` |
| Install timeout | Large `npm ci` / Composer | Raise `TIMEOUT_SECONDS` (e.g. 900) |
| `OUTPUT_DIR does not exist` | Wrong out dir or build failed silently | Match Vite/Next outDir; run build locally; check log above the error |
| OOM during Node build | Small VPS | Raise swap / `NODE_OPTIONS` in `secrets.env` / build elsewhere |
| Private npm 401 | No token | `NPM_TOKEN` in `secrets.env` + `.npmrc` |

Failed install **does not** change `public_html`.

---

## Live site content

| Symptom | Likely cause | Fix |
|---|---|---|
| Old site after “success” | Same commit debounce | `v-plugin-git-deploy USER DOMAIN force` |
| Missing files / emptied uploads | `rsync --delete` + data only in `public_html` | Keep durable data in `shared/` + symlink in `INSTALL_CMD` |
| `.env` missing on Laravel | EXCLUDE strips `.env*` | See [PHP scenario](./scenarios/php-app.md) env section |
| `.git` visible in webroot | Should not happen | Report bug; releases force-remove `.git` |
| SPA routes 404 | No fallback to `index.html` | Custom nginx `try_files` |
| Assets 404 | Wrong bundler `base` | Fix `base` / `basePath` in app config |

---

## Webhook

| Symptom | Likely cause | Fix |
|---|---|---|
| 403 | Bad/missing signature | Secret must match `WEBHOOK_SECRET`; regenerate and update GitHub/GitLab |
| 200 but no deploy | Other branch, `AUTO_DEPLOY=no`, or debounce | Push to configured `BRANCH`; set `AUTO_DEPLOY=yes`; force deploy once |
| Webhook to site domain | Wrong host | Use **panel** URL (`:8083/.../webhook.php?user=&domain=`) |

---

## Lock / concurrency

| Symptom | Likely cause | Fix |
|---|---|---|
| “deploy already running” | Overlapping webhook + manual | Wait; if stuck after crash, remove stale `git-deploy/deploy.lock` only when no deploy process is active |

---

## Rollback

```bash
sudo v-plugin-git-rollback USER DOMAIN
ls /home/USER/web/DOMAIN/git-deploy/releases
```

Needs at least two releases on disk (`MAX_RELEASES` ≥ 2 for a useful history).
