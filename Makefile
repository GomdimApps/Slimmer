# Slimmer — Makefile
.PHONY: help build test test-filter shell clean

# Defaults
COMPOSE  := docker compose
SERVICE  := test
FILTER   ?=

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# Docker image
build: ## Build (or rebuild) the Docker image
	$(COMPOSE) build --pull $(SERVICE)

# Test targets
test: ## Run the full test suite (builds image if needed)
	$(COMPOSE) run --rm $(SERVICE)

test-filter: ## Run tests matching FILTER=<pattern>  (e.g. make test-filter FILTER=PdfOptimizer)
	$(COMPOSE) run --rm $(SERVICE) \
		vendor/bin/pest --configuration phpunit.xml --filter "$(FILTER)"

# Dev helpers
shell: ## Open a bash shell inside the test container
	$(COMPOSE) run --rm --entrypoint /bin/sh $(SERVICE)

clean: ## Remove containers, volumes, and the built image
	$(COMPOSE) down --volumes --rmi local
