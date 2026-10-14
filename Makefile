.PHONY: up down shell htop build install test analyse format format-check run-server bench bench-wikipedia

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

# The baseline on a synthetic corpus: SECONDS per profile (default 8).
bench: up
	docker compose exec $(TTY) -e XDEBUG_MODE=off php bash -c "php benchmarks/corpus-synthetic.php 10000 > /tmp/synthetic.jsonl && php -d memory_limit=-1 benchmarks/run.php /tmp/synthetic.jsonl $(or $(SECONDS),8)"

# The same on 10,000 Wikipedia introductions, downloaded once into benchmarks/data/ (never committed).
bench-wikipedia: up
	docker compose exec $(TTY) -e XDEBUG_MODE=off php bash -c "mkdir -p benchmarks/data && test -s benchmarks/data/wikipedia.jsonl || php benchmarks/corpus-wikipedia.php 10000 > benchmarks/data/wikipedia.jsonl; php -d memory_limit=-1 benchmarks/run.php benchmarks/data/wikipedia.jsonl $(or $(SECONDS),8)"

format: up
	docker compose exec $(TTY) php composer format

# check reports without touching anything (what CI runs)
format-check: up
	docker compose exec $(TTY) php composer format:check
