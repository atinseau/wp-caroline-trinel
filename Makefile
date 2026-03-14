# ────────────────────────────────────────────────────────────────
# Makefile — Development workflow for wp-caroline-trinel
# All commands run inside Docker. No local PHP required.
# ────────────────────────────────────────────────────────────────

COMPOSE = docker compose --env-file .env.development -f docker-compose.dev.yml
APP     = $(COMPOSE) exec app
DB      = $(COMPOSE) exec db

.DEFAULT_GOAL := help

# ── Lifecycle ───────────────────────────────────────────────────

.PHONY: up
up: ## Start the dev stack
	$(COMPOSE) up -d --build

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

# ── First-time setup ───────────────────────────────────────────

.PHONY: setup
setup: ## First-time project setup: build and start the dev stack
	$(COMPOSE) up -d --build
	@echo ""
	@echo "✔ Stack is running:"
	@echo "  WordPress  → http://localhost:$${APP_PORT:-8080}"
	@echo "  Mailpit    → http://localhost:$${MAILPIT_PORT:-8025}"
	@echo ""

# ── Help ────────────────────────────────────────────────────────

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'
