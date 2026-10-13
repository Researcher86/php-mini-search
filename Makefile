.PHONY: up down shell htop build install test analyse format format-check

# CI has no terminal to attach to (GitHub Actions sets CI=true), so exec runs
# without one there; locally it keeps the TTY for colours and interactive use.
TTY := $(if $(CI),-T,)

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell: up
	docker compose exec $(TTY) php bash

htop: up
	docker compose exec $(TTY) php htop

install: up
	docker compose exec $(TTY) php composer install

test: up
	docker compose exec $(TTY) php composer test

analyse: up
	docker compose exec $(TTY) php composer analyse

format: up
	docker compose exec $(TTY) php composer format

# check reports without touching anything (what CI runs)
format-check: up
	docker compose exec $(TTY) php composer format:check
