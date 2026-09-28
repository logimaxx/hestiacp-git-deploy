# Scenario: PHP app + Node frontend build

One repository that needs **Composer** and an **npm/Vite (or similar) asset build**, then publishes the PHP app tree (usually `OUTPUT_DIR=.`).

## When to use this

- Laravel + Vite / Mix
- Symfony + Webpack Encore / Asset Mapper with a Node step
- Custom PHP where `npm run build` writes into `public/build` (or similar)

## Core settings

```bash
sudo v-plugin-git-set alice app.example.com \
  INSTALL_CMD='composer install --no-dev --optimize-autoloader && npm ci && npm run build' \
  OUTPUT_DIR=. \
  EXCLUDE='.git,.env,.env.*,node_modules,tests,.github' \
  TIMEOUT_SECONDS=900
```

Order matters: install PHP deps first (some scripts expect `vendor/`), then JS build so assets land under `public/` before the release snapshot.

### Laravel + Vite example

```bash
INSTALL_CMD='composer install --no-dev --optimize-autoloader && npm ci && npm run build && php artisan config:cache && php artisan route:cache'
OUTPUT_DIR=.
```

Vite emits to `public/build` by default — that path is inside the tree published by `OUTPUT_DIR=.`.

## Host requirements

Both toolchains must work as the site user:

```bash
sudo -u alice -H bash -lc 'php -v; composer -V; node -v; npm -v'
```

## Secrets

```bash
printf 'NPM_TOKEN=…\n' | sudo v-plugin-git-secrets-write alice app.example.com -
# plus shared .env strategy from the PHP scenario
```

See [PHP application](./php-app.md) for `.env`, `storage/`, and document-root (`public/`) notes — they apply fully here.

## Faster builds (optional)

- Cache Composer: `composer install` already uses user home cache under `/home/<user>/`
- Prefer `npm ci` over `npm install`
- Skip dev npm deps if you have a production-only script: `npm ci --omit=dev && npm run build` only if your build does not need devDependencies (many Vite setups **need** them at build time — keep default `npm ci`)

## Related

- [PHP application](./php-app.md)
- [Node.js static site](./nodejs-static.md)
