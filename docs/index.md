# HestiaCP Git Deploy — Documentation

Plugin that deploys a Hestia web domain from a Git repository:

**clone → install/build → atomic release → sync `public_html` → optional webhook / rollback**

## Contents

| Doc | What it covers |
|---|---|
| [Usage guide](./USAGE.md) | Concepts, first deploy, config reference, CLI, webhook, security |
| **[Install script](./install-script.md)** | **Recommended:** commit `.hestia-install.sh` and set `INSTALL_CMD='bash .hestia-install.sh'` |
| [PHP application](./scenarios/php-app.md) | Laravel, Symfony, WordPress-like PHP apps (`OUTPUT_DIR=.`) |
| [Node.js static site](./scenarios/nodejs-static.md) | Vite / Next export / Astro / Vue / React build → `dist` |
| [PHP + frontend build](./scenarios/php-with-frontend.md) | Composer + npm in one deploy |
| [Plain static / HTML](./scenarios/plain-static.md) | No build step — publish repo files as-is |
| [Troubleshooting](./TROUBLESHOOTING.md) | Common failures and how to fix them |

Design / architecture: [spec.md](../spec.md)  
Quick install & CLI cheat sheet: [README.md](../README.md)
