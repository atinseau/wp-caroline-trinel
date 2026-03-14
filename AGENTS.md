# AGENTS.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress site built on [Roots Bedrock](https://roots.io/bedrock/) — a modern WordPress boilerplate with Composer dependency management, environment-based configuration, and a clean folder structure separating WordPress core from application code. Containerised with Docker and deployed on [Coolify](https://coolify.io/).

## Commands

All commands run inside Docker via the Makefile. **No local PHP installation required.**

```bash
make up                # Start the dev stack
make down              # Stop the dev stack
make restart           # Restart the dev stack
make logs              # Tail all container logs
make shell             # Open a shell in the app container
make composer c="..."  # Run a Composer command
make wp c="..."        # Run a WP-CLI command
make install           # Run composer install
make lint              # Check code style (Laravel Pint)
make lint-fix          # Auto-fix code style
make test              # Run tests (Pest)
make db-shell          # Open a MariaDB shell
make db-export         # Export the database to db-dump.sql
make db-import         # Import db-dump.sql into the database
make clean             # Remove containers, volumes, images (full reset)
make help              # Show all available commands
```

## Architecture

```
config/
├── application.php          # Main config: DB, URLs, auth keys, WP settings
└── environments/
    ├── development.php      # Debug on, SAVEQUERIES, allows file mods
    ├── staging.php          # Indexing disabled, otherwise production-like
    └── production.php       # WP_DEBUG off, indexing enabled

web/
├── index.php                # Entry point
├── wp-config.php            # Bootstraps vendor/autoload.php + config/application.php
├── app/                     # WordPress content directory (wp-content equivalent)
│   ├── mu-plugins/          # Must-use plugins (auto-loaded via bedrock-autoloader)
│   │   ├── bedrock-autoloader.php
│   │   └── smtp-mailer.php  # Routes emails via SMTP when SMTP_HOST is set
│   ├── plugins/             # Composer-managed plugins
│   └── themes/              # Themes (custom themes go here)
└── wp/                      # WordPress core (managed by Composer, do not edit)

docker/
├── nginx.conf               # Nginx vhost (root: /var/www/html/web)
├── supervisord.conf         # Runs php-fpm + nginx inside a single container
└── dev-entrypoint.sh        # Auto composer install + permissions on first run

tests/
└── Feature/                 # Pest feature tests
```

**Key conventions:**

- All plugins and themes are managed via Composer using `wpackagist`. WordPress core is installed at `web/wp/` and never modified directly.
- The Makefile is the single entry point for all development commands. It passes `--env-file .env.development` to Docker Compose.

## Environment Setup

**Local development:** No `.env` file needed. All dev variables live in `.env.development` (committed to git, source of truth). The Makefile passes it to Docker Compose via `--env-file .env.development` so values are available for both YAML interpolation and container injection. Just run `make up`.

**Production/staging:** Copy `.env.example` to `.env` and fill in:

- `DB_NAME`, `DB_USER`, `DB_PASSWORD` (or use `DATABASE_URL` as a DSN)
- `WP_HOME` — full site URL (e.g. `https://example.com`)
- `WP_SITEURL` — must be `${WP_HOME}/wp`
- Auth keys/salts — generate at https://roots.io/salts/
- `WP_ENV` — `development`, `staging`, or `production`

## Code Style

Laravel Pint uses the **PER** preset. Excluded from linting:

- `web/wp/` (WordPress core)
- `web/app/plugins/` (third-party plugins)
- `web/app/themes/twentytwentyfive/` (default theme)

Custom theme and plugin code in `web/app/` should be linted.

## Docker

The project uses a single multi-stage `Dockerfile` with two targets:

- **`production`** — Optimised image for deployment (Composer deps baked in, no dev tools)
- **`development`** — Extends `production` with Composer, WP-CLI, Xdebug, and git

### Key Files

| File | Role |
|---|---|
| `Dockerfile` | Multi-stage: `production` target + `development` target on top |
| `docker-compose.yml` | Production stack (Coolify deployment) |
| `docker-compose.dev.yml` | Development stack (extends production via `extends:`) |
| `.dockerignore` | Keeps build context lean |
| `.env.development` | Dev variables — source of truth, committed to git |
| `.env.example` | Template for production/staging (empty values) |
| `Makefile` | Developer commands — all run inside Docker |

### Development

The dev stack reproduces the production environment exactly, with dev tools layered on top.

**Identical to production:** PHP 8.3-FPM, Nginx config, all extensions (gd, imagick, intl, mysqli, opcache, zip, bcmath, exif), supervisord, Alpine base.

**Added for dev:** Composer, WP-CLI, Xdebug (trigger mode), git.

**No `.env` file required.** `.env.development` is loaded by the Makefile via `--env-file` and by the `app` service via `env_file:`. Bedrock's `config/application.php` skips `.env` loading when the file doesn't exist and uses the environment variables injected by Docker Compose.

#### Quick Start

```bash
git clone <repo>
cd wp-caroline-trinel
make up
# → WordPress at http://localhost:8080
# → Mailpit at http://localhost:8025
```

#### Hot Reload

The source code is bind-mounted (`.:/var/www/html`), so any file change is reflected immediately — no rebuild needed.

| What hot-reloads | What needs an action |
|---|---|
| PHP files (themes, plugins, config) | `make composer c="require ..."` for new packages |
| CSS / JS (browser refresh) | `make restart` after `.env.development` changes |
| Templates | `make install` after pulling new `composer.lock` |

#### Services

| Service | Port | Description |
|---|---|---|
| **app** | `8080` | WordPress (PHP-FPM + Nginx) |
| **db** | — | MariaDB 11 (internal only) |
| **mailpit** | `8025` | Email capture (SMTP on port 1025 internally) |

#### Xdebug

Xdebug is installed in trigger mode (`xdebug.start_with_request=trigger`). It does not slow down normal requests. To activate it, use a browser extension that sets the `XDEBUG_TRIGGER` cookie, or append `?XDEBUG_TRIGGER=1` to any URL.

- Host: `host.docker.internal`
- Port: `9003`

#### Mailpit

All WordPress emails are routed through Mailpit in development via the `smtp-mailer.php` mu-plugin. The plugin activates automatically when the `SMTP_HOST` environment variable is set (injected by `docker-compose.dev.yml`). In production, `SMTP_HOST` is not set, so the plugin does nothing.

### Deployment (Coolify)

The production stack is containerised for deployment on [Coolify](https://coolify.io/).

#### Required Environment Variables

All variables below must be set in Coolify's service environment:

| Variable | Example | Notes |
|---|---|---|
| `DB_NAME` | `wordpress` | Defaults to `wordpress` |
| `DB_USER` | `wordpress` | Defaults to `wordpress` |
| `DB_PASSWORD` | *(secret)* | **Required** — shared by app & db |
| `DB_ROOT_PASSWORD` | *(secret)* | Falls back to `DB_PASSWORD` |
| `WP_HOME` | `https://example.com` | **Required** — full site URL |
| `WP_SITEURL` | `${WP_HOME}/wp` | Defaults to `${WP_HOME}/wp` |
| `WP_ENV` | `production` | `production`, `staging`, or `development` |
| `AUTH_KEY` | *(generate)* | **Required** — generate at https://roots.io/salts/ |
| `SECURE_AUTH_KEY` | *(generate)* | **Required** |
| `LOGGED_IN_KEY` | *(generate)* | **Required** |
| `NONCE_KEY` | *(generate)* | **Required** |
| `AUTH_SALT` | *(generate)* | **Required** |
| `SECURE_AUTH_SALT` | *(generate)* | **Required** |
| `LOGGED_IN_SALT` | *(generate)* | **Required** |
| `NONCE_SALT` | *(generate)* | **Required** |

#### Volumes

- **`db_data`** — MariaDB data persistence
- **`uploads`** — `web/app/uploads/` (WordPress media)

#### Coolify Setup

1. Create a new **Docker Compose** resource in Coolify pointing to this repo.
2. Set all required environment variables in the Coolify UI.
3. Coolify handles TLS termination via its built-in reverse proxy — the container only exposes port 80.
4. `DB_HOST` is set to `db:3306` automatically in `docker-compose.yml`; do **not** override it unless you use an external database.

#### Container Architecture

The `app` container bundles **PHP-FPM 8.3** and **Nginx** managed by **supervisord** in a single Alpine-based image. The `development` target extends this exact image with dev tools (Composer, WP-CLI, Xdebug, git) — ensuring dev and production environments are identical at the runtime level.