# Scenario: Plain static / HTML (no build)

Publish files from Git as-is — no Composer, no npm.

## When to use this

- Hand-written HTML/CSS/JS
- Pre-built artifacts already committed (e.g. `dist/` committed — uncommon but supported)
- Docs sites generated elsewhere and pushed as plain files

## Config

### Repo root is the site

```bash
sudo v-plugin-git-set alice docs.example.com \
  INSTALL_CMD='' \
  OUTPUT_DIR=. \
  EXCLUDE='.git,.env,.env.*,node_modules,README.md,.github'
```

Empty `INSTALL_CMD` skips the install step (clone/fetch → copy → go live).

If `OUTPUT_DIR` is still the default `dist` and that folder is not in the checkout, the deploy publishes the repository root and saves `OUTPUT_DIR=.`.

### Site lives in a subfolder

```bash
sudo v-plugin-git-set alice docs.example.com \
  INSTALL_CMD='' \
  OUTPUT_DIR=public
```

or `website`, `www`, etc.

### Prebuilt `dist/` committed to git

```bash
sudo v-plugin-git-set alice docs.example.com \
  INSTALL_CMD='' \
  OUTPUT_DIR=dist
```

Prefer building on the server ([Node.js static](./nodejs-static.md)) so you do not commit build artifacts — but this works for simple workflows.

## Checklist

1. [ ] No reliance on server-side Node/PHP for build  
2. [ ] `OUTPUT_DIR` points at the folder that should become `public_html`  
3. [ ] Secrets / `.env` not in the published tree (EXCLUDE)  

## Related

- [Node.js static site](./nodejs-static.md) — when you want `npm run build` on the server
- [Usage guide](../USAGE.md)
