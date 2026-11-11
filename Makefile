.DEFAULT_GOAL := help
COMPOSE := docker compose
APP     := $(COMPOSE) exec app

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

setup: ## First run: build, start, install, migrate and seed
	./bin/setup

up: ## Start the stack
	$(COMPOSE) up -d

down: ## Stop the stack (data volumes are kept)
	$(COMPOSE) down

ps: ## Show service status and health
	$(COMPOSE) ps

logs: ## Follow logs of all services
	$(COMPOSE) logs -f --tail=100

shell: ## Open a shell in the app container
	$(APP) bash

migrate: ## Run migrations
	$(APP) php artisan migrate

fresh: ## Rebuild the schema from scratch (dev only, before the first deploy)
	$(APP) php artisan migrate:fresh --seed

seed: ## Run the (idempotent) seeders
	$(APP) php artisan db:seed --force

partitions: ## List audit_logs partitions and add upcoming months
	$(APP) php artisan audit:partitions

test: ## Run the test suite (against the audit_testing database)
	$(APP) php artisan test

lint: ## Check code style with Pint
	$(APP) ./vendor/bin/pint --test

analyse: ## Static analysis with Larastan
	$(APP) ./vendor/bin/phpstan analyse --memory-limit=1G

schema-dump: ## Write database/schema/schema.sql from the live database
	./bin/schema-dump

doctor: ## Run every check and save the report to storage/logs/doctor.log
	./bin/doctor

backup-full: ## Take a full database backup now
	$(COMPOSE) --profile backup run --rm backup full

.PHONY: help setup up down ps logs shell migrate fresh seed partitions test lint analyse schema-dump doctor backup-full
