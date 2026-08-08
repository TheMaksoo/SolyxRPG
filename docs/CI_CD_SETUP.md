# CI/CD Setup Guide

This document explains how the automated deployment pipeline works for Solyx RPG.

---

## Overview

| Environment | Domain | Branch | Deploy trigger |
|---|---|---|---|
| **Dev** | `dev.solyx.gg` | `dev` | Automatic — any push/merge to `dev` |
| **Live** | `play.solyx.gg` | `main` | Manual — GM clicks "Run workflow" in GitHub Actions |

---

## Branch Strategy

- `main` → live production (`play.solyx.gg`)
- `dev` → dev/test environment (`dev.solyx.gg`)
- Feature branches → always branch off `main`, PR into `dev` first

```
main ──────────────────────────────────────── (live)
  │
  └── feature/my-feature ──► PR → dev ──► test ──► PR → main ──► deploy live
```

---

## Workflows

### 1. `ci.yml` — Continuous Integration

**Triggers:** Every PR opened against `dev` or `main`

**Steps:**
1. PHPUnit tests (SQLite in-memory)
2. Vite frontend build
3. Laravel Pint code style check
4. PHPStan static analysis (level 5)

**PRs are blocked from merging if CI fails.** Set this up via GitHub branch protection rules (see below).

---

### 2. `deploy-dev.yml` — Auto-deploy to Dev

**Triggers:** Any push to `dev` (i.e. after a PR is merged)

**Steps:**
1. SSH into dev server
2. `git pull origin dev`
3. `composer install`
4. `npm ci && npm run build`
5. `php artisan down`
6. `php artisan migrate --force`
7. `php artisan db:seed --class=ChangelogSeeder --force`
8. Clear & rebuild all caches
9. `php artisan queue:restart`
10. `php artisan up`

---

### 3. `deploy-live.yml` — Manual Live Deploy ("Update Live")

**Triggers:** Manual — GM goes to **GitHub Actions → Deploy to Live → Run workflow**, types `deploy` to confirm

**Requires:** GitHub `production` environment approval (configure in repo Settings → Environments)

Same steps as dev deploy but targets the live server (`main` branch, `/var/www/RPG`).

---

### 4. `auto-changelog.yml` — Auto Changelog

**Triggers:** Every PR merged into `main` (or `master`)

**What it does:**
- Reads the PR title, body, labels, and diff stat
- Calls OpenAI (`OPENAI_API_KEY` secret) to classify the change and draft a changelog entry —
  tag (`feature`/`fix`/`balance`/`misc`), visibility (`player`/`tester`/`gm`), title, and body
- Computes the next version (feature → minor bump, everything else → patch bump off the current minor)
- Appends the entry to `database/seeders/ChangelogSeeder.php` and commits + pushes directly back to
  the base branch

**Override labels:** `changelog:feature` / `changelog:fix` / `changelog:balance` / `changelog:misc` and
`audience:player` / `audience:tester` / `audience:gm` are passed to the model as hints; `changelog:skip`
skips the whole step for that PR.

Once this repo has a `dev` branch, retarget this workflow's trigger (or add a second one) to fire on
merges into `dev` instead of/alongside `main`, matching this doc's branch strategy above.

---

## Server Setup

### Required GitHub Secrets

Configure these in **Settings → Secrets and variables → Actions**:

#### `development` environment secrets:
| Secret | Value |
|---|---|
| `DEV_SSH_HOST` | Server IP or hostname |
| `DEV_SSH_USER` | SSH username (e.g. `deploy`) |
| `DEV_SSH_KEY` | Private SSH key (generate a dedicated deploy key) |
| `DEV_SSH_PORT` | SSH port (default: `22`) |
| `DEV_APP_PATH` | Path to the dev app folder (e.g. `/var/www/RPGDEV`) |

#### `production` environment secrets:
| Secret | Value |
|---|---|
| `PROD_SSH_HOST` | Server IP or hostname |
| `PROD_SSH_USER` | SSH username |
| `PROD_SSH_KEY` | Private SSH key |
| `PROD_SSH_PORT` | SSH port (default: `22`) |
| `PROD_APP_PATH` | Path to the live app folder (e.g. `/var/www/RPG`) |

### Generating a Deploy Key

```bash
# On your local machine
ssh-keygen -t ed25519 -C "github-deploy@solyx.gg" -f deploy_key

# Add the PUBLIC key to the server's authorized_keys
cat deploy_key.pub >> ~/.ssh/authorized_keys

# Add the PRIVATE key as the DEV_SSH_KEY or PROD_SSH_KEY secret in GitHub
cat deploy_key
```

---

### Server: Nginx Vhost for dev.solyx.gg

Add a second server block for the subdomain (alongside your existing `play.solyx.gg` block):

```nginx
server {
    listen 80;
    server_name dev.solyx.gg;
    return 301 https://dev.solyx.gg$request_uri;
}

server {
    listen 443 ssl;
    server_name dev.solyx.gg;

    ssl_certificate     /etc/letsencrypt/live/dev.solyx.gg/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/dev.solyx.gg/privkey.pem;

    root /var/www/RPGDEV/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Then get a TLS certificate:
```bash
certbot certonly --nginx -d dev.solyx.gg
```

---

### Server: Dev `.env` file

The dev `.env` (`/var/www/RPGDEV/.env`) should have:

```env
APP_ENV=dev
APP_URL=https://dev.solyx.gg
TESTER_REGISTRATION=true
SESSION_DOMAIN=.solyx.gg
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=dev.solyx.gg

DB_DATABASE=solyxrpg_dev
# ... rest of your settings
```

The live `.env` should have:
```env
APP_ENV=production
APP_URL=https://play.solyx.gg
TESTER_REGISTRATION=false
```

---

## Branch Protection Rules

Configure these in **Settings → Branches**:

### `main` branch
- ✅ Require a pull request before merging
- ✅ Require status checks to pass: `Tests & Code Quality` (ci.yml)
- ✅ Require branches to be up to date before merging
- ✅ Restrict who can push to `main` (GM only)

### `dev` branch
- ✅ Require a pull request before merging
- ✅ Require status checks to pass: `Tests & Code Quality` (ci.yml)

---

## GitHub Environments

Configure in **Settings → Environments**:

### `development`
- No approval required (auto-deploys)
- Restrict to `dev` branch

### `production`
- ✅ Required reviewers: add GM GitHub accounts
- Restrict to `main` branch

---

## Code Quality

### Running locally

```bash
# Run all tests
php artisan test

# Check code style (auto-fix)
vendor/bin/pint

# Check code style (dry run, same as CI)
vendor/bin/pint --test

# Run static analysis
vendor/bin/phpstan analyse
```

### PHPStan levels

Current level: **5** (set in `phpstan.neon`). This catches:
- Type mismatches
- Possibly null dereferences
- Unknown methods/properties
- Dead code branches

Raise to level 6 or 7 as the codebase grows and existing issues are resolved.

To generate a baseline (ignore existing issues, only fail on new ones):
```bash
vendor/bin/phpstan analyse --generate-baseline
```

---

## Tester Registration

The `TESTER_REGISTRATION` env flag controls whether new accounts can be created:

- `true` → Registration is open (default on dev)
- `false` → Registration returns a `403` with an invite-only message (set on live)

This is checked in `AuthController::register()` before any validation runs.
