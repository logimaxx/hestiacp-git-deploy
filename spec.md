# Specificație: Plugin HestiaCP — Deploy Site din Repository Git

## 1. Scop

Extensie pentru HestiaCP care permite asocierea unui domeniu/website cu un repository Git, cu suport pentru:
- clonare automată a codului sursă
- rulare install/build script definit de utilizator
- deploy atomic al rezultatului în webroot
- redeploy automat la push (webhook) sau manual (din panou)
- rollback la un release anterior funcțional

Nu modifică scripturile core existente ale Hestia — se adaugă ca set nou de comenzi și pagini UI, pentru compatibilitate cu update-urile Hestia.

### 1.1 Non-goals (MVP)

- Multi-branch pe același domeniu (staging = domeniu separat)
- Blue-green pe mai multe servere / cluster
- CI matrix, test runners, preview environments
- Editare live a fișierelor din `git-src/` din panou

---

## 2. Arhitectură generală

```
Repo (GitHub/GitLab)
   │  push / webhook
   ▼
Webhook Listener (pe hostname-ul panoului, validare HMAC)
   ▼
Async queue (systemd-run / at) → răspuns 200 imediat
   ▼
Deploy Engine (script bash, rulat ca user jailat)
   │
   ├── 1. lock
   ├── 2. git fetch/reset  → git-src/
   ├── 3. install script   → rulează în git-src/
   ├── 4. copy output      → git-deploy/releases/<id>/
   ├── 5. atomic switch    → current + public_html
   ├── 6. healthcheck (opțional) → auto-rollback la eșec
   └── 7. status.json + deploy.log + unlock
   ▼
UI Hestia (tab „Git Deploy” per domeniu)
```

---

## 3. Packaging & convenții

- Distribuție ca **plugin** (Hestia native plugins și/sau Pluginable), nu fork.
- Comenzi CLI cu prefix **`v-plugin-git-*`** pentru a evita coliziuni cu viitoare comenzi core Hestia.
- Nu se editează scripturi core (`v-add-web-domain` etc.).
- Hook pe `v-delete-web-domain`: la ștergerea domeniului se curăță `git-src/`, `git-deploy/`, chei și secrets.

---

## 4. Componente

### 4.1 Structură de directoare per site

```
/home/<user>/web/<domain>/
├── public_html/                 # symlink → git-deploy/current
│                                # (sau document root configurat să pointeze la current)
├── git-src/                     # clone complet al repo-ului (nu e public)
└── git-deploy/
    ├── config.conf              # configurație deploy
    ├── secrets.env              # variabile build-only (perm 600); nicodată în webroot
    ├── status.json              # stare curentă (pentru UI / polling)
    ├── deploy.log               # log verbose (sanitizat)
    ├── deploy.lock              # lock concurență
    ├── deploy_key               # cheie SSH privată (perm 600), dacă AUTH_METHOD=ssh
    ├── deploy_key.pub
    ├── known_hosts              # host keys GitHub/GitLab (ssh-keyscan controlat la add)
    ├── current → releases/<id>/ # symlink release activ
    └── releases/
        ├── <timestamp>-<shortsha>/
        └── ...                  # max MAX_RELEASES (default 5); restul se șterg
```

`public_html.bak/` din draft-ul anterior este înlocuit de modelul **releases + symlink**. Rollback = switch symlink la release-ul anterior.

### 4.2 Fișier de configurare (`git-deploy/config.conf`)

```ini
REPO_URL=git@github.com:user/repo.git
BRANCH=main
AUTH_METHOD=ssh                 # ssh | https
# Pentru https: token în secrets.env ca GIT_TOKEN / GIT_ASKPASS helper
INSTALL_CMD="composer install --no-dev && npm ci && npm run build"
OUTPUT_DIR="dist"               # relativ la git-src/; validat: realpath sub git-src/
EXCLUDE=".git,.env,.env.*,node_modules"
DEPLOY_KEY_PATH=/home/user/web/domain.tld/git-deploy/deploy_key
WEBHOOK_SECRET=<hmac secret, generat automat>
AUTO_DEPLOY=yes                 # yes/no
TIMEOUT_SECONDS=300
MAX_RELEASES=5
HEALTHCHECK_URL=                # gol = dezactivat; ex. http://127.0.0.1/ cu Host header
HEALTHCHECK_EXPECT=200
GIT_SUBMODULES=no               # yes necesită clone non-shallow sau fetch submodule
LAST_DEPLOYED_COMMIT=           # actualizat la succes; folosit pentru debounce webhook
SETUP_DONE=no                   # yes după skip setup sau primul deploy reușit (UI checklist)
```

Reguli:
- `OUTPUT_DIR` trebuie să rezolve sub `git-src/` (anti path-traversal).
- La rsync/copy spre release se aplică mereu `EXCLUDE` (minim: `.git`, `.env*`).
- `OUTPUT_DIR="."` e permis, dar UI-ul afișează warning puternic; EXCLUDE rămâne obligatoriu.
- `WEBHOOK_SECRET` și tokenii **nu** apar în `deploy.log` (sanitizare la scriere).

### 4.3 `status.json` (exemplu)

```json
{
  "state": "idle",
  "last_status": "success",
  "last_commit": "abc1234",
  "last_release": "20260922-143015-abc1234",
  "started_at": null,
  "finished_at": "2026-09-22T14:30:40Z",
  "duration_seconds": 25,
  "message": "Deploy OK"
}
```

`state`: `idle` | `running` | `failed`. UI și webhook poll pe acest fișier.

### 4.4 Comenzi CLI (`v-plugin-git-*`)

| Comandă | Descriere |
|---|---|
| `v-plugin-git-add <user> <domain> <repo_url> <branch>` | Inițializează: structură, deploy key / secrets stub, `known_hosts`, config, webhook secret |
| `v-plugin-git-test <user> <domain>` | Verifică accesul la remote (`git ls-remote`) cu deploy key / HTTPS helper |
| `v-plugin-git-deploy <user> <domain>` | Flux complet: lock → fetch → install → release → switch → healthcheck → log |
| `v-plugin-git-rollback <user> <domain> [release_id]` | Switch la release anterior (default) sau la `release_id` explicit |
| `v-plugin-git-delete <user> <domain>` | Elimină config + `git-src` + `git-deploy` (webroot/release activ: politică — păstrează conținutul curent materializat, rupe legătura git) |
| `v-plugin-git-list <user> [domain]` | Config + status (plain/json/shell, convenție Hestia) |
| `v-plugin-git-key-generate <user> <domain>` | (Re)generează perechea SSH + actualizează `known_hosts` |

Toate comenzile respectă output-ul Hestia (plain/json/shell) pentru API și panou.

### 4.5 Deploy Engine — pași detaliați

1. **Lock**: creează `git-deploy/deploy.lock` (fail dacă există și e fresh; stale lock după timeout → reclaim cu warning). Setează `status.state=running`.
2. **Fetch**:
   - clone inițial (shallow `--depth=1` dacă `GIT_SUBMODULES=no`) sau
   - `git -C git-src fetch --depth=1 origin $BRANCH` + `reset --hard origin/$BRANCH`
   - auth: SSH (`GIT_SSH_COMMAND` + `deploy_key` + `known_hosts`) sau HTTPS (token din `secrets.env`)
3. **Debounce**: dacă commit curent == `LAST_DEPLOYED_COMMIT` și nu e forțat manual → exit success „already deployed”, unlock.
4. **Install**: rulează `INSTALL_CMD` în `git-src/`, cu:
   - `timeout $TIMEOUT_SECONDS`
   - user/grup = user-ul jailat al site-ului (niciodată root)
   - `env` minim + `secrets.env` (build-only)
   - output → `deploy.log` (sanitizat)
   - **exit ≠ 0 → stop, webroot neatins, `last_status=failed`, unlock**
5. **Release**: creează `releases/<timestamp>-<shortsha>/`, copiază din `git-src/$OUTPUT_DIR` cu `EXCLUDE` aplicat. Validează că path-ul sursă e sub `git-src/`.
6. **Atomic switch**:
   - actualizează `git-deploy/current` → noul release (symlink atomic: `ln -sfn` + `mv`)
   - aliniază `public_html` (symlink sau sync final din `current`, conform alegerii de integrare cu template-ul web Hestia)
   - `chown`/`chmod` ca user-ul site-ului, consistent cu PHP-FPM template
7. **Healthcheck** (dacă `HEALTHCHECK_URL` setat):
   - curl local cu Host header / URL configurat
   - așteaptă `HEALTHCHECK_EXPECT`
   - **eșec → auto-rollback la release-ul anterior**, `last_status=failed_rolled_back`
8. **Cleanup**: păstrează cel mult `MAX_RELEASES`; șterge release-urile vechi (nu pe `current`).
9. **Unlock + status**: scrie `status.json`, actualizează `LAST_DEPLOYED_COMMIT`, log final.

### 4.6 Rollback

`v-plugin-git-rollback <user> <domain> [release_id]`:
- nu necesită git-src, rețea sau `INSTALL_CMD`
- switch atomic `current` (+ `public_html`) la release-ul țintă
- dacă nu există release anterior → eroare clară

### 4.7 Webhook Listener

- **Endpoint pe hostname-ul panoului**, nu pe site — evită ștergerea de `rsync --delete`, CDN și confuzia originului.
  - Exemplu: `POST https://<panel-host>:<port>/git-deploy/<user>/<domain>`
  - sau invocarea prin mecanismul de plugin al panoului
- Validează HMAC:
  - GitHub: `X-Hub-Signature-256`
  - GitLab: `X-Gitlab-Token` (sau signature dacă e configurat)
- Verifică că push-ul e pe `BRANCH` configurat; altfel 200 + no-op (sau 204).
- Dacă `AUTO_DEPLOY=yes` și semnătura e validă:
  - răspunde **imediat 200**
  - pornește deploy **async**: `systemd-run` (preferat) sau `at now` care apelează `v-plugin-git-deploy`
- Debounce: dacă commit-ul e deja `LAST_DEPLOYED_COMMIT` sau lock activ → skip / queue coadă scurtă
- Request nesemnat / secret greșit → **403** (fără excepții)
- Webhook URL-ul din UI pointează mereu la panel; secret afișat o singură dată / regenerabil

### 4.8 UI — tab „Git Deploy” (Web → Domeniu)

Elemente:
- Repo URL + Branch + Auth method (SSH / HTTPS)
- Public key (copy) pentru Deploy Key în GitHub/GitLab
- Install Command
- Output Directory (+ warning dacă `.`)
- Exclude patterns
- Toggle Auto-deploy on push
- Webhook URL (panel) + secret (shown-once / regenerate)
- Câmpuri Environment (scriu în `secrets.env`, mascate)
- Buton „Deploy acum”
- Buton „Rollback” (+ selector release dacă există istoric)
- Istoric: timestamp, commit, release id, status, link log
- Status live din `status.json` (poll cât timp `state=running`)

---

## 5. Securitate & trust model

### 5.1 Trust model

- Doar **owner-ul domeniului** (și admin Hestia) poate seta `INSTALL_CMD`, secrets, repo URL.
- `INSTALL_CMD` este shell arbitrar **intenționat**, dar rulează doar ca user-ul jailat al acelui site — compromiterea unui site nu oferă root și nu citește alte `/home/<alt-user>/`.
- Panoul nu rulează install ca root și nu expune `secrets.env` în API listările plain fără mascare.

### 5.2 Controale

- **Deploy key / token per site**, nu credențiale globale de server
- **Izolare user**: clone, install, copy, symlink — user jailat
- **HMAC obligatoriu** pe webhook
- **Fără `.git` / secrets în webroot** — doar conținutul release-ului, cu EXCLUDE
- **Validare path** pe `OUTPUT_DIR`
- **Timeout** + lock; ulterior nice/cgroup (Faza 4)
- **Sanitizare log**: nu scrie `WEBHOOK_SECRET`, `GIT_TOKEN`, valori din `secrets.env`

---

## 6. Failure modes

| Etapă | Webroot atins? | Status | Acțiune |
|---|---|---|---|
| Lock conflict | Nu | `failed` / skip | Mesaj „deploy already running” |
| Fetch/auth eșuat | Nu | `failed` | Unlock; păstrează release curent |
| Install exit ≠ 0 | Nu | `failed` | Unlock; păstrează release curent |
| Copy release eșuat | Nu | `failed` | Șterge release incomplet |
| Switch atomic eșuat | Posibil inconsistent | `failed` | Încearcă revert symlink la previous |
| Healthcheck eșuat | Da, apoi rollback | `failed_rolled_back` | Switch înapoi la release anterior |
| Debounce (same commit) | Nu | `success` (noop) | Unlock rapid |

---

## 7. Compatibilitate & mentenanță

- Structura nouă (`git-src/`, `git-deploy/`) e izolată de directoarele gestionate nativ de Hestia
- Integrarea `public_html` ca symlink trebuie verificată cu template-urile web/proxy existente (Apache/Nginx); dacă symlink-ul pe `public_html` e problematic pe o platformă, fallback: rsync final din `current/` în `public_html/` **după** build (tot pe baza release-ului deja validat), păstrând rollback din releases
- UI ca tab/pagină nouă, fără rescrierea template-urilor core ale listărilor existente (hook Pluginable / custom page)
- La `v-delete-web-domain`, cleanup obligatoriu al artefactualelor git-deploy

### 7.1 Dependențe pe host

Documentate la instalarea pluginului: `git`, și opțional `composer` / `node`/`npm` în PATH-ul vizibil userilor jailati (sau wrapper-e explicite). Fără acestea, `INSTALL_CMD` tipic eșuează predictibil cu mesaj în log.

---

## 8. Roadmap

**Faza 1 — MVP (CLI)**
- `v-plugin-git-add`, `deploy`, `rollback`, `list`, `delete`, `key-generate`
- config, deploy key, `known_hosts`, `status.json`
- lock, timeout, EXCLUDE, validare `OUTPUT_DIR`
- releases + switch atomic + cleanup `MAX_RELEASES`
- deploy manual din CLI

**Faza 2 — Webhook**
- endpoint pe panel + HMAC + filtrare branch
- async (`systemd-run` / `at`) + debounce pe commit
- auto-deploy la push

**Faza 3 — UI**
- tab Git Deploy: configurare, deploy, rollback, istoric, secret shown-once, secrets mascate

**Faza 4 — Robustețe**
- healthcheck + auto-rollback
- `secrets.env` UI polish, AUTH https+token
- `GIT_SUBMODULES`, resource limits (nice/cgroup)
- notificări email la eșec via `v-send-mail-to-user` (Hestia existent)
- webhook extern opțional pentru notificări

---

## 9. Decizii pe întrebările deschise

| Întrebare | Decizie |
|---|---|
| Multi-branch pe același domeniu? | **Nu în MVP.** 1 domeniu ↔ 1 branch. Staging = al doilea domeniu. |
| Notificări la eșec? | **Email Hestia** (`v-send-mail-to-user`) în Faza 4; webhook extern ulterior. |
| Limită spațiu repo/build? | Respectă **quota user** Hestia; `MAX_RELEASES` + cleanup; UI warning că `git-src` + `node_modules` consumă quota. |

---

## 10. Criterii de acceptare MVP (Faza 1)

1. `v-plugin-git-add` creează structura, cheia SSH și config valid.
2. `v-plugin-git-deploy` aduce un site static/build (ex. `OUTPUT_DIR=dist`) live fără a lăsa `.git` în webroot.
3. Eșec la `INSTALL_CMD` lasă site-ul vechi intact.
4. Al doilea deploy concurent pe același domeniu e respins de lock.
5. `v-plugin-git-rollback` restaurează release-ul anterior fără rețea.
6. `v-plugin-git-list ... json` expune status consumabil de UI.
7. Ștergerea domeniului (sau `v-plugin-git-delete`) nu lasă chei/secrets orfane.
