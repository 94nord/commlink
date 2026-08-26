COMPOSE = docker compose
CERT_DIR = server/docker/nginx/certs

.PHONY: install dev stop build unit test test-ui certs app-install app-dev devtools

install: app-install ## First-time setup: web/app deps + Playwright browser
	cd web && npm install
	npm install
	npx playwright install chromium

dev: certs ## Start the dev stack (https://localhost:8443 API, http://localhost:5173 web)
	$(COMPOSE) --profile dev up -d --build

stop: ## Stop the dev stack
	$(COMPOSE) --profile dev down

build: ## Build web/ production assets
	cd web && npm run build

unit: ## Fast PHPUnit suite (SQLite in-memory, no containers)
	cd server && php artisan test

test: ## Full E2E cycle: test stack (built by Docker) → Playwright → teardown
	npx playwright test; status=$$?; $(COMPOSE) --profile test down -v; exit $$status

test-ui: ## Interactive Playwright UI; leaves the test stack running
	npx playwright test --ui

# The Expo dev server stays on the host, deliberately unlike web/: it needs
# LAN reachability for physical devices and the native toolchain (Simulator,
# adb) that lives outside Docker. Point it at the API with EXPO_PUBLIC_API_URL
# in app/.env — see app/.env.example for the value per target.
app-install: ## app/ npm dependencies
	cd app && npm install

app-dev: ## Expo dev server for app/ (runs on the host, not in Docker)
	cd app && npx expo start

# Fakes a customer texting or emailing in against the dev stack's API
# (routes/dev.php, local-environment only) — host-run for the same reason
# as app/: it's a separate small app, not part of the product.
devtools: ## Dev inbound simulator UI (http://localhost:5174), needs `make dev` running
	cd devtools && npm install && npm run dev

certs: $(CERT_DIR)/localhost.crt ## Locally-trusted TLS certificate (mkcert) for dev nginx

$(CERT_DIR)/localhost.crt:
	mkdir -p $(CERT_DIR)
	mkcert -install
	mkcert -cert-file $(CERT_DIR)/localhost.crt \
		-key-file $(CERT_DIR)/localhost.key \
		localhost 127.0.0.1
