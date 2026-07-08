COMPOSE = docker compose
CERT_DIR = docker/nginx/certs

.PHONY: install dev stop build unit test test-ui certs

install: ## First-time setup: frontend dependencies + Playwright browser
	npm install
	npx playwright install chromium

dev: certs ## Start the dev stack (https://localhost:8443)
	$(COMPOSE) --profile dev up -d --build

stop: ## Stop the dev stack
	$(COMPOSE) --profile dev down

build: ## Build frontend production assets
	npm run build

unit: ## Fast PHPUnit suite (SQLite in-memory, no containers)
	php artisan test

test: build ## Full E2E cycle: assets → test stack → Playwright → teardown
	npx playwright test; status=$$?; $(COMPOSE) --profile test down -v; exit $$status

test-ui: build ## Interactive Playwright UI; leaves the test stack running
	npx playwright test --ui

certs: $(CERT_DIR)/localhost.crt ## Locally-trusted TLS certificate (mkcert) for dev nginx + Vite

$(CERT_DIR)/localhost.crt:
	mkdir -p $(CERT_DIR)
	mkcert -install
	mkcert -cert-file $(CERT_DIR)/localhost.crt \
		-key-file $(CERT_DIR)/localhost.key \
		localhost 127.0.0.1
