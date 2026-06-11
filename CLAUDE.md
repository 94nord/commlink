# Inspection Book

Self-service booking tool for trade inspections: an inspector's company
(corporation) sets up a project, registers apartments and their contacts, opens
an examination (a round of inspections), and each contact books their own slot
via a personal link in their email. Product decisions live in
`docs/product.md` (D1–D16) — consult them before adding features.

## Stack

Laravel 12 · Inertia 2 · React 19 · Tailwind 4 · Vite · PostgreSQL · Docker

Server-driven SPA: routing and page data come from Laravel, no separate API.
Object storage is S3-compatible everywhere (MinIO in dev, managed bucket in
prod) behind Flysystem. Deployed with Dokploy.

## Commands

| Command      | What it does                                              |
|--------------|-----------------------------------------------------------|
| `make install` | npm dependencies + Playwright browser                   |
| `make dev`     | dev stack up (builds images, generates dev TLS certs)   |
| `make stop`    | dev stack down                                           |
| `make build`   | Vite production build                                    |
| `make unit`    | PHPUnit on SQLite in-memory — fast, no containers       |
| `make test`    | full E2E cycle: build → test stack → Playwright → down  |
| `make test-ui` | Playwright UI mode, leaves the test stack running       |

Artisan inside the dev stack: `docker compose exec app-dev php artisan …`

## Environments & ports

One multi-stage `Dockerfile` (frontend → app/php-fpm → web/nginx), three
Compose profiles in `docker-compose.yml`:

| Surface              | Dev                 | Test            |
|----------------------|---------------------|-----------------|
| App (HTTPS / HTTP)   | 8443 / 8080         | 9080 (HTTP)     |
| Vite HMR             | 5173                | —               |
| PostgreSQL           | 5432                | 5433            |
| MinIO API / console  | 9000 / 9001         | —               |

Dev and test stacks run concurrently. The test stack fresh-migrates an empty
database on every boot (`docker/entrypoint-test.sh`); prod caches
config/routes/views (`docker/entrypoint-prod.sh`).

## Tenancy & auth (current slice)

- `Corporation` owns users; `users.corporation_id` is non-nullable (D1).
- Everyone in a corporation is an admin — no roles yet (D2).
- No self-serve signup (D3): public registration is removed. Provision
  customers with `php artisan app:create-corporation`; dev seeding reads
  `SEED_*` vars from `.env` (`php artisan db:seed`).

## Testing

- **PHPUnit** (`make unit`): SQLite in-memory, cheap hashing — the tight loop
  and CI gate.
- **Playwright** (`make test`): real browser against the containerized test
  stack. Backend state is controlled via test-only endpoints (`/_test/reset`,
  `/_test/seed` in `routes/testing.php`), loaded **only** in the testing
  environment. Auth is established once in `tests/e2e/auth.setup.ts` and
  reused via stored session state.

## Conventions

- No hardcoded secrets anywhere — env vars or runtime generation only;
  `.env.example` documents every variable with safe placeholders.
- PostgreSQL in every environment; SQLite only inside PHPUnit.
- Same Dockerfile for all environments; differences live in Compose profiles,
  entrypoints, and mounts — never forked Dockerfiles.
