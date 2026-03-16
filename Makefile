# ────────────────────────────────────────────────────────────────
# Makefile — Development workflow for wp-caroline-trinel
# All commands run inside Docker. No local PHP required.
# ────────────────────────────────────────────────────────────────

COMPOSE = docker compose --env-file .env.development -f docker-compose.dev.yml
APP     = $(COMPOSE) exec app
DB      = $(COMPOSE) exec db

.DEFAULT_GOAL := help

# ── Lifecycle ───────────────────────────────────────────────────

.PHONY: build
build: ## Build the dev image (builds production first, then dev on top)
	docker build -t wp-caroline-trinel-app:latest -f Dockerfile .
	$(COMPOSE) build

.PHONY: up
up: build ## Start the dev stack
	$(COMPOSE) up -d
	@echo ""
	@echo "✔ Stack is running:"
	@echo "  WordPress  → http://localhost:$${APP_PORT:-8080}"
	@echo "  Mailpit    → http://localhost:$${MAILPIT_PORT:-8025}"
	@echo ""

.PHONY: down
down: ## Stop the dev stack
	$(COMPOSE) down

.PHONY: restart
restart: ## Restart the dev stack
	$(COMPOSE) restart

.PHONY: logs
logs: ## Tail all container logs
	$(COMPOSE) logs -f

.PHONY: clean
clean: ## Remove containers, volumes, and built images (full reset)
	$(COMPOSE) down -v --rmi local --remove-orphans

# ── Shell access ────────────────────────────────────────────────

.PHONY: shell
shell: ## Open a shell in the app container
	$(APP) sh

.PHONY: db-shell
db-shell: ## Open a MariaDB shell
	$(DB) mariadb -u $${DB_USER:-wordpress} -p$${DB_PASSWORD:-wordpress} $${DB_NAME:-wordpress}

# ── PHP tooling (runs inside the container) ─────────────────────

.PHONY: install
install: ## Run composer install
	$(APP) composer install

.PHONY: composer
composer: ## Run a Composer command — usage: make composer c="require foo/bar"
	$(APP) composer $(c)

.PHONY: wp
wp: ## Run a WP-CLI command — usage: make wp c="plugin list"
	$(APP) wp --allow-root $(c)

.PHONY: lint
lint: ## Check code style (Laravel Pint)
	$(APP) composer lint

.PHONY: lint-fix
lint-fix: ## Auto-fix code style (Laravel Pint)
	$(APP) composer lint:fix

.PHONY: test
test: ## Run tests (Pest)
	$(APP) composer test

# ── Database ────────────────────────────────────────────────────

.PHONY: db-export
db-export: ## Export the database to db-dump.sql
	$(DB) mariadb-dump -u $${DB_USER:-wordpress} -p$${DB_PASSWORD:-wordpress} $${DB_NAME:-wordpress} > db-dump.sql
	@echo "Database exported to db-dump.sql"

.PHONY: db-import
db-import: ## Import db-dump.sql into the database
	$(DB) mariadb -u $${DB_USER:-wordpress} -p$${DB_PASSWORD:-wordpress} $${DB_NAME:-wordpress} < db-dump.sql
	@echo "Database imported from db-dump.sql"

# ── Remote (Coolify production) ─────────────────────────────────

REMOTE_SHELL = ./scripts/remote-shell.sh
REMOTE_DB    = ./scripts/remote-db.sh

.PHONY: remote-shell
remote-shell: ## Open a shell in the remote WordPress container
	$(REMOTE_SHELL) app

.PHONY: remote-db-shell
remote-db-shell: ## Open a shell in the remote MariaDB container
	$(REMOTE_SHELL) db

.PHONY: remote-db-export
remote-db-export: ## Export the remote database to remote-db-dump.sql
	$(REMOTE_DB) export

.PHONY: remote-db-import
remote-db-import: ## Import remote-db-dump.sql into the remote database
	$(REMOTE_DB) import

.PHONY: remote-logs
remote-logs: ## Tail logs of the remote WordPress container
	$(REMOTE_SHELL) --logs app

# ── Content migration (bidirectional) ───────────────────────────

CONTENT_MIGRATE = ./scripts/content-migrate.sh

.PHONY: content-push
content-push: ## Push local content → production (full: export + uploads + import)
	$(CONTENT_MIGRATE) push full

.PHONY: content-pull
content-pull: ## Pull production content → local (full: export + uploads + import)
	$(CONTENT_MIGRATE) pull full

.PHONY: content-push-export
content-push-export: ## Export local content → content-export/
	$(CONTENT_MIGRATE) push export

.PHONY: content-push-import
content-push-import: ## Import content-export/ → production (with URL search-replace)
	$(CONTENT_MIGRATE) push import

.PHONY: content-push-uploads
content-push-uploads: ## Sync local uploads (media) → production
	$(CONTENT_MIGRATE) push sync-uploads

.PHONY: content-pull-export
content-pull-export: ## Export production content → content-export/
	$(CONTENT_MIGRATE) pull export

.PHONY: content-pull-import
content-pull-import: ## Import content-export/ → local (with URL search-replace)
	$(CONTENT_MIGRATE) pull import

.PHONY: content-pull-uploads
content-pull-uploads: ## Sync production uploads (media) → local
	$(CONTENT_MIGRATE) pull sync-uploads

# ── Help ────────────────────────────────────────────────────────

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'
