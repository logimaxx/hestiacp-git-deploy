# Scenario: PHP application

Deploy a PHP site (Laravel, Symfony, custom PHP, WordPress-in-git, etc.) where the **document root should contain PHP sources**, not a separate `dist/` folder.

## When to use this

- App entrypoint is `index.php` (or `public/index.php` — see below)
- Dependencies via Composer
- Optional Artisan / console post-install steps
- No Node build, or Node is covered in [PHP + frontend](./php-with-frontend.md)

## Core settings

**Preferred:** commit `.hestia-install.sh` (or `scripts/deploy.sh`) and set:

```bash
sudo v-plugin-git-set alice example.com \
  INSTALL_CMD='bash .hestia-install.sh' \
  OUTPUT_DIR=. \
  EXCLUDE='.git,.env,.env.*,node_modules,tests,.github,phpunit.xml,.phpunit.result.cache' \
  TIMEOUT_SECONDS=600
```

See **[Install script](../install-script.md)** for templates (shared runtime, dotenv, seeding).

| Key | Typical value |
|---|---|
| `INSTALL_CMD` | `bash .hestia-install.sh` (or inline `composer install --no-dev --optimize-autoloader`) |
| `OUTPUT_DIR` | `.` (repo root) **or** `public` if only the public dir is served |
| `EXCLUDE` | Expand defaults so vendor sources stay, junk does not |

### Document root = repository root

Many simple PHP apps and some frameworks place `index.php` at the repo root. Inline equivalent if you skip a script:

```bash
sudo v-plugin-git-set alice example.com \
  INSTALL_CMD='composer install --no-dev --optimize-autoloader' \
  OUTPUT_DIR=. \
  EXCLUDE='.git,.env,.env.*,node_modules,tests,.github,phpunit.xml,.phpunit.result.cache'
```
### Document root = `public/` (Laravel / Symfony public)

Hestia’s webroot is always `public_html/`. Two approaches:

**A — Publish only `public/` (static front controller + assets)**  
Not enough alone for Laravel: PHP still needs `vendor/` and `bootstrap/` **outside** `public/`. Prefer B.

**B — Publish the whole app (`OUTPUT_DIR=.`) and point the domain document root to `public_html/public`**

1. Deploy with `OUTPUT_DIR=.`
2. In Hestia **Web → Edit domain**, set custom document root / “Public directory” to `public` if your Hestia version supports it  
   **or** use an nginx/Apache custom template that sets root to `…/public_html/public`

```bash
sudo v-plugin-git-set alice example.com \
  INSTALL_CMD='composer install --no-dev --optimize-autoloader && php artisan config:cache && php artisan route:cache && php artisan view:cache' \
  OUTPUT_DIR=. \
  EXCLUDE='.git,.env,.env.*,node_modules,tests,.github,phpunit.xml,storage/logs/*,storage/framework/cache/*,storage/framework/sessions/*,storage/framework/views/*' \
  TIMEOUT_SECONDS=600
```

Adjust Artisan steps to what your app needs. Increase `TIMEOUT_SECONDS` for large `vendor/` trees.

## Host packages

```bash
php -v
composer --version
# PHP extensions your app needs (mbstring, xml, curl, zip, …)
sudo -u alice -H bash -lc 'composer --version && php -m'
```

## Environment / `.env`

`.env` is **never** taken from the git tree into the release by default (`EXCLUDE` includes `.env*`). Do not commit production secrets.

Recommended pattern — copy a server-side env file during install:

```bash
# Once on the server (not in git):
sudo mkdir -p /home/alice/web/example.com/shared
sudo cp /path/to/production.env /home/alice/web/example.com/shared/.env
sudo chown -R alice:alice /home/alice/web/example.com/shared
sudo chmod 600 /home/alice/web/example.com/shared/.env
```

```bash
sudo v-plugin-git-set alice example.com \
  INSTALL_CMD='composer install --no-dev --optimize-autoloader && cp /home/alice/web/example.com/shared/.env .env && php artisan config:cache'
```

Because copy happens **before** the release snapshot, `.env` becomes part of that release under `git-src/` → release. Ensure `.env` is **not** listed in a way that strips it after copy — default EXCLUDE applies when rsyncing `OUTPUT_DIR`; if `OUTPUT_DIR=.`, `.env` in the tree is excluded from the release!

**Important:** With default `EXCLUDE=.git,.env,.env.*,…`, a `.env` copied into `git-src/` is still **stripped** when creating the release. Options:

1. **Remove `.env` from EXCLUDE** after you stop committing env files (keep `.env.example` only in git):

   ```bash
   sudo v-plugin-git-set alice example.com \
     EXCLUDE='.git,node_modules,tests,.github,phpunit.xml'
   ```

   Then `cp shared/.env .env` in `INSTALL_CMD` will be included in the release.

2. **Symlink from `public_html` to a path outside the sync** — fragile with `rsync --delete`; prefer (1) or keep secrets in `shared/` and symlink inside `INSTALL_CMD` to a location under `git-src` that is not matched by EXCLUDE (e.g. name it `env.local.php` and require it from code).

3. Use **`secrets.env`** only for build-time values; runtime PHP still needs a file the app can read under the live tree.

## Writable directories (Laravel `storage/`, `bootstrap/cache`)

`rsync --delete` refreshes `public_html` each deploy. Writable dirs that must persist:

**Option A — recreate / chmod every deploy** (stateless cache; uploads elsewhere):

```bash
INSTALL_CMD='… && mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache && chmod -R ug+rwx storage bootstrap/cache'
```

**Option B — shared storage outside releases** (uploads persist):

```bash
# Once
sudo mkdir -p /home/alice/web/example.com/shared/storage
sudo chown -R alice:alice /home/alice/web/example.com/shared

# In INSTALL_CMD, after composer:
# rm -rf storage && ln -s /home/alice/web/example.com/shared/storage storage
```

Same idea for `public/uploads` if applicable.

## WordPress (code in git)

Typical layout: WP core + theme/plugins in repo; `wp-config.php` and `wp-content/uploads` on the server.

```bash
sudo v-plugin-git-set alice blog.example.com \
  INSTALL_CMD='' \
  OUTPUT_DIR=. \
  EXCLUDE='.git,.env,.env.*,node_modules,wp-content/uploads'
```

Keep uploads in `shared/` and symlink in a small `INSTALL_CMD`, or exclude uploads and restore the symlink after each deploy via install script.

## Healthcheck (optional)

```bash
sudo v-plugin-git-set alice example.com \
  HEALTHCHECK_URL='http://127.0.0.1/' \
  HEALTHCHECK_EXPECT=200
```

Curl sends `Host: example.com`. Failed check rolls back to the previous release.

## Checklist

1. [ ] PHP + Composer available as site user  
2. [ ] Deploy key on GitHub/GitLab; `v-plugin-git-test` OK  
3. [ ] `OUTPUT_DIR` matches how Hestia serves the app (`.` vs custom public dir)  
4. [ ] Runtime `.env` strategy that survives EXCLUDE + rsync  
5. [ ] Writable / upload paths either rebuilt or shared outside releases  
6. [ ] First `v-plugin-git-deploy`; confirm site and `deploy.log`  
7. [ ] Webhook if you want push-to-deploy  

## Related

- **[Install script](../install-script.md)** — put Composer, dotenv, and shared storage in `.hestia-install.sh`
- [PHP + frontend build](./php-with-frontend.md)
- [Usage guide — persistent files](../USAGE.md#persistent-runtime-files-important-for-php)
- [Troubleshooting](../TROUBLESHOOTING.md)
