# Scenario: Node.js static site

Build a static frontend on the Hestia host and publish only the build output (no `node_modules` in the webroot).

## When to use this

- Vite, Vue, React (CRA), Astro, SvelteKit static, Eleventy, Next.js **static export**, Nuxt generate, etc.
- The live site is HTML/CSS/JS (and assets) — PHP is not required for the app itself
- Build runs on the server via `npm` / `pnpm` / `yarn`

## Core idea

```
git-src/  →  npm ci && npm run build  →  dist/ (or out/)  →  public_html/
```

`node_modules` stays in `git-src/` only and is excluded from releases by default.

## Core settings

**Preferred:** ship a repo script and keep the panel thin:

```bash
# .hestia-install.sh in the repo:
#   #!/usr/bin/env bash
#   set -euo pipefail
#   npm ci && npm run build

sudo v-plugin-git-set alice www.example.com \
  INSTALL_CMD='bash .hestia-install.sh' \
  OUTPUT_DIR=dist \
  TIMEOUT_SECONDS=600
```

Full guide: **[Install script](../install-script.md)**.

| Key | Typical value |
|---|---|
| `INSTALL_CMD` | `bash .hestia-install.sh` (or inline `npm ci && npm run build`) |
| `OUTPUT_DIR` | `dist` (Vite/Vue/Astro default), `build` (CRA), `out` (Next export), `public` (some SSGs) |
| `EXCLUDE` | defaults are usually enough |
| `TIMEOUT_SECONDS` | `300`–`900` for large installs |

### Vite / Vue / React (Vite) / Astro

Inline (or the body of `.hestia-install.sh`):

```bash
sudo v-plugin-git-set alice www.example.com \
  INSTALL_CMD='npm ci && npm run build' \
  OUTPUT_DIR=dist \
  TIMEOUT_SECONDS=600
```
Confirm the folder in `package.json` / vite config (`build.outDir`).

### Create React App

```bash
sudo v-plugin-git-set alice www.example.com \
  INSTALL_CMD='npm ci && npm run build' \
  OUTPUT_DIR=build
```

### Next.js static export

In `next.config.js`: `output: 'export'` (and no server-only features). Build writes to `out/`:

```bash
sudo v-plugin-git-set alice www.example.com \
  INSTALL_CMD='npm ci && npm run build' \
  OUTPUT_DIR=out \
  TIMEOUT_SECONDS=900
```

### Nuxt (static)

```bash
sudo v-plugin-git-set alice www.example.com \
  INSTALL_CMD='npm ci && npm run generate' \
  OUTPUT_DIR=dist
```

(Adjust `OUTPUT_DIR` to match your Nuxt major version / `nitro` output.)

### pnpm / yarn

```bash
# pnpm
INSTALL_CMD='pnpm install --frozen-lockfile && pnpm run build'

# yarn
INSTALL_CMD='yarn install --frozen-lockfile && yarn build'
```

Ensure `pnpm` / `yarn` exist on PATH for the site user.

## Host setup

```bash
node -v   # LTS recommended; match engines in package.json
npm -v
sudo -u alice -H bash -lc 'node -v && npm -v'
```

Tips:

- Prefer one Node version for all sites, or install via a version manager that site users can call
- Disk quota: `git-src/node_modules` counts against the user — large monorepos need headroom
- Set `NODE_OPTIONS=--max-old-space-size=4096` in `secrets.env` if builds OOM

## Private packages / env at build time

```bash
printf 'NPM_TOKEN=ghp_xxx\nVITE_API_BASE=https://api.example.com\n' \
  | sudo v-plugin-git-secrets-write alice www.example.com -
```

Example `INSTALL_CMD` when using a GitHub npm package:

```bash
INSTALL_CMD='npm config set //npm.pkg.github.com/:_authToken=$NPM_TOKEN && npm ci && npm run build'
```

Or rely on `.npmrc` in the repo that references `${NPM_TOKEN}` (token only in `secrets.env`).

**Note:** `VITE_*` / `NEXT_PUBLIC_*` are baked into the JS bundle at build time. Changing them requires a new deploy (force if commit unchanged).

## SPA routing (Vue/React Router history mode)

Static hosts need a fallback to `index.php` or `index.html` for client routes. In Hestia, use a custom nginx template, e.g.:

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

(Exact snippet depends on your Hestia nginx template.)

## Base path / asset URLs

If the site is not at domain root, set the bundler `base` (Vite `base`, Next `basePath`) in the repo so asset URLs match.

## Minimal end-to-end (CLI)

```bash
sudo v-plugin-git-add alice www.example.com git@github.com:org/marketing-site.git main
# add deploy key on GitHub…
sudo v-plugin-git-test alice www.example.com

sudo v-plugin-git-set alice www.example.com \
  INSTALL_CMD='npm ci && npm run build' \
  OUTPUT_DIR=dist \
  AUTO_DEPLOY=yes \
  TIMEOUT_SECONDS=600

sudo v-plugin-git-deploy alice www.example.com
curl -I https://www.example.com/
```

## Checklist

1. [ ] Node/npm (or pnpm/yarn) on PATH for site user  
2. [ ] `OUTPUT_DIR` matches real build output (check once in CI or locally)  
3. [ ] Lockfile committed (`package-lock.json` / `pnpm-lock.yaml` / `yarn.lock`) — prefer `npm ci`  
4. [ ] Build env vars in `secrets.env`  
5. [ ] SPA fallback configured if using client-side routing  
6. [ ] Webhook for push-to-deploy (optional)  

## Related

- **[Install script](../install-script.md)**
- [PHP + frontend](./php-with-frontend.md) — when the same repo also serves PHP
- [Plain static](./plain-static.md) — no Node build
- [Troubleshooting](../TROUBLESHOOTING.md)
