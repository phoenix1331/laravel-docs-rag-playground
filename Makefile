.DEFAULT_GOAL := help
.PHONY: help up down build shell migrate seed tinker test test-pgsql lint lint-test logs fresh

EXEC = docker compose exec app

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Start all containers in the background
	@docker compose up -d

down: ## Stop and remove containers
	@docker compose down

build: ## Build the app image
	@docker compose build

shell: ## Open a shell in the app container
	@$(EXEC) bash

tinker: ## Open tinker in the app container
	@docker compose exec -e XDG_CONFIG_HOME=/tmp app php artisan tinker

migrate: ## Run database migrations
	@$(EXEC) php artisan migrate

seed: ## Seed the database
	@$(EXEC) php artisan db:seed

test: ## Run the test suite (SQLite in memory)
	@$(EXEC) php artisan test

test-pgsql: ## Run Postgres-only tests against laravel_testing
	@$(EXEC) env DB_CONNECTION=pgsql DB_DATABASE=laravel_testing php artisan test --group=pgsql

lint: ## Fix code style with Pint
	@$(EXEC) ./vendor/bin/pint

lint-test: ## Check code style without changing files
	@$(EXEC) ./vendor/bin/pint --test

logs: ## Tail app container logs
	@docker compose logs -f app

fresh: ## Drop all tables, re-run migrations and seed
	@$(EXEC) php artisan migrate:fresh --seed
