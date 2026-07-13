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
| Mailpit UI / API     | 8025                | 9025 (API)      |

Dev and test stacks run concurrently. The test stack fresh-migrates an empty
database on every boot (`docker/entrypoint-test.sh`); prod caches
config/routes/views (`docker/entrypoint-prod.sh`).

## Tenancy & auth (current slice)

- `Corporation` owns users; `users.corporation_id` is non-nullable (D1).
- Everyone in a corporation is an admin — no roles yet (D2).
- No self-serve signup (D3): public registration is removed. Provision
  customers with `php artisan app:create-corporation`; dev seeding reads
  `SEED_*` vars from `.env` (`php artisan db:seed`).
- Event spine (X1): record every state change with
  `EventRecorder::record($corporation, 'thing.happened', subject: …)` —
  append-only `events` table, one central log.
- External refs (X2): host-mirrored business entities get
  `$table->externalRef()` in migrations + the `HasExternalReference` trait;
  `external_ref` null means the record stands alone.
- Customer is the durable anchor (spec vocabulary since 2026-06-12; was
  Party). Visits and work orders reference `customer_id`, never an email
  column — resolution is always `Customer::findByEmail()` → customer →
  interactions, so contact identities can move to their own table later
  without touching any relationship. Customers are created/edited on the
  Customers page (name, email, optional `address`) with a detail page
  listing their cases.
- WorkOrder implements the spec's **Case** (`case` is a PHP reserved word;
  table `work_orders` because `jobs` belongs to Laravel's queue): one
  apartment/matter, required `customer_id`, plain `address`, optional
  `title` (shown in lists when set) + `notes`, nullable `project_id`. Cases
  are created and edited on the Cases page (the new-case address prefills
  from the customer's address, still editable); projects and rounds
  **attach existing** cases rather than creating them
  (`ProjectWorkOrderController` sets/clears `project_id`;
  `RoundWorkOrderController` manages membership). Tenant scoping in
  controllers: resolve models through
  `$request->user()->corporation->…()->findOrFail()`, never bare bindings.

## Testing

- **PHPUnit** (`make unit`): SQLite in-memory, cheap hashing — the tight loop
  and CI gate.
- **Playwright** (`make test`): real browser against the containerized test
  stack. Backend state is controlled via test-only endpoints (`/_test/reset`,
  `/_test/seed` in `routes/testing.php`), loaded **only** in the testing
  environment. Auth is established once in `tests/e2e/auth.setup.ts` and
  reused via stored session state.
- The test entrypoint clears `public/hot` (per blueprint); if dev HMR stops
  after `make test`, run `docker compose restart vite`.

## Conventions

- No hardcoded secrets anywhere — env vars or runtime generation only;
  `.env.example` documents every variable with safe placeholders.
- **Never put `prefetch` on Inertia `<Link>`s.** Hover-prefetch races the
  click into two concurrent visits; the late response remounts the page and
  wipes local state (open dialogs close). Verified still broken on
  @inertiajs/react 2.3.27 (2026-07); all `prefetch` props were removed then.
- PostgreSQL in every environment; SQLite only inside PHPUnit.
- Same Dockerfile for all environments; differences live in Compose profiles,
  entrypoints, and mounts — never forked Dockerfiles.

##SCOPE
- Specs found in inspection-booking-design.md file
- Claude code prompts found in claude-code-prompts.md
- Do ONLY what the current task asks. Do not scaffold, stub, or begin later phases.
- When done, stop. Summarize what you built and how to run/verify it, then wait for review.
- Vocabulary map (design doc → code): Customer → `Customer` · Case →
  `WorkOrder` (PHP reserves `case`) · Staff → `Staff` (built; availability
  windows deferred — staff are assumed always available for now, S2 invariant
  enforced: founder provisioned at creation, last member undeletable) ·
  Visit → `Visit` (built; standalone admin-managed, polymorphic owner,
  always scheduled — staff + times required at creation, no unscheduled state;
  soft-cancel via `cancelled_at`; occupy staff time ONLY through
  `App\Actions\ScheduleVisit` — it holds the per-staff no-double-book
  invariant) · Round → `Round` (built; membership is the `round_work_orders`
  table, NOT pre-created visits — a visit only exists once booked; draft →
  open → closed lifecycle, open needs ≥ 1 case AND a window, edit/remove/
  delete draft-only, late-adds while open; nullable `round.project_id` is SO8
  organizational belonging, never membership). Self-booking (built): magic
  link token on the membership row (`RoundWorkOrder` pivot model), public
  `/book/{token}` page; booking creates the visit via `App\Actions\BookVisit`
  → `ScheduleVisit` with `round_id` set and staff resolved at booking time;
  slot grid = window ∩ per-round day-hours − booked (`App\Support\RoundSlots`
  — day-hours are a stopgap until staff availability windows). Scheduled
  start (built): `rounds.scheduled_open_at` + `app:open-due-rounds` run
  every minute by `schedule:work` containers (`scheduler-dev`/`scheduler`
  Compose services); due-but-invalid rounds are skipped with the schedule
  kept; manual open clears the schedule; `round.opened` payload carries
  `trigger: scheduled|manual`.
  Where claude-code-prompts.md still says party/job/booking/resource/sendout
  (its Phase 1 predates the doc's Staff/Round/Visit reframe), the design doc
  wins.

ARCHITECTURE (from the very first commit)
- Multi-tenant: every domain row is scoped to a corporation (tenant).
- Event spine: every state change records an event to one central event log.
- Late-bound references: business entities carry an OPTIONAL external reference (for a
  future host system), and a booking may exist before its job (party is the durable anchor).
- Standalone only — no third-party / host integration yet.

DESIGN DIRECTION
- All create and edit flows open in a modal (dialog), triggered by an add/edit
  button on the page that lists the records — no dedicated create/edit pages.

DEV/TEST WORKFLOW
- Each slice includes a minimal FE so it's testable
  end-to-end, not backend-only.
- Mail goes through the event -> outbox -> Mailable path (provider-swappable), now BUILT:
  `EventRecorder` dispatches `DomainEventRecorded`; `QueueOutboxEmails` (auto-discovered listener)
  maps spine events to `outbox_messages` rows; `app:send-outbox` (scheduled every minute,
  `withoutOverlapping`) drains them through the four Mailables (invite/booked/changed/cancelled).
  Send sites never touch mail directly — they only record events. In dev/test the transport is
  Mailpit (Docker) so nothing leaves the machine; a real provider is a prod-only config swap.
  PHPUnit uses Mail::fake(); Playwright drains via `POST _test/drain-outbox` then asserts against
  Mailpit's HTTP API. Per-recipient invite status (SO4) lives on `round_work_orders`
  (`invite_sent_at`/`invite_failed_at`); booked/cancelled stay derived from visits.

