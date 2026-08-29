# Slimmer — Makefile
.PHONY: help build test test-filter shell clean version-major version-minor version-patch

# Defaults
COMPOSE  := docker compose
SERVICE  := test
FILTER   ?=
LOG_FILE ?= test.log

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# Docker image
build: ## Build (or rebuild) the Docker image
	$(COMPOSE) build --pull $(SERVICE)

# Test targets
test: ## Run the full test suite (builds image if needed), logging output to LOG_FILE (default: test.log)
	@rm -f $(LOG_FILE)
	@bash -o pipefail -c '$(COMPOSE) run --rm $(SERVICE) 2>&1 | tee $(LOG_FILE)'

test-filter: ## Run tests matching FILTER=<pattern> (e.g. make test-filter FILTER=PdfOptimizer), logging output to LOG_FILE
	@rm -f $(LOG_FILE)
	@bash -o pipefail -c '$(COMPOSE) run --rm $(SERVICE) \
		vendor/bin/pest --configuration phpunit.xml --filter "$(FILTER)" 2>&1 | tee $(LOG_FILE)'

# Dev helpers
shell: ## Open a bash shell inside the test container
	$(COMPOSE) run --rm --entrypoint /bin/sh $(SERVICE)

clean: ## Remove containers, volumes, and the built image
	$(COMPOSE) down --volumes --rmi local

# Versioning
version-major: ## Create a new MAJOR git tag (e.g. v1.0.0 -> v2.0.0)
	@git fetch --tags --quiet
	@LAST_TAG=$$(git tag --sort=-v:refname | grep -E '^v?[0-9]+\.[0-9]+\.[0-9]+$$' | head -n 1); \
	if [ -z "$$LAST_TAG" ]; then \
		NEXT_TAG="v1.0.0"; \
	else \
		NEXT_TAG=$$(echo "$$LAST_TAG" | sed 's/^v//' | awk -F. '{print "v"$$1+1".0.0"}'); \
	fi; \
	git tag -a "$$NEXT_TAG" -m "Release MAJOR $$NEXT_TAG" && \
	echo "New MAJOR version created locally: $$NEXT_TAG (Do not forget to run: git push origin $$NEXT_TAG)"

version-minor: ## Create a new MINOR git tag (e.g. v0.1.0 -> v0.2.0)
	@git fetch --tags --quiet
	@LAST_TAG=$$(git tag --sort=-v:refname | grep -E '^v?[0-9]+\.[0-9]+\.[0-9]+$$' | head -n 1); \
	if [ -z "$$LAST_TAG" ]; then \
		NEXT_TAG="v0.1.0"; \
	else \
		NEXT_TAG=$$(echo "$$LAST_TAG" | sed 's/^v//' | awk -F. '{print "v"$$1"."$$2+1".0"}'); \
	fi; \
	git tag -a "$$NEXT_TAG" -m "Release MINOR $$NEXT_TAG" && \
	echo "New MINOR version created locally: $$NEXT_TAG (Do not forget to run: git push origin $$NEXT_TAG)"

version-patch: ## Create a new PATCH git tag (e.g. v0.0.1 -> v0.0.2)
	@git fetch --tags --quiet
	@LAST_TAG=$$(git tag --sort=-v:refname | grep -E '^v?[0-9]+\.[0-9]+\.[0-9]+$$' | head -n 1); \
	if [ -z "$$LAST_TAG" ]; then \
		NEXT_TAG="v0.0.1"; \
	else \
		NEXT_TAG=$$(echo "$$LAST_TAG" | sed 's/^v//' | awk -F. '{print "v"$$1"."$$2"."$$3+1}'); \
	fi; \
	git tag -a "$$NEXT_TAG" -m "Release PATCH $$NEXT_TAG" && \
	echo "New PATCH version created locally: $$NEXT_TAG (Do not forget to run: git push origin $$NEXT_TAG)"
