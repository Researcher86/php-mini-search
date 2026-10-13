.PHONY: up down shell htop build install test analyse format format-check run-server

# CI has no terminal to attach to (GitHub Actions sets CI=true), so exec runs
# without one there; locally it keeps the TTY for colours and interactive use.
TTY := $(if $(CI),-T,)

# A token for local runs only; the service refuses to start without one of
# at least 32 bytes. Override it: make run-server TOKEN=...
TOKEN ?= demo-token-demo-token-demo-token-0

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

# Logs one JSON line per event to the terminal; Ctrl-C drains, a second Ctrl-C forces.
run-server: up
	docker compose exec $(TTY) -e MINI_SEARCH_TOKEN=$(TOKEN) php php bin/mini-search serve

format: up
	docker compose exec $(TTY) php composer format

# check reports without touching anything (what CI runs)
format-check: up
	docker compose exec $(TTY) php composer format:check
