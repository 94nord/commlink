# Inbox Module — Implementation Plan (SMS via Twilio)

Executable, ordered slices for building the shared chat inbox. Each slice is
independently shippable and ends with a verification step. Work a slice to
completion (including tests) before starting the next; tick the checkboxes as
items land.

**Scope of this plan: SMS only.** Email comes after every slice here is done,
as a new adapter against the same interface — nothing email-specific gets
built now, but the core must stay channel-agnostic (hard requirement:
WhatsApp/Messenger/email later = new adapter, core untouched).

## Binding product decisions

1. **Customer IS the contact** — no separate Contact entity. An unknown
   inbound number *does* create a Customer row, but an unnamed one, kept out
   of the customer list. (This reverses the original decision, which was
   really about not polluting that list; see the identity-ownership note
   below for why the indirection it forced was worse.)
2. **Inbox replaces Dashboard entirely** — delete the dashboard page, route,
   and `GET /api/dashboard`. Inbox is the first sidebar item (titled
   "Inbox") and the landing page.
3. **Twilio number per corporation**, provisioned manually: purchase in the
   Twilio console, attach via artisan command (matches the no-self-serve
   `app:create-corporation` convention).
4. Deferred beyond this plan: email channel, ownership/assignment (one
   reserved nullable column only), internal notes, unread tracking, push /
   mobile app, delivery-status callbacks, WebSockets (web polls).

## Data model

The anonymous-contact problem is solved by letting **a person exist before
anyone knows their name**:

```
conversations   corporation_id · customer_id NULLABLE · status (open|archived) ·
                owner_id nullable (reserved, unused) · last_message_at ·
                timestamps · softDeletes
                partial unique (corporation_id, customer_id)
                  where customer_id is not null and deleted_at is null
                  → one live conversation per customer, unlimited anonymous ones

identities      corporation_id · customer_id (NON-NULL FK) · address_type ·
                value (normalized, E.164 for phone) · timestamps · softDeletes
                partial unique (corporation_id, address_type, value)
                  where deleted_at is null
                address_type is the ADDRESS SPACE (phone), not the transport.
                Every channel reaching a phone number — SMS today, WhatsApp,
                MMS and voicemail later — shares this one identity, so one
                person stays one thread and one exchange.

customers       name NULLABLE — an unknown number becomes a person nobody has
                named, shown by their number. The customer LIST hides them;
                the inbox is where they belong. No phone column: addresses
                are identities, one store.

threads         corporation_id · identity_id · external_key nullable ·
                timestamps · softDeletes
                external_key is the channel's thread discriminator — ALWAYS
                null for SMS (one default thread per identity, enforced by
                partial unique (identity_id) where external_key is null).
                Email's References chain slots in later with zero schema change.

messages        corporation_id · thread_id · channel · direction
                (inbound|outbound) · body · channel_data json nullable
                (SMS: from/to/Twilio SID) · user_id nullable (authoring staff
                user for outbound, null for inbound) · sent_at · timestamps
                index (thread_id, sent_at) · NO softDeletes (immutable log) ·
                NO conversation_id — the timeline resolves messages → threads
                → identities → conversation, so merge/unlink never touch
                message rows
```

**An address belongs to a person, not to an exchange.** Identities point
straight at a customer; the conversation is the team's state about that
person and is created only when something is actually said. One link, no
drift. The mechanics:

- **Inbound from an unknown number**: create an unnamed customer + identity
  + conversation. The inbox shows the number.
- **Writing a number on a customer's record**: create an identity. Nothing
  else — no conversation, no inbox entry.
- **Naming an unknown number**: merge the placeholder person into the known
  one. Identities move; if only one side had a conversation it is kept
  (id and all), otherwise the survivor absorbs and recomputes. Messages
  never move — they hang off threads, which hang off the addresses.
- **Unlink**: move one address to a fresh unnamed person; its history goes
  with it.

The inbox list shows only conversations with a message, so anchors and
address-book entries never clutter the queue.

## Architecture

```
Webhook / UI request
      │
      ▼
App\Inbox\Contracts\ChannelAdapter    ← the ONLY place channel specifics live
  channel() → Channel  (transport)        (App\Inbox\Sms\SmsChannelAdapter)
  addressType() → AddressType (phone)     └─ SmsTransport: twilio | log
  receive(raw) → CanonicalInbound
  send(Message) · normalizeAddress() · capabilities()
      │
      ▼
App\Inbox\Actions (channel-agnostic core)
  IngestInboundMessage · SendOutboundMessage · ComposeMessage ·
  OpenConversation · MergeCustomers · UnlinkIdentity ·
  AddCustomerAddress · RemoveCustomerAddress
      │
      ▼
Models: Customer → Identity → Thread → Message   +   EventRecorder spine
        Customer → Conversation (team state, created on first message)
```

SMS-specifics (Twilio field names, E.164 ingress normalization, transports,
signature check, `corporations.sms_number` lookup) live only in
`App\Inbox\Sms\*`, `VerifyTwilioSignature`, `TwilioWebhookController`, and
`AttachSmsNumber`. The transport abstraction (`SMS_TRANSPORT=log` default,
`twilio` in prod) mirrors Laravel's mail drivers so dev/test never need
Twilio credentials.

**The rule, audited every slice:** for every schema field or piece of logic
ask "does this assume SMS specifically?" If yes and it's in the core, move
it into the adapter or `message.channel_data`.

---

## Slice 1 — Core domain model + phone normalization ✅ DONE

Goal: schema, models, enums, factories, and the anonymous/link/merge/unlink
mechanics at model level. No routes, no UI.

New composer dep: `propaganistas/laravel-phone`.

- [x] Enums `server/app/Enums/{Channel,MessageDirection,ConversationStatus}.php`
      — backed string enums, `CustomerKind` style (`Sms = 'sms'`,
      `Inbound`/`Outbound`, `Open`/`Archived`).
- [x] Four migrations (anonymous class, domain-rule docblock, string columns
      for enums, partial unique indexes via `DB::statement` with SQL valid on
      both Postgres and SQLite — mirror
      `2026_07_06_000001_create_customers_table.php`):
      conversations, identities, threads, messages (per the data model above).
- [x] Models `server/app/Models/{Conversation,Identity,Thread,Message}.php`
      — `corporation(): BelongsTo`, enum `casts()`, SoftDeletes (not on
      Message), relations, `Conversation` display-name accessor
      (customer name ?? first identity value). Add
      `Corporation::conversations()`.
- [x] `server/app/Support/PhoneNumber.php` — `normalize(string): string`
      returning E.164 via laravel-phone; default region from
      `config('inbox.default_phone_region')`; throws on unparseable input.
- [x] `server/config/inbox.php` —
      `default_phone_region => env('INBOX_DEFAULT_PHONE_REGION', 'DK')`;
      document in `server/.env.example`.
- [x] Core actions
      `server/app/Inbox/Actions/{LinkConversationToCustomer,UnlinkIdentity}.php`
      — `DB::transaction`, record `conversation.linked` /
      `conversation.merged` / `identity.unlinked` via `EventRecorder`.
- [x] Factories for all four models (`corporation_id => Corporation::factory()`).
- [x] Tests in `server/tests/Feature/Inbox/`:
      `PhoneNumberTest` (DK 8-digit `12345678` → `+4512345678`, spaced
      `+45 12 34 56 78` → same, E.164 passthrough, invalid throws);
      `ConversationLinkingTest` (anonymous create; link sets customer_id;
      link-when-customer-has-conversation merges identities, soft-deletes the
      source, leaves messages untouched, takes max last_message_at; unlink
      moves identity + history to a fresh anonymous conversation; partial
      unique constraints hold; second live conversation per customer rejected).

**Verify:** `make unit` green — 108 passing. The migrations were also applied
against dev PostgreSQL, proving the partial-index SQL is valid on both
engines (PHPUnit only ever exercises SQLite).

**Two things this plan got wrong, corrected while building:**

- `12345678` is *not* a valid Danish number — `1xxxxxxx` is a service range,
  not a subscriber one — so it cannot be the normalization example. Tests use
  real mobile ranges (`20123456` → `+4520123456`). `normalize()` validates
  rather than merely parsing, so a number that could never receive an SMS
  never becomes an identity.
- Unlinking the *only* address of an anonymous conversation is refused: there
  is nothing to separate, and it would leave a row nothing can reach. An
  anonymous conversation therefore always keeps at least one address, which
  is why no "soft-delete the emptied conversation" branch exists. Unlinking
  the last address of a *customer's* conversation is allowed, and leaves that
  conversation standing.

`Conversation::timeline()` is the gathering query — every message across
every thread of every identity, oldest first. Slice 3 paginates it, slice 6
filters on status.

## Slice 2 — Channel adapter interface + SMS adapter + Twilio wiring ✅ DONE

Goal: the channel contract, the SMS implementation, transport abstraction,
corporation number storage + attach command, signed inbound webhook, sync
outbound send. Shippable: a corporation with an attached number receives real
SMS into the database.

New composer dep: `twilio/sdk`.

- [x] `server/app/Inbox/Contracts/ChannelAdapter.php` —
      `channel(): Channel`, `capabilities(): ChannelCapabilities`,
      `receive(array $raw): CanonicalInbound`, `send(Message $message): void`.
- [x] `server/app/Inbox/Contracts/ChannelCapabilities.php` — readonly value
      object (`supportsThreadKeys: false`, `maxBodyLength`, `supportsAttachments:
      false` for SMS). Trivial now; exists so richer channels don't break
      core assumptions.
- [x] `server/app/Inbox/CanonicalInbound.php` — readonly DTO: `address`
      (normalized identity value), `externalKey` (null for SMS), `body`,
      `sentAt`, `channelData`.
- [x] `server/app/Inbox/ChannelRegistry.php` — `Channel` enum → adapter
      instance; bindings in `AppServiceProvider`. Core only ever calls the
      interface.
- [x] `server/app/Inbox/Sms/SmsChannelAdapter.php` — `receive()` maps
      Twilio `From/Body/MessageSid/To` to canonical, normalizing `From` via
      `PhoneNumber` (the only place SMS field names + ingress normalization
      live); `send()` resolves to = thread→identity value, from =
      corporation `sms_number`, delegates to transport.
- [x] `server/app/Inbox/Sms/Transport/{SmsTransport,TwilioSmsTransport,LogSmsTransport}.php`
      — interface `send(string $from, string $to, string $body): ?string`
      (returns provider SID). Bound by `config('inbox.sms.transport')` →
      `env('SMS_TRANSPORT', 'log')`. Default **log**; prod opts into twilio.
- [x] `server/app/Inbox/Actions/IngestInboundMessage.php` — channel-agnostic:
      firstOrCreate identity (creating the anonymous conversation when new),
      firstOrCreate thread by external_key, create inbound Message, bump
      `last_message_at`, record `message.received` (actor null — webhook
      context). Transactional.
- [x] `server/app/Inbox/Actions/SendOutboundMessage.php` — create outbound
      Message (author user_id), `adapter->send()`, store returned SID in
      channel_data, record `message.sent`. **Synchronous** — no queue worker
      exists in the stack yet, the Twilio call is one ~200ms HTTP request,
      and a failure surfaces immediately to the sender instead of dying
      silently in a jobs table. Revisit when volume demands.
- [x] `server/app/Http/Middleware/VerifyTwilioSignature.php` — Twilio
      `RequestValidator` (`services.twilio.token`) against
      `X-Twilio-Signature` + full URL + POST params; 403 on mismatch. Note
      the APP_URL/reverse-proxy caveat in the docblock.
- [x] `server/app/Http/Controllers/TwilioWebhookController.php` — resolve
      corporation by normalized `To` == `corporations.sms_number` (unknown →
      404), `adapter->receive()` → `IngestInboundMessage`, respond 200 with
      empty TwiML (`<Response/>`, text/xml).
- [x] Migration: nullable **unique** `sms_number` on corporations (multiple
      NULLs are fine on both Postgres and SQLite).
- [x] `server/app/Console/Commands/AttachSmsNumber.php` —
      `app:attach-sms-number`, prompt fallbacks like `CreateCorporation`,
      normalizes to E.164, records `corporation.sms_number_attached`.
- [x] Wiring: `POST api/webhooks/twilio/sms` in `routes/api.php` OUTSIDE the
      `auth:sanctum` group with `VerifyTwilioSignature`; CSRF exception
      `api/webhooks/*` in `bootstrap/app.php`; `services.twilio` config
      (`TWILIO_SID`, `TWILIO_AUTH_TOKEN`); `.env.example` entries incl.
      `SMS_TRANSPORT=log`.
- [x] Tests: `TwilioWebhookTest` (valid signature → message row; bad
      signature → 403; unknown To → 404; new number → anonymous conversation
      + identity + thread; repeat number appends to same thread, no dup
      conversation; DK local-format From normalized); `SmsAdapterTest`
      (canonical mapping, capabilities); `SendOutboundMessageTest` (fake/log
      transport, message row + event + SID stored);
      `AttachSmsNumberCommandTest`.

**Verify:** `make unit` — 133 passing. The `sms_number` migration was applied
against dev PostgreSQL and the route confirmed registered. Signature
verification is tested for real (valid, forged, tampered payload, missing,
and unconfigured token) by computing signatures with Twilio's own
`RequestValidator::computeSignature`. Still worth a smoke test against a real
Twilio number over ngrok before a pilot.

**Changes from the plan, made while building:**

- `ChannelAdapter::send()` returns `array` (what the channel wants remembered
  about the delivery, merged into `channel_data`) rather than `void` plus a
  SID. The core never reads it, so email's reference chain will fit the same
  signature without a change.
- Added `INBOX_SMS_WEBHOOK_URL`. Twilio signs the exact URL it dialled, and
  this stack's nginx does not set `fastcgi_param HTTPS`, so PHP rebuilds
  `http://…` and every real webhook would 403. The override pins the public
  URL for the signature check; leave it empty when the app sees its own real
  URL. There is a test for both paths.
- The webhook fails closed: no configured auth token means nothing can be
  verified, so nothing is accepted. A consequence is that the endpoint cannot
  be exercised in dev without Twilio credentials — slice 4's
  `_test/inbound-sms` covers that for tests, and ngrok covers it for dev.
- `SendOutboundMessage` wraps create-send-record in a transaction, so a
  refused send leaves no message row. A timeline must never show the customer
  a message they never received.
- `IngestInboundMessage` also records `conversation.started` when an unknown
  address brings a conversation into being — creating one is a state change,
  and the event spine is supposed to have every one of them.

## Slice 3 — Inbox API ✅ DONE

Goal: JSON API for list, timeline, reply, compose, link/unlink. Shippable:
API-complete backend.

Routes inside the `auth:sanctum` group (non-admin):

```
GET    /conversations                    list, last_message_at desc, previews
POST   /conversations                    compose
GET    /conversations/{id}               detail + timeline page
POST   /conversations/{id}/messages      reply
POST   /conversations/{id}/link          { customerId }
POST   /identities/{id}/unlink
```

- [x] `ConversationController` + `ConversationMessageController` — tenancy
      strictly via `$request->user()->corporation->conversations()->findOrFail()`
      (cross-tenant = 404; never route-model binding). Hand-mapped camelCase
      envelopes per house style: list →
      `{conversations: [{id, displayName, customerId, status, lastMessageAt,
      lastMessage: {body, direction, sentAt}}]}`; detail adds `identities`
      and paginated `messages`.
- [x] **Reply correctness:** target thread = the thread of the latest
      *inbound* message across the conversation's threads; fallback (fresh
      compose, no inbound yet) = the most recent thread. Delegates to
      `SendOutboundMessage`.
- [x] **Compose semantics** (server is the dedup backstop): normalize
      phone → existing identity ⇒ append to *its* conversation and return it
      (never a duplicate); else `customerId` given ⇒ find-or-create that
      customer's conversation + identity + thread (phone required in the
      same request — customers store no phone; identities are the phone
      store); else raw new number ⇒ anonymous conversation, identity,
      thread, send.
- [x] Timeline pagination: `Message::whereIn(thread_id, …)->orderBy(sent_at)`,
      cursor `?before=<message id>`, page size 50, `hasMore` in response.
- [x] FormRequests: `StoreConversationRequest` (`body` required; exactly one
      of `customerId`/`phone`; phone validated + normalized),
      `StoreConversationMessageRequest` (`body` required, max per
      capabilities), `LinkConversationRequest`.
- [x] Tests: `ConversationControllerTest` (list ordering + preview shape;
      cross-tenant 404 on every endpoint; compose dedup by phone; compose to
      customer without identity requires phone; anonymous compose; link and
      link-merge via API; unlink via API; validation error shapes);
      `ConversationReplyTest` (reply lands in latest-inbound's thread with
      multiple identities/threads present; fallback path; event recorded).

**Verify:** `make unit` — 160 passing. Migration applied against dev
PostgreSQL and routes confirmed registered.

**Changes from the plan, made while building:**

- **`conversations.last_message_id` added** (migration `..._000005`). The list
  needs a preview line per row and is polled every few seconds by every open
  browser; walking identities and threads per conversation would be an N+1 on
  the hottest query in the module. `last_message_at` was already denormalized
  for ordering, so this is its other half. Both are written together through
  `Conversation::touchLastMessage()` / `recomputeLastMessage()`, and the merge
  and unlink actions now recompute rather than doing arithmetic on
  timestamps — truth comes from the messages, so the two cannot drift.
- **`ChannelAdapter::normalizeAddress()` added.** Compose takes an address
  typed by a human, and matching it against existing identities requires
  normalizing it first. Without this the compose path would have had to call
  `PhoneNumber` directly and the core would have learned what a phone is.
- **Body length is enforced in `SendOutboundMessage`** against
  `capabilities()->maxBodyLength`, not in a form request — a request cannot
  know which channel the reply will go out on.
- **Compose is one transaction over resolution *and* send**, so a refused
  send cannot leave an empty conversation sitting in the inbox.
- **`customerId` and `phone` together is legal** (the plan said "exactly one
  of"). Sending them together is how a number gets attached to a customer we
  hold no number for — the plan's own compose semantics needed it. The rule
  is: at least one.

**A portability bug this slice uncovered:** adding a foreign-key column makes
Laravel **rebuild the table on SQLite**, and the rebuild copies indexes
*without their WHERE clause*. That silently turned the conversations claim
index into a plain unique one, so a merged-away conversation kept its hold on
its customer — caught by an existing slice-1 test. PostgreSQL is unaffected.
Migration `..._000005` re-asserts the partial index afterwards.

> **Any future migration that alters `conversations`, `identities`, `threads`
> or `customers` must re-assert that table's partial indexes**, or the
> invariants they enforce quietly stop existing under SQLite.

## Slice 4 — Web inbox UI + dashboard replacement ✅ DONE

Goal: Inbox replaces Dashboard as the landing page; two-pane inbox with
bubbles, composer, compose modal, link-to-customer. Shippable: usable
end-to-end product.

**Deletions (do these, don't skip):**
- [x] `web/src/pages/dashboard.tsx`
- [x] `server/app/Http/Controllers/DashboardController.php` + its
      `GET /dashboard` line in `server/routes/api.php`
- [x] `server/tests/Feature/DashboardTest.php`
- [x] `tests/e2e/dashboard.spec.ts` (replaced by `inbox.spec.ts`)

Changes:
- [x] `web/src/App.tsx` — `/` → `Navigate to /inbox`; routes `/inbox` and
      `/inbox/:id` (deep-linkable, like `/customers/:id`); `/dashboard` →
      redirect `/inbox` (stale bookmarks); drop the Dashboard import.
- [x] `web/src/auth/RequireAuth.tsx` — `RequireAdmin` fallback `/dashboard`
      → `/inbox`.
- [x] `web/src/components/app-sidebar.tsx` — first item
      `{ title: 'Inbox', url: '/inbox', icon: Inbox }`, Dashboard removed,
      logo link → `/inbox`.
- [x] `web/src/components/nav-main.tsx` — `isActive` = exact match OR
      `pathname.startsWith(url + '/')` (fixes `/inbox/:id` highlighting).
- [x] shadcn CLI adds: `textarea`, `command`, `popover`, `scroll-area`.
- [x] `web/src/pages/inbox/index.tsx` — `usePageTitle('Inbox')`, AppLayout;
      left pane conversation list (display name or E.164, last-message
      preview, relative timestamp, anonymous badge), right pane empty state
      or the selected conversation.
- [x] `web/src/pages/inbox/conversation.tsx` — merged timeline: inbound
      bubbles left, outbound right with author name; "load older" cursor
      button; Textarea composer + send mutation (invalidate on success,
      `InputError` on 422, house style).
- [x] `web/src/pages/inbox/compose-dialog.tsx` — "+" opens Dialog with a
      Command-based smart recipient field: picking a customer with an
      existing conversation navigates to it (server dedups as backstop);
      customer without one reveals a phone input; free-typed digits treated
      as a number → silent anonymous conversation. Channel auto-pick: number
      → SMS.
- [x] `web/src/pages/inbox/link-customer-dialog.tsx` — "Link to customer"
      affordance on anonymous conversations; on merge response, navigate to
      the surviving conversation id.
- [x] **Polling** (first in the codebase): `refetchInterval: 5000` on
      `['conversations']` and `['conversations', id]`. No WebSockets.
- [x] Test plumbing: `server/routes/testing.php` gets `POST /_test/inbound-sms`
      `{to, from, body}` — pushes through `SmsChannelAdapter::receive()` +
      `IngestInboundMessage` (the real pipeline minus the signature check) —
      and seed support for setting the corporation's `sms_number`. Outbound
      in the test stack uses the default `log` transport.
- [x] `tests/e2e/auth.setup.ts` — `waitForURL('/inbox')` + assert the Inbox
      heading (the corporation-name heading dies with the dashboard).
- [x] `tests/e2e/inbox.spec.ts` — inject inbound → conversation appears via
      polling with the number as title; open + reply → outbound bubble
      renders; compose to a new number creates a conversation; link
      anonymous conversation to a seeded customer renames it.
- [x] Docs: update `CLAUDE.md` current-scope section + `docs/product.md`
      build state.

**Verify:** `make unit` (156) + `make test` (10 e2e, all green) + format,
lint and typecheck on `web/`. The dev stack was loaded in a browser to
confirm the bundle boots and a guest at `/inbox` is redirected to login with
no console errors.

**Changes from the plan, made while building:**

- **No new npm dependencies.** The plan called for shadcn `command`,
  `popover` and `scroll-area`, which would have pulled in `cmdk` and two more
  Radix packages for one dialog. The recipient picker is a filtered list
  under a plain input instead, and scrolling is `overflow-y-auto`. The only
  new UI file is `textarea.tsx`, hand-written to match `input.tsx`. Adding
  dependencies is a decision worth asking about, and nothing here needed
  them.
- **`/inbox` and `/inbox/:id` render the same component**, which reads the id
  from the route, rather than a nested `<Outlet/>`. One component owns the
  two-pane layout and the "which pane shows on mobile" question.
- **Two more stale `/dashboard` references** than the plan listed: the
  post-login redirect in `pages/auth/login.tsx` and the logo link in
  `layouts/auth/auth-simple-layout.tsx`. Also
  `CorporationTest::test_the_dashboard_shows_the_users_corporation_name`,
  whose only subject was the deleted endpoint.
- **`_test/customer` added** alongside `_test/inbound-sms`, so the link test
  has somebody to link to without clicking through the customers UI first.
  `_test/seed` now also gives every seeded firm an SMS number, since the
  inbox cannot send without one.
- **The two panes carry `aria-label`s** ("Conversations", "Conversation").
  Playwright needed to distinguish a message body in the thread from the same
  text in the list preview, and a labelled region is the honest fix rather
  than a brittle CSS selector.

**Worth knowing:** e2e phone numbers must be *real* Danish ranges.
`+452000xxxx` is not allocated and `normalize()` rejects it, which failed
four tests until the numbers were changed to `+4520123456` and friends. Same
trap as slice 1's `12345678`.

### Follow-ups after slice 4 (from using it)

- **Starting a conversation no longer happens in a modal.** The dialog picks
  the recipient and nothing else; it then opens an empty conversation at
  `/inbox/new`, and the first message is written in the same box as every
  other reply. Nothing is persisted until that message sends, so abandoning a
  draft cannot leave empty rows in the queue and a failed send leaves no
  trace. Needed one new endpoint, `GET /conversations/resolve?phone=…`: a
  number already in the inbox has to open its history rather than a blank
  page, and the list alone cannot tell (an address filed under a customer
  shows their name, not the number). It also rejects a mistyped number while
  the person can still fix it.
- **The thread pane scrolls internally.** `SidebarInset` is `min-h-svh` — a
  minimum — so nothing capped the page height and a long conversation pushed
  the composer off the bottom. `AppLayout` now takes an opt-in `fullHeight`
  prop (inbox only; every other page still scrolls as a document). The
  timeline pins to the bottom on open and after your own send, and stays put
  when you have scrolled up to read. Regression-tested in `inbox.spec.ts`.
- **Customers have a phone, and no longer need an email.** A trade customer
  is often a name and a mobile number. `customers.phone` is the *address
  book*: `identities` remains what inbound messages match against and what a
  reply is addressed to. `Customer::addressFor(Channel)` maps between them —
  an exhaustive match, so a new channel has to answer what an address looks
  like for it. Composing to a customer nobody has texted now uses the number
  on their record instead of refusing.

  Two traps this hit: making `email` nullable rebuilds the SQLite table and
  drops the partial indexes' WHERE clauses again (re-asserted in the
  migration — see the slice 3 warning), and Danish mobile ranges are
  allocated in patches, so factories must generate-and-check rather than
  assume a prefix is valid.

### Identity rework: address space, not transport

An identity used to be keyed on the *channel* a message arrived over, which
would have split one person the moment a second channel reached the same
place. It is now keyed on `address_type` — the kind of address — while each
message still records the `channel` that carried it.

```
AddressType::Phone   the address space. One identity, one thread.
Channel::Sms         the transport. Message-level. MMS and voice join it later.

Channel::Sms->addressType()      => Phone   (what this transport reaches)
AddressType::Phone->defaultChannel() => Sms (how a plain message goes out)
ChannelAdapter::addressType()    declared per adapter
```

The payoff, which is the whole point: an MMS photo and a voicemail recording
sent to a number the firm already texts will resolve to that same identity,
land in that same thread, and appear in the one conversation — no merging, no
duplicate rows, no change to the inbox. `Customer::addressFor()` is keyed the
same way, since one number serves every channel that reaches a phone.

`IngestInboundMessage::handle()` now takes the adapter rather than a bare
`Channel`, because it needs both facts and they are no longer the same one.

Migration `..._000007` renames the column, migrates `sms` → `phone`, and
re-asserts the partial unique index (the rename rebuilds the table on SQLite
— the recurring trap noted in slice 3).

### Identity ownership: addresses belong to people

Identities first hung off conversations, so an unknown number had somewhere
to live. The cost showed up everywhere else: a customer's numbers were
reachable only through a conversation, writing one down forced an exchange
into being that had not happened, and `customers.phone` became a second
store that could not agree with the first.

Now `identities.customer_id` is the one link, and `customers.name` is
nullable so an unknown number can simply be a person nobody has named.
`customers.phone` is gone. The conversation stayed — it is genuinely the
team's state about a person, and `docs/product.md` anticipates it attaching
to a *case* later — but it is created lazily, on the first message.

What this bought, concretely: adding a number to a customer creates one row
and nothing else; a customer's addresses are `customer.identities`; and
WhatsApp, whose addresses *are* phone numbers, will resolve to the identity
the SMS history already hangs off — same person, same thread, automatically.
Channels whose addresses are not phone numbers (Messenger's page-scoped ids)
get their own `AddressType` and must be linked by hand.

**Choosing a channel to reply on** is deferred. The rule when it lands:
reply on the channel of the latest inbound message, ask the adapter whether
it can send right now (WhatsApp's 24-hour window), fall back to the address
type's default, and let the composer override it explicitly.

### Anonymization — what is in place and what is not

Personal data lives in `customers` (name, email, address),
`identities.value`, and `messages.body` / `messages.channel_data` (which
holds the from/to pair). Removing `customers.phone` means numbers are no
longer duplicated across two tables, which is what makes a future erasure
correct rather than half-correct.

In place: erasing a customer scrubs their record **and soft-deletes their
identities**, which releases the number — a later text from it arrives as a
stranger. Identity values are always scrubbable because the unique index
covers live rows only. Event payloads carry ids, never addresses, since an
append-only log cannot be scrubbed.

**Not** in place, and a product decision as much as a technical one: message
bodies and `channel_data` survive erasure. They are also the record of what
the firm agreed with the customer. `LogSmsTransport` writes bodies and
numbers to `laravel.log` — fine in dev, worth remembering.

## Slice 5 — Watchers + notifications ⏸️ DEFERRED (built, then removed)

> Built and working, then **removed at the product owner's request** to be
> revisited later. Nothing of it remains: no `conversation_watchers` table
> (rolled back), no job, notification, endpoints, bell toggle or queue worker
> services. The checkboxes below are the plan to rebuild from.
>
> Worth keeping from the attempt: watchers-only notification cannot ship on
> its own, because a new conversation has no watchers and nothing else
> auto-subscribes anyone, so an inbound text would reach nobody. The rule
> that made it work was **a conversation nobody watches notifies the whole
> firm**, with watching (or replying) narrowing it. Also: use a Notification
> rather than a Mailable — `server/` has no `resources/` directory and the
> password-reset mail already goes through Laravel's own templates.


Watchers live on **conversations** (they must cover anonymous ones; the
conversation is the contact-level team-state entity). MVP notification =
**email to watchers** (real, Mailpit-testable) + the event-spine record.
Explicitly deferred: in-app notification center, unread badges, push,
digesting. Auto-watch on reply (you replied → you're watching); self
watch/unwatch only.

- [ ] Migration `conversation_watchers` — corporation_id, conversation_id,
      user_id, timestamps; unique `(conversation_id, user_id)`.
      `Conversation::watchers()` belongsToMany(User); merge action updated
      to union watchers.
- [ ] `server/app/Jobs/NotifyWatchersOfInboundMessage.php` (ShouldQueue),
      dispatched after commit from `IngestInboundMessage`; mails each
      watcher via `server/app/Mail/InboundMessageNotification.php` + blade
      view (display name, body excerpt, link to `/inbox/{id}` via
      FRONTEND_URL).
- [ ] Routes `POST` / `DELETE /conversations/{id}/watch` (self only); events
      `conversation.watched` / `conversation.unwatched`; `watching` flag in
      the detail envelope; bell toggle in the conversation header.
- [ ] **Infra:** add `queue-work` / `queue-work-dev` compose services
      (`php artisan queue:work --tries=3`, same build/env pattern as
      `scheduler`); set `QUEUE_CONNECTION=sync` in the test stack so e2e
      needs no worker and Mailpit receives immediately (phpunit already
      runs sync).
- [ ] Tests: watch/unwatch endpoints + tenant 404s; ingest dispatches job
      (`Queue::fake`); job mails watchers only (`Mail::fake`); reply
      auto-watches; merge unions watchers. Playwright: watch → inject
      inbound → assert via Mailpit API (port 9025, existing pattern).

**Verify:** `make unit` (170) and `make test` (13 e2e) green. In the dev
stack, an inbound message queued one job, `queue-work-dev` drained it (0
left, 0 failed) and the mail arrived in Mailpit — the whole loop, not a
mocked one.

**Decisions and changes made while building:**

- **A conversation nobody watches notifies the whole firm.** The plan left
  this open ("whole team" vs "watcher selection"). Watchers-only would have
  meant an inbound message from an unknown number reaching *nobody*, since a
  new conversation has no watchers and there is no ownership or case linkage
  yet to auto-subscribe anyone. The rule is now: unclaimed conversations are
  everybody's, and watching (or replying, which counts as the same thing)
  narrows it. `Conversation::notifiables()` is the single place this lives,
  so the pilot can change its mind in one method.

  The consequence to watch for: the first person to reply silences everyone
  else on that conversation. In a firm where the shared inbox is on screen
  anyway that is the intended noise reduction, but it is the thing to check
  with real users.
- **A Notification, not a Mailable + blade view.** `server/` has no
  `resources/` directory — it renders no HTML of its own — and the
  password-reset mail already goes through Laravel's built-in notification
  templates. `App\Notifications\InboundMessageReceived` follows that instead
  of reintroducing views.
- **`queue:listen` in dev, `queue:work` in prod.** `listen` reboots the
  framework per job, so edits to the mounted source are picked up without
  restarting the container — worth the overhead in dev and wrong in prod.
- **`QUEUE_CONNECTION: sync` pinned for the test stack**, so Playwright never
  waits on a worker, and `.env` cannot leak a different setting in.
- **Unlink does not carry watchers across.** The fresh conversation starts
  unwatched, which by the rule above means everybody hears about it — the
  safe direction for a conversation that was just pulled out of the wrong
  place.

## Slice 6 — Status + archiving

Front-standard queue mechanics. The status column exists since slice 1
(default `open`) — no migration.

- [ ] `PATCH /conversations/{id}` accepting `{status: open|archived}` +
      `UpdateConversationRequest`; events `conversation.archived` /
      `conversation.reopened`.
- [ ] `IngestInboundMessage` — auto-reopen: inbound to an archived
      conversation sets it open + records `conversation.reopened`. Same
      conversation, never a new one. Channel-agnostic core behavior.
- [ ] `GET /conversations?status=open|archived` (default `open`).
- [ ] Web: shadcn `tabs` add; Open/Archived tabs above the list;
      archive/reopen action in the conversation header.
- [ ] Tests: archive/reopen endpoints + tenant 404; auto-reopen on webhook
      ingest; list filter. Playwright: archive clears the queue → inject
      inbound → same conversation id resurfaces in Open.

**Verify:** `make unit`, `make test`.

---

## Slice 7 — Photo upload links ✅ DONE

**Why this exists.** Inbound MMS is built and tested
(`MessageAttachment`, media fetch in `IngestInboundMessage`, images in the
bubble) but cannot run on a Danish number: Twilio reports `mms: false` for
`+45` numbers, and the carrier drops the picture before Twilio sees it —
every inbound message arrives with `num_media: 0`. The MMS path stays as
written for the day there is an MMS-capable number; this slice is how a
customer sends a photo in the meantime.

**Why a link and not another platform.** WhatsApp carries media but Denmark
skews to Facebook Messenger, so it would be a bet on an app much of the
customer base may not use. RCS is live in Denmark via Twilio but needs
sender registration plus Google and carrier review on a variable timeline,
and silently falls back to SMS — no inbound media — for anyone whose device
or carrier lacks it. A link works on every phone today, needs no vendor, and
reuses the whole attachment path already in place. The customer taps it and
gets their normal camera/gallery picker.

- [x] `upload_links` + `App\Models\UploadLink`: `token_hash` (SHA-256, what
      lookups use) plus `token` (encrypted, so the dialog can show the link
      again), `conversation_id`, `expires_at`, `revoked_at`, `upload_count`,
      `user_id`. Limits in `config/inbox.php` under `uploads`: 7 days,
      10 photos *per submission*, 10 MB each — no lifetime total (see below).
      `upload_count` is kept for the audit trail only; nothing gates on it.
- [x] `GET|POST /conversations/{id}/upload-link` (authed, tenant-scoped).
      The dialog reads the current link rather than minting on open, so
      reopening to re-copy cannot replace the URL the customer is already
      using. Events `upload_link.created` (with how many it replaced) /
      `upload_link.used`.
- [x] Public `GET|POST /api/uploads/{token}`, `throttle:20,1`, outside
      `auth:sanctum`. A `Route::bind` resolves unknown, expired and
      used-up tokens to the same 404 so the endpoint is not an oracle.
      CSRF stays on — the page is same-origin, so Sanctum makes it stateful
      and the SPA client already fetches the cookie.
- [x] `StoreUploadedPhotos` files one inbound `Message` per batch with
      `MessageAttachment` rows on the existing storage path, so the timeline
      renders them unchanged. Channel stays the thread's; `channel_data`
      carries `{source: 'upload_link'}`.
- [x] Tests: `PhotoUploadTest` (15 cases) + `tests/e2e/photo-upload.spec.ts`,
      which runs in a blank storage state — the suite's first
      unauthenticated browser context.

**Settled while building:** a link is multi-use for 7 days rather than
single-use, since one job produces photos over days from one text.
Generating a link **revokes every earlier one** for that conversation, so
there is exactly one live URL and no question of which is current — decided
against the alternative of letting them coexist, which would have avoided
breaking a customer mid-upload. That risk is real and accepted: the dialog
says so in as many words before you press the button.

Storing the token encrypted alongside its hash is what makes the dialog
re-showable, and it is a deliberate weakening: before, a stolen database
yielded no working links; now it yields them to anyone who also holds
APP_KEY. The blast radius stays bounded by the same expiry, photo cap and
single-conversation scope.

Still open: whether an upload should notify anyone once watchers exist.

**A link has no lifetime cap on photo count — only `max_per_upload` bounds
a single submission.** The original design capped total photos per link;
that was reversed because it turned a job needing more photos than expected
into a support request the customer has no way to resolve themselves. A
link now keeps accepting uploads, in as many visits as it takes, until it
expires or is replaced. `max_per_upload` (default 10) is a technical
ceiling against an oversized request, not a budget — `PhotoUploadTest`
proves a link with `upload_count` past the old cap still accepts a photo.

**The page tells the customer nothing about themselves** — only which firm
is asking and until when. Anyone can hold a forwarded text, so it must not
confirm who the link was sent to. `PhotoUploadTest` asserts the response
body carries exactly those two keys.

**Verify:** `make unit`, `make test`.

---

## Cross-cutting notes

- **New dependencies by slice:** S1 `propaganistas/laravel-phone` (composer);
  S2 `twilio/sdk` (composer); S4 shadcn `textarea`/`command`/`popover`/
  `scroll-area`; S6 shadcn `tabs`. Nothing else.
- **Adding email later** (out of scope now): new `App\Inbox\Email\*` adapter
  + non-null `external_key` handling (References chains → multiple threads
  per identity). Core actions, models, controllers, and UI are already
  channel-agnostic by construction.
- **Scope guards:** no email scaffolding beyond the neutral
  `external_key`/`channel` columns; no React Native `app/`; no internal
  notes; ownership is exactly one reserved nullable `owner_id` column.
