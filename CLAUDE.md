# Commlink

A shared SMS/email inbox layered on top of Ordrestyring (order/job management
used by Danish trade firms of 10–20 employees) — not a replacement for it. A
visit scheduled in Ordrestyring triggers a customer notification; the
customer's reply lands in a shared inbox the whole firm works from.
Conversations group at the customer level, with optional linking to a case.
Long-term this needs both a web app (office staff) and a React Native mobile
app (field staff, push notifications), sharing logic where practical.

Product decisions live in `docs/product.md` — consult it before adding
features. This repo is mid-pivot from an earlier product ("inspection
booking tool"); the docs describing that product have been removed.

## Stack

- `server/` — Laravel 12 API (JSON only, no server-rendered views) ·
  Sanctum (cookie auth for `web/`, bearer-token auth for `app/`) ·
  PostgreSQL · Docker
- `web/` — standalone Vite + React 19 + React Router + TanStack Query SPA ·
  Tailwind 4 · shadcn/ui (Radix primitives)
- `app/` — Expo (SDK 57) + React Native + expo-router + TanStack Query.
  **Shell only**: login, secure token storage, and one placeholder screen
  behind the auth gate. No inbox screens and no push notifications yet.

No Inertia: `server/` and `web/` are fully decoupled, talking only over the
JSON API + Sanctum SPA cookie auth. Object storage is S3-compatible
everywhere (MinIO in dev, managed bucket in prod) behind Flysystem.

**Deploy target: Laravel Cloud.** This affects only the `prod` Compose
profile below (the `app`/`api`/`web`/`scheduler` prod services,
`server/docker/entrypoint-prod.sh`, the `prod` build stage in each
Dockerfile) — that's Dokploy-era infrastructure predating this decision and
not yet adapted: Laravel Cloud builds from the repo directly rather than
consuming `docker-compose.yml`, has its own env/queue/scheduler/Reverb
conventions, and doesn't use the `dokploy-network` external network `api`/
`web` currently join. **The `dev` and `test` profiles are unaffected** —
they're the actual local dev workflow (`make dev`, `make test`) and stay
exactly as important regardless of where prod ends up deploying. Don't
extend the `prod` profile assuming it's still the real deploy path; do keep
building on `dev`/`test` as normal.

## Commands

| Command      | What it does                                              |
|--------------|-----------------------------------------------------------|
| `make install` | web/ + app/ npm dependencies + Playwright browser       |
| `make dev`     | dev stack up (builds images, generates dev TLS certs)   |
| `make stop`    | dev stack down                                           |
| `make build`   | web/ production build                                    |
| `make unit`    | PHPUnit on SQLite in-memory — fast, no containers       |
| `make test`    | full E2E cycle: test stack (Docker-built) → Playwright → down |
| `make test-ui` | Playwright UI mode, leaves the test stack running       |
| `make app-install` | app/ npm dependencies                               |
| `make app-dev` | Expo dev server for app/ (on the host, not in Docker)   |
| `make devtools` | Dev inbound simulator UI at :5174 (host, needs `make dev`) |

Artisan inside the dev stack: `docker compose exec app-dev php artisan …`

## Environments & ports

Two Dockerfiles — `server/Dockerfile` (PHP-FPM app stage → nginx API-gateway
stage) and `web/Dockerfile` (Vite build stage → nginx static-SPA stage) — one
root `docker-compose.yml` orchestrating both, three Compose profiles:

| Surface                 | Dev           | Test          |
|--------------------------|---------------|---------------|
| PHP API (HTTPS / HTTP)  | 8443 / 8080   | 9080 (HTTP)   |
| Web app (Vite / static) | 5173          | 9081          |
| PostgreSQL              | 5432          | 5433          |
| MinIO API / console     | 9000 / 9001   | —             |
| Mailpit UI / API        | 8025          | 9025 (API)    |
| Reverb (WebSocket)      | 8090          | — (not wired into the test stack yet) |

In dev, `web-dev` runs Vite's own dev server directly (no nginx in front) and
calls the API cross-origin via CORS + Sanctum stateful-domain cookies —
`api-dev` is the nginx→PHP-FPM gateway, `app-dev` is PHP-FPM itself. In
test/prod, `web/Dockerfile` builds a static bundle served by its own nginx
(`web`/`web-test`), separate from the API's nginx (`api`/`api-test`).

Dev and test stacks run concurrently. The test stack fresh-migrates an empty
database on every boot (`server/docker/entrypoint-test.sh`); prod caches
config/routes/views (`server/docker/entrypoint-prod.sh`).

## Tenancy & auth (current slice)

- `Corporation` owns users; `users.corporation_id` is non-nullable (D1).
- Everyone in a corporation is an admin — no roles yet (D2, though the
  `is_admin` flag on `Staff` already gates Staff/Corporation management).
- No self-serve signup (D3): public registration is removed. Provision
  customers with `php artisan app:create-corporation`; dev seeding reads
  `SEED_*` vars from `server/.env` (`php artisan db:seed`).
- Event spine (X1): record every state change with
  `EventRecorder::record($corporation, 'thing.happened', subject: …)` —
  append-only `events` table, one central log.
- External refs (X2): host-mirrored business entities get
  `$table->externalRef()` in migrations + the `HasExternalReference` trait;
  `external_ref` null means the record stands alone. This is what the future
  Ordrestyring adapter will hang off of.
- `Customer` is the durable anchor, scoped to one corporation — the same
  person at two corporations is two unlinked rows. Not a login account (no
  credentials stored on it). Resolution is always `Customer::findByEmail()`.
- `Staff` is a bookable-time/assignment owner; availability windows aren't
  modeled yet (treated as always available). S2 invariant: a corporation
  always has ≥ 1 staff member (founder provisioned at creation, last member
  undeletable). Login is provisioned separately from the staff row via
  `StaffController::invite()` (password-reset-link flow), not automatic.
- Tenant scoping in controllers: resolve models through
  `$request->user()->corporation->…()->findOrFail()`, never bare route-model
  bindings.

**Current product scope**: Staff and Customer, this tenancy/event-spine/auth
foundation, and slices 1–4 and 7 of `docs/inbox-plan.md` — the inbox core
model, the channel adapter with Twilio SMS, the inbox JSON API, the web
inbox, and upload links for photos (`UploadLinkDialog`, slice 7). **The
dashboard is gone**: `/inbox` is the landing page and the first sidebar item.
Both panes push over a WebSocket (Laravel Reverb, dev-only for now — see
`reverb-dev` in `docker-compose.yml`) with a slower poll as a fallback;
`App\Listeners\BroadcastInboxUpdate` sits
on the `DomainEventRecorded` spine rather than being called from
`IngestInboundMessage`/`SendOutboundMessage` directly, turning
`message.received`/`message.sent` into a thin `InboxUpdated` broadcast
(corporation + conversation id only — the client already knows how to
refetch a conversation, so the payload isn't a second copy of the message).
Archiving (slice 6 of `docs/inbox-plan.md`) is the one inbox slice not
started; the Ordrestyring sync is still future work — standalone only, no
third-party/host integration yet. It's planned as an incremental background
sync into our own copy (customer→case→message, adapter pattern), read via
`external_ref` (X2), not live queries.

**The email channel is further along than "future work"** — don't assume
it's unbuilt. Per `docs/email-plan.md`, slices 1–3, 5 and 6 are done
(corporation addresses, modules, the domain foundation, the web UI's
channel/thread selectors + rich compose, and the `devtools/` dev inbound
simulator — see Commands above); slice 4's provider-independent half is
done too (`App\Inbox\Email\*` adapter, transports, threading, sanitizer —
dev outbound already lands in Mailpit, dev inbound is exercised via both
`_test/inbound-email` and `devtools/`'s `_dev/inbound-email`). What's
genuinely missing, and blocked on an undecided email/SMS provider (see
`docs/email-plan.md`'s open questions): the real inbound webhook, DNS
verification, and prod SMTP credentials. Don't scaffold those
provider-specific pieces ahead of that decision; the rest is already fair
game to build on.

**An identity is an address, not a transport.** `identities.address_type`
(`AddressType::Phone`) is the address *space*; `messages.channel`
(`Channel::Sms`) is how a given message travelled. Everything reaching a
phone number — SMS now, WhatsApp, MMS and voicemail later — shares one
identity, so it lands in one thread under one person instead of fragmenting
them across channels. `Channel::addressType()`, `AddressType::defaultChannel()`
and `ChannelAdapter::addressType()` are the seams; `IngestInboundMessage`
takes the adapter because it needs both facts.

Unread state is per user (`conversation_reads`, holding the last message id
each person saw) and counts inbound messages only. `GET /conversations/{id}`
marks read as a deliberate side effect; the list computes all counts in one
grouped query.

The PHPUnit suite and the test Compose stack both **pin** `SMS_TRANSPORT=log`
and empty Twilio vars, because `server/.env` is read in both — without that,
a developer's real credentials would be live during tests.

Partial unique indexes carry real invariants in this schema (one live
conversation per customer, one live owner per address). Adding a foreign key
to such a table makes SQLite rebuild it and **drop the WHERE clause** from
those indexes — and so does dropping or changing a column. Any migration
altering `conversations`, `identities`, `threads` or `customers` must
re-assert them; see
`2026_08_23_000008_identities_belong_to_customers.php`.

**Inbox module** (`docs/inbox-plan.md` is the execution plan; work it slice
by slice). Its one hard rule: nothing in the core may know about a specific
channel. The core speaks only in conversations, identities, threads,
messages and the `Channel` enum; everything channel-specific lives behind a
channel adapter or in `messages.channel_data`.

**An address belongs to a person.** `identities.customer_id` is the one link;
`customers.name` is nullable, so an unknown number that texts in becomes a
person nobody has named (shown by their number, hidden from the customer
list via `Customer::scopeNamed`). A `Conversation` is the team's state about
that person — status now, ownership and watchers later — and is created only
on the first message, so a number merely written down is not an inbox item.
Naming an unknown number is `MergeCustomers`; getting it wrong is repaired
with `UnlinkIdentity`. Neither ever moves a message: messages hang off
threads, threads off addresses, so history follows the address.

`Customer` has an optional `email` and **no phone column**: numbers are
identities, one store, so a number typed on a customer's record is instantly
one the inbox can answer on and vice versa. Nothing is required to create a
customer — not even a name. Erasing one scrubs the record *and* soft-deletes
their identities, releasing the number; message bodies and `channel_data`
deliberately survive (see `docs/inbox-plan.md`).

SMS specifics live only in `app/Inbox/Sms/`, `VerifyTwilioSignature` and
`TwilioWebhookController`. `SMS_TRANSPORT` defaults to `log`, so dev and
tests need no Twilio account and text nobody's real phone; production sets
`twilio` plus `TWILIO_SID`/`TWILIO_AUTH_TOKEN`. Each firm's number is bought
in the Twilio console and attached with `php artisan app:attach-address`
(`app:detach-address` gives one up) — inbound texts are routed back to the
firm by the number they were sent to. Behind a TLS-terminating proxy, set
`INBOX_SMS_WEBHOOK_URL` or webhook signatures will not verify.

**A firm's own addresses are rows, not a column.** `corporation_addresses`
holds every number (later mailbox) a firm is reachable at, keyed by
`AddressType` — the mirror of `identities`, which are the addresses a
*customer* is reachable at. `is_primary` governs only where *new* exchanges
start: every address the firm has ever had stays live for inbound, so a
customer replying to an old number still routes. `Corporation::primaryAddress
(AddressType)` is the read; the commands are the only writers.

## Auth model

Two front ends, two ways in, one guard.

**`web/` — Sanctum SPA cookie auth**: it calls `GET /sanctum/csrf-cookie`
once on boot, then all mutating requests carry `X-XSRF-TOKEN`
(`web/src/api/client.ts`). `POST /api/login` regenerates the session.

**`app/` — bearer tokens**: `POST /api/mobile/token` takes the same
credentials plus a `device_name` and returns a personal access token, which
the phone keeps in the Keychain/Keystore (`app/src/auth/token.ts`).
`POST /api/mobile/logout` revokes just that device's token — its own route
because the web logout invalidates a session a token request does not have.
`MobileTokenRequest` extends `LoginRequest`, so both logins share one
5-attempt `email|ip` throttle.

The two never collide: `EnsureFrontendRequestsAreStateful` only makes a
request stateful when its `Origin` is a configured stateful domain. A native
client sends no `Origin`, so it falls through to the token guard — which
also means no CORS entry and no CSRF exemption is needed for `app/`. The
flip side is that `tests/TestCase.php` sets an `Origin` header on every
request, so a test exercising the token path must `withoutHeader('Origin')`
(see `tests/Feature/Auth/MobileTokenTest.php`).

`auth:sanctum` + an `admin` middleware alias (`EnsureUserIsAdmin`) gate
`routes/api.php` for both.

## Testing

**Do not run `make unit` or `make test` unless explicitly asked.** Both are
slow enough to interrupt a working session — `make test` rebuilds Docker
images and takes minutes — and running them unprompted after every change
burns time that is better spent on the change itself. Write and update tests
as part of the work; leave running them to the person who asked. Static
checks are different and stay in the loop: `npx tsc --noEmit`, `npm run lint`
and `npm run build` in `web/`, `php -l` and `vendor/bin/pint` in `server/`.

- **PHPUnit** (`make unit`): SQLite in-memory, cheap hashing — the tight loop
  and CI gate. Requests carry a `X-XSRF-TOKEN`/Origin header
  (`tests/TestCase.php`) so Sanctum treats them as first-party SPA requests,
  matching what `web/` actually sends.
- **Playwright** (`make test`): real browser against the containerized test
  stack — navigates the built `web/` SPA (port 9081), which calls the API
  (port 9080). Backend state is controlled via test-only endpoints
  (`/_test/reset`, `/_test/seed` in `server/routes/testing.php`), loaded
  **only** in the testing environment. Auth is established once in
  `tests/e2e/auth.setup.ts` and reused via stored session state.

## Conventions

- No hardcoded secrets anywhere — env vars or runtime generation only;
  each app's `.env.example` documents its variables with safe placeholders.
- PostgreSQL in every environment; SQLite only inside PHPUnit.
- One Dockerfile per app (`server/Dockerfile`, `web/Dockerfile`), each
  covering all of that app's environments (dev/test/prod) — differences live
  in Compose profiles, entrypoints, and mounts, never forked Dockerfiles.
- `web/` has no server-rendered pages and no route-name helper (no Ziggy) —
  API paths are plain string literals; if that grows unwieldy, a small
  `web/src/lib/routes.ts` constants file is the place for it, not a codegen
  step.
- `app/` mirrors `web/`'s shape on purpose: `src/api/client.ts` keeps the
  same `api.get/post/...` surface and the same `ApiError`, and
  `src/auth/AuthContext.tsx` the same `user`/`isLoading`/`login`/`logout`/
  `refresh` contract, so the two read side by side. Only the auth mechanism
  differs. `@/` means `./src` in both.
- `app/` is **not** in `docker-compose.yml` and has no Dockerfile. The Expo
  dev server runs on the host: it needs LAN reachability for physical devices
  and native tooling (Simulator, adb) that does not live in a container. Its
  API origin comes from `EXPO_PUBLIC_API_URL` in `app/.env` — use the dev
  stack's HTTP port 8080, not the self-signed 8443, and see
  `app/.env.example` for the value per target (simulator / emulator /
  device).

## Scope

- Do ONLY what the current task asks. Do not scaffold, stub, or begin later phases.
- When done, stop. Summarize what you built and how to run/verify it, then wait for review.
- `app/` exists now, but as a shell only. Do not add inbox screens, push
  notifications, an `app/` Dockerfile or Compose service, or a native E2E
  runner until explicitly asked.
