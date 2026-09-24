# HestiaCP Git Deploy

Plugin for [Hestia Control Panel](https://hestiacp.com/) that deploys a web domain from a Git repository: clone → install/build → atomic release → optional webhook auto-deploy → rollback.

See [spec.md](./spec.md) for the full design.

## Requirements

- HestiaCP host (Debian/Ubuntu)
- `git`, `rsync`, `ssh-keygen`, `openssl`, `curl`, `timeout`
- Optional for builds: `composer`, `node`/`npm` on the host PATH

## Install

```bash
sudo ./install.sh
```

This copies the plugin to `/usr/local/hestia/plugins/git-deploy` and symlinks `v-plugin-git-*` into `/usr/local/hestia/bin`.

## Web UI

After install, open:

```
https://<panel-host>:<port>/git-deploy/?domain=example.com
```

From **Web → Edit domain**, with [Pluginable](https://github.com/virtuosoft-dev/hestiacp-pluginable) installed, a **Git Deploy** button is injected automatically.

The page supports:
- Enable / configure repo, branch, auth, install command, output dir
- Deploy now / Force deploy (async) with live status poll
- Rollback to previous or specific release
- Copy deploy public key + webhook URL
- Regenerate deploy key / webhook secret (secret shown once)
- Build secrets (`secrets.env`, masked until replaced)
- Disable (keeps `public_html`)

## Quick start

```bash
# 1. Initialize (prints deploy public key + webhook secret once)
sudo v-plugin-git-add alice example.com git@github.com:org/site.git main

# 2. Add the printed public key as a read-only Deploy Key in GitHub/GitLab

# 3. Configure build (or use the UI)
sudoedit /home/alice/web/example.com/git-deploy/config.conf
# Set INSTALL_CMD and OUTPUT_DIR, e.g.:
#   INSTALL_CMD="npm ci && npm run build"
#   OUTPUT_DIR=dist

# 4. Deploy
sudo v-plugin-git-deploy alice example.com

# 5. Inspect
sudo v-plugin-git-list alice example.com json
```

### Useful commands

| Command | Purpose |
|---|---|
| `v-plugin-git-add USER DOMAIN REPO [BRANCH]` | Enable git deploy for a domain |
| `v-plugin-git-deploy USER DOMAIN [force]` | Full deploy (`force` skips commit debounce) |
| `v-plugin-git-rollback USER DOMAIN [release_id]` | Switch to previous (or given) release |
| `v-plugin-git-list USER [DOMAIN] [json\|shell]` | Show config + status |
| `v-plugin-git-key-generate USER DOMAIN` | Rotate SSH deploy key |
| `v-plugin-git-set USER DOMAIN KEY=VALUE...` | Update allowed config keys |
| `v-plugin-git-secret-regenerate USER DOMAIN` | Rotate webhook secret (prints once) |
| `v-plugin-git-delete USER DOMAIN` | Remove `git-src` + `git-deploy` (keeps `public_html`) |

## Layout per site

```
/home/<user>/web/<domain>/
├── public_html/          # live files (rsync from current release)
├── git-src/              # private clone
└── git-deploy/
    ├── config.conf
    ├── secrets.env       # build-only env (mode 600)
    ├── status.json
    ├── deploy.log
    ├── deploy_key[.pub]
    ├── known_hosts
    ├── current → releases/<id>/
    └── releases/
```

## Webhook (Phase 2)

Point GitHub/GitLab webhook to the **panel** host (not the site), with the secret from `config.conf`.

Example PHP endpoint: `/git-deploy/webhook.php?user=alice&domain=example.com`  
(or the plugin `webhook/listener.php` behind nginx `fastcgi_param`).

Supports:

- GitHub `X-Hub-Signature-256`
- GitLab `X-Gitlab-Token`

On success it returns `200` immediately and starts `v-plugin-git-deploy` via `systemd-run` (or `at`).

## Domain delete cleanup

Call after `v-delete-web-domain`:

```bash
/usr/local/hestia/plugins/git-deploy/hooks/cleanup-domain.sh USER DOMAIN
```

## Uninstall

```bash
sudo ./uninstall.sh
```

Per-site `git-deploy/` directories are left in place.

## Security notes

- All git/install/rsync work runs as the site user (never leaves secrets in `public_html`)
- `.git`, `.env*` are always excluded from releases
- `OUTPUT_DIR` is validated against path traversal
- Concurrent deploys are blocked with `deploy.lock`
- Webhook HMAC is mandatory (403 otherwise)

## License

MIT
