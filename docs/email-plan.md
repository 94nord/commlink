# Email Channel — Implementation Plan

Ordered slices for adding email as the second channel, plus the two pieces of
foundation it forces (corporation addresses as a table, modules as a concept)
and the dev-only inbound simulator. Same rules as `docs/inbox-plan.md`: each
slice independently shippable, verification at the end, work one slice to
completion before the next. The inbox core rule is unchanged and audited
every slice: **nothing in the core may know about a specific channel.**

Open questions that need an answer before their slice starts are collected at
the bottom — none block slice 1.

## Binding decisions

1. **Provider: TBD — supplier search in progress.** SendGrid was
   provisionally picked to keep invoicing with Twilio, but that premise is
   shaky (separate billing, divestiture reports) and its Inbound Parse
   doesn't sign webhooks. Slice 4 is blocked until a provider is chosen;
   SendGrid specifics in that slice are placeholders. Slices 1–3, 5–6 are
   provider-independent. The adapter + transport seam keeps the eventual
   choice contained to `App\Inbox\Email\*` + one webhook controller.
2. **Per-corporation addressing**, not per-case. A corporation owns one or
   more email addresses (on its own domain, DNS-authenticated); the customer
   is resolved by their from-address — exactly mirroring SMS. The per-case
   address idea in `docs/product.md` is deferred until the Ordrestyring sync
   exists (there are no cases to address yet); update product.md when
   slice 3 lands.
3. **Firms use their own domain** (option: DNS authentication, not OAuth
   mailbox connection, not plain forwarding). SPF/DKIM via SendGrid domain
   authentication, done manually in the SendGrid console per firm — same
   convention as buying a Twilio number in the Twilio console. Inbound: the
   firm points MX for the chosen subdomain/address at SendGrid Inbound Parse.
   Known process risk (accepted): if staff keep reading that mailbox in a
   normal mail client, replies can happen outside the shared inbox.
4. **Modules are opt-in for everything.** No module is enabled at
   corporation creation; ops enables each one explicitly (no self-serve UI).
   Presence of a row = enabled. Opting out later freezes, never deletes:
   history stays readable, no new sends, no replies, inbound silently
   swallowed (webhook still returns its normal success response so the
   provider doesn't retry).
5. **Multiple addresses per corporation, per channel, with one primary.**
   Primary governs where *new* conversations start. The address a thread
   uses is bound at thread creation and never re-resolved — changing the
   primary later must not move an existing thread's From. Old addresses stay
   live for inbound indefinitely (a customer replying to a months-old email
   goes to whatever address we sent from).
6. **Threading**: header-based (`Message-ID` / `In-Reply-To` / `References`),
   normalized-subject fallback for broken chains, unmatched reply → new
   thread. Threads are subject-named, listed by most recent activity with
   visible timestamps.
7. **Two selectors**: A = which thread is viewed (specific or "all"
   flattened); B = which thread a reply targets, incl. "new thread". B
   inherits A when A is specific (selector hidden unless >1 thread exists);
   when A is "all", B defaults to "new thread" if the customer has never
   been emailed, else the latest thread. No reply-from-message shortcut.
8. **Rich text starting set**: bold, italic, underline, bullet/numbered
   lists, links, paragraph breaks — nothing else. Editor: TipTap, **already
   in `web/`** (`@tiptap/react` + starter-kit) — zero new frontend deps.
   Server-side sanitizer: `symfony/html-sanitizer` (one new composer dep,
   approved). The sanitizer allowlist covers both directions — what we emit
   and the far richer HTML real mail clients send back.
9. **Storage**: `messages.body` stays the plain-text canonical form on every
   channel (search, previews). New generic nullable `messages.body_html`
   carries the rich version when a channel produces one. Rendering is a
   presence check, never a channel check. NOT in `channel_data` — that is
   for rarely-consulted transport metadata (SID, from/to, References chain).
10. **No CC/BCC** (fits in `channel_data` later without schema change). No
    internal notes, no auto-signatures. Attachments reuse `MessageAttachment`
    as-is — it was built channel-agnostic on purpose.
11. **Dev safety**: outbound is already safe (SMS `log` transport, email →
    Mailpit). Inbound simulation is the gap → dev-only `routes/dev.php`
    (`local` env gate, no auth — same posture as `_test/*`) + a standalone
    host-run `devtools/` React app. Deliberately NOT reusing
    `routes/testing.php`, which exists for the Playwright stack.

12. **Starting a conversation takes an address of either kind.** The
    "start a conversation" recipient field accepts a phone number *or* an
    email address, and what was typed decides the channel: a string
    containing `@` is read as a mailbox, anything else as a number. The
    field is validated as *one of the two* before anything is created — a
    string that is neither a dialable number nor a well-formed mailbox is a
    field error in the dialog, and the same check runs again server-side,
    because the dialog's sniffing is a convenience and never the guarantee.
    No new validator: `PhoneNumber::isValid()` already exists, and the email
    side reuses the adapter normalization from slice 4. An address on a
    channel the firm is not switched on for (or has no address of its own to
    send from) is refused by name — "this firm has no email channel" —
    rather than accepted and failed at the send; that judgement comes from
    slice 2's `ChannelAvailability`, never re-derived in the browser.

## Data model changes

```
corporation_addresses   corporation_id · address_type (phone|email) · value
                        (normalized) · is_primary · verified_at nullable ·
                        timestamps · softDeletes
                        partial unique (corporation_id, address_type)
                          where is_primary and deleted_at is null
                          → one primary per channel family per corp
                        partial unique (address_type, value)
                          where deleted_at is null
                          → an address belongs to one corporation
                        Replaces corporations.sms_number (data migrated,
                        column dropped).

corporation_modules     corporation_id · module (sms|email) · timestamps
                        unique (corporation_id, module) — presence = enabled.
                        Row deleted = opted out (freeze semantics live in
                        code, not here; events record enable/disable).

messages                + body_html nullable text (generic, channel-agnostic)

threads                 + subject nullable string (email thread label; null
                          for SMS) 
                        + corporation_address_id nullable FK (the From
                          binding, set at creation, never re-resolved)
                        ⚠️ threads carries a partial unique index — this
                        migration MUST re-assert it (see the SQLite trap in
                        CLAUDE.md and 2026_08_23_000008 for the pattern).

customers               email column DROPPED — email addresses become
                        identities (address_type = email), one store, the
                        exact phone precedent. Backfill existing values into
                        identities first. Customer::findByEmail resolves via
                        identities. Erasure already soft-deletes identities,
                        so scrubbing email follows for free.
                        ⚠️ customers is also on the partial-index trap list —
                        re-assert.

email_message_ids       (adapter-owned, email module only) thread_id ·
                        message_id (unique) — maps every Message-ID we have
                        seen or sent to its thread, because a reply's
                        In-Reply-To may name any message in the chain, not
                        the root. Written only by the email adapter. See open
                        question 5.

Enums: AddressType::Email (defaultChannel → Channel::Email) ·
       Channel::Email · Module (sms|email) ·
       ChannelStatus (active|read_only|unavailable)
```

## Slices

### Slice 1 — Corporation addresses (retrofit SMS)

Goal: `corporation_addresses` replaces `corporations.sms_number`; behavior
identical afterwards. No email anywhere yet.

- [x] Migration: create table, backfill one row per corp with a
      `sms_number` (type phone, primary, verified_at = now), drop the column.
- [x] `CorporationAddress` model + `Corporation::addresses()` /
      `primaryAddress(AddressType)` helpers.
- [x] Update the three readers: `TwilioWebhookController::resolveCorporation`
      (lookup via table — any address of the kind, not just the primary, so a
      reply to an old number still routes), `SmsChannelAdapter::send` (From =
      corp primary phone; the thread's bound address half waits for
      `threads.corporation_address_id` in slice 3, and the send site carries a
      comment saying so), `routes/testing.php` seed (create an address row).
- [x] Generalize `app:attach-sms-number` → `app:attach-address`
      (`--type=phone|email --primary`), keeping its uniqueness check and
      event recording (`corporation.address_attached`). `--type` currently
      accepts only `phone`, since `AddressType` has no email case until
      slice 3 — the restriction is `AddressType::tryFrom`, not a hardcoded
      list, so the command needs no edit when that case lands. Normalization
      is a `match` with no default arm: adding the case therefore throws
      `UnhandledMatchError` the first time `--type=email` is actually run
      (runtime, not compile time — there is no static analysis in the lint
      gate, and no test trips it). Deliberate: a passthrough default would
      store an un-normalized address, and inbound routing is an equality
      lookup on that column, so the failure would be silently unmatched mail
      instead of a loud error on an ops command.
- [x] Events for attach/detach/primary-change. Detach got its own
      `app:detach-address` command so the event has a real emitter: it soft-
      deletes and clears `is_primary` (a soft-deleted primary would keep
      occupying the one primary slot), and never auto-promotes a successor.
- [x] Verify: `make unit` (205 passed) + `make test` (18 passed) green with
      zero behavior change.

### Slice 2 — Modules (opt-in) + computed channel status

Goal: the module gate exists, SMS enforces it, the UI consumes one enum.

- [x] `corporation_modules` migration + `Module` enum +
      `Corporation::hasModule()`. Backfill: enable `sms` for every existing
      corporation that has a phone address (preserves current behavior for
      live data — see open question 4). Fresh corps get nothing. `Module`
      maps to an address space, not a transport (`Module::addressType()`,
      `AddressType::module()`), so switching SMS off takes everything that
      reaches a phone number with it.
- [x] `app:enable-module` / `app:disable-module` artisan commands + events
      (`corporation.module_enabled` / `_disabled`). Enabling a module for a
      firm with no address of that kind warns that it still cannot send.
- [x] Enforcement in the core actions (`SendOutboundMessage`,
      `ComposeMessage`): module off → validation error. Server-side, so a
      bypassed UI still can't send. `ComposeMessage` checks *before*
      resolving, so a refusal invents no customer and no identity.
- [x] Webhook swallow: `TwilioWebhookController` returns the normal empty
      TwiML 200 for a resolved corp whose sms module is off — no ingest, no
      record, no error (an error would make Twilio retry). The swallow sits
      after the signature check, so it is not reachable by forgery.
- [x] Computed `ChannelStatus` per identity in the conversation payload
      (`ConversationController`): active / read_only (module off + prior
      history on that address type) / unavailable (no module, no address
      provisioned, or opt-in channel without prior inbound). Identities with
      no history on an off module are simply omitted. Corp-level channel
      availability exposed too, for the compose empty-state
      (`availableAddressTypes` on the conversation index). The rules live in
      `App\Inbox\ChannelAvailability`, not in the controller, because the
      per-identity and corporation-level answers are the same rules.
- [x] `web/`: composer + channel area switch on `ChannelStatus` only — the
      frontend never re-derives module logic. Read-only = visible, greyed,
      no reply. Corp with zero available channels → real empty state on
      "start new conversation" instead of a failing send.
- [x] `_test/seed` enables the sms module (`sms_module: false` opts out);
      `_test/module` toggles it mid-suite. Playwright E2E for the read-only
      state, the compose empty state, and switching back on.
- [x] Verify: `make unit` (220 passed) + `make test` (21 passed).

Decided while building: the dev seeder (`DatabaseSeeder`) stays **bare** —
no address, no modules — so a seeded dev firm matches a really-provisioned
customer, and the empty states are what a developer sees until they run
`app:attach-address` and `app:enable-module` by hand.

### Slice 3 — Email domain foundation (no adapter yet)

Goal: the core speaks email as an address type; still nothing sends or
receives.

- [x] `AddressType::Email` + `Channel::Email` cases, plus `Module::Email`
      (forced: `AddressType::Email->module()` has to answer). Normalization
      landed as `App\Support\EmailAddress`, the mirror of `PhoneNumber`, and
      **canonical form moved onto `AddressType::normalize()` as the single
      authority** — it belongs to the address *space*, not the transport, or
      SMS and MMS to one number could disagree and split a person in two.
      `SmsChannelAdapter::normalizeAddress()` now delegates there, and the
      console `NormalizesAddresses` trait was deleted rather than growing a
      second copy of the match. `AddCustomerAddress` takes an `AddressType`
      instead of a `Channel`, so writing a mailbox on a record works with no
      adapter registered, and carries an `$errorKey` so a refusal lands on
      the right form field.
- [x] Migration: backfill `customers.email` → email identities, drop the
      column, re-assert customers' partial indexes. `Customer::findByEmail`
      resolves via identities and answers "nobody" for an unparseable
      address rather than throwing. `customers.email` survives as a
      read-only accessor over `addressFor(Email)`, so payloads and forms
      keep their `email` key. Updated `_test/customer`, `CustomerController`
      (store/update/erase), `CustomerPhoneController`, `CustomerFactory`
      (`withEmail()`), and both customer form requests — the
      `Rule::unique('customers','email')` is gone, since one live owner per
      address is the identities index and `AddCustomerAddress` enforces it
      with a message that can name the holder. The seeder needed no change
      (it only ever set *user* emails).
- [x] Migration: `messages.body_html`; `threads.subject` +
      `threads.corporation_address_id` (threads' two partial indexes
      re-asserted and verified present after the SQLite rebuild).
- [x] `composer require symfony/html-sanitizer`; `App\Inbox\Support\
      HtmlSanitizer` pinning the allowlist to the rich-text starting set +
      html→plaintext derivation for `body`. Two things the obvious
      implementation gets wrong, both now covered by tests: unknown elements
      must be **blocked, not dropped**, or a mail client's table wrapper
      empties the message; and `dropElement()` does *not* remove the text of
      raw-text elements (`style`, `title`, `head`), which leaked CSS as the
      message body until they were cut out before the sanitizer ran.
      `toPlainText()` deliberately takes the **original** HTML — sanitizing
      has already thrown away the block tags that say where one paragraph
      ends.
- [x] Message rendering in `web/`: presence check on `bodyHtml`, sanitized
      server-side before storage (defense at write), rendered without
      re-interpretation client-side. A scoped `.rich-body` block in
      `index.css` puts back the list markers and block margins Tailwind's
      preflight strips.
- [x] Update `docs/product.md` (per-case address line → per-corporation
      decision).
- [x] Verify: `make unit` (236 passed) + `make test` (21 passed).

Also done here, because adding the enum case made it reachable:
`ChannelAvailability` now reports a channel with **no registered adapter** as
`unavailable`. Without it, switching a firm on for the email module before
slice 4 would not refuse politely — it would throw out of `ChannelRegistry`.

### Slice 4 — Email channel adapter + SendGrid + threading

**Provider-independent half done; the three provider-shaped edges are still
open** (open question 1). Email now sends and threads end to end against
Mailpit; what is missing is a way for real mail to *arrive*, which is the
webhook, and the DNS verification that would let a real firm's address be
marked usable.

Goal: a firm with a verified address receives real email into the inbox and
sends replies out. ⚠️ Only the provider-specific edges are blocked on the
supplier pick (open question 1): the concrete inbound webhook controller +
its verification scheme, prod SMTP credentials, and the expected DNS values
for `app:verify-email-dns`. Everything else — adapter, transports (dev
outbound goes to Mailpit via the Laravel mailer), threading, sanitizer,
`email_message_ids` — is provider-independent and buildable now; inbound is
exercised through the `_test`/`_dev` simulation routes until the real
webhook lands. SendGrid names below are placeholders.

- [x] `App\Inbox\Email\EmailChannelAdapter` implementing `ChannelAdapter`
      (channel Email, addressType Email, lowercase normalization,
      capabilities: threads=true, subjects=true).
- [x] Transports mirroring SMS: `EMAIL_TRANSPORT=log|mail` — `log` default;
      `mail` uses the Laravel mailer, so dev/test hit Mailpit via the
      existing SMTP config and **prod sends through SendGrid's SMTP relay**
      (`smtp.sendgrid.net` + API key — zero new PHP deps, no SendGrid SDK).
      From = thread's bound corporation address; custom `Message-ID`;
      `In-Reply-To`/`References` set from the thread's chain; sanitized
      HTML + plaintext alternative; returned ids into `channel_data`.
- [x] Thread resolution: new optional interface
      `App\Inbox\Contracts\ResolvesThreads`
      (`threadFor(Identity, CanonicalInbound): Thread`);
      `IngestInboundMessage` uses it when the adapter implements it, else
      the default single thread (SMS unchanged). Email implementation:
      match `In-Reply-To`/`References` against `email_message_ids`; fallback
      normalized-subject match (strip `Re:`/`Fwd:`/`SV:`/`VS:` — Danish
      clients) against that identity's threads; no match → new thread named
      by subject. Every seen/sent Message-ID recorded to
      `email_message_ids`. `CanonicalInbound` gains nullable
      subject/headers fields.
- [x] `config/inbox.php` `email` block + `.env.example` entries
      (`EMAIL_TRANSPORT`, `INBOX_EMAIL_WEBHOOK_TOKEN`).
- [x] PHPUnit: threading matrix (root match, mid-chain In-Reply-To, subject
      fallback incl. `SV:`, unmatched → new thread, cross-identity
      isolation), webhook token fail-closed, module swallow, sanitizer
      round-trip, attachment ingestion. Pin `EMAIL_TRANSPORT=log` in
      phpunit.xml + test stack (same reasoning as `SMS_TRANSPORT`).
- [x] Verify: `make unit` (264 passed) + `make test` (23 passed); manual:
      Mailpit shows an outbound reply with the right From, Subject,
      `Message-ID`, `In-Reply-To` and `References` — checked on the wire via
      Mailpit's API, not just in the log transport.

**Blocked on the provider, and deliberately not stubbed:**

- [ ] The inbound webhook controller and its verification scheme. The
      adapter's `receive()` already takes a flat, provider-neutral payload
      (`from`/`to`/`subject`/`text`/`html`/`message_id`/`in_reply_to`/
      `references`), so whoever is chosen needs one controller that renames
      their fields into that shape and one middleware that checks their
      signature. Nothing else moves.
- [ ] `app:verify-email-dns`. The mechanism is provider-independent but the
      expected MX/SPF/DKIM *values* are not, and inventing them would be
      worse than leaving it out. Until it exists, `app:attach-address
      --verified` is the manual stamp — which is what dev uses.
- [ ] Ops runbook naming the provider's domain-authentication steps.

**Decided while building:** `ChannelAvailability` now requires
`verified_at` on the sending address, per this slice's own note. Mail from an
unauthenticated domain is spam-filed, which looks to the firm like silence,
so an unproved address is `unavailable` rather than `active`. That made a
manual override necessary before the DNS command exists — hence
`--verified`.

### Slice 5 — Inbox UI: channel selector, thread selectors, rich compose

Goal: the two-selector model in `web/`, per-channel unread, TipTap compose.
`app/` is untouched (shell only, per scope rules).

**Done.** The first half landed before slice 4's adapter existed (per-address
unread badges, selector A, address-typed compose); the rest followed once it
did.

- [x] Conversation header: all of the customer's identities where the
      number sits today, each with its `ChannelStatus` and a per-channel
      unread badge. Unread derived from the existing `conversation_reads`
      watermark — any inbound message newer than the last-seen id, grouped
      by thread → identity — **no new read-tracking schema**. (Same
      semantics as today: opening the conversation marks everything read.)
      One ordering trap: the counts must be computed *before* `markReadBy`
      in the same request, or they are always zero.
- [x] Selector A (view): "all" flattened timeline (default) or one thread;
      thread list subject-labeled (falling back to the address when there is
      no subject), sorted by last activity, timestamps shown. `?thread=` on
      the show endpoint narrows the timeline, resolved through the
      conversation's own threads so an id from another firm selects nothing.
      Rendered only when there is more than one thread — which, until email
      lands, is only a customer reachable at two numbers.
- [x] Selector B is a **target bar** directly above the composer, shown
      only for the email channel (SMS: no bar, single stream). Existing
      thread targeted → bar shows that thread's subject + last-activity
      time, read-only — doubling as the "replying into this thread"
      confirmation. "New thread" targeted → the bar swaps to an inline
      required subject input (Enter moves focus to the body; Send disabled
      until filled). The bar's dropdown is the selector itself (threads by
      recency + "new thread" on top), rendered only when there is an actual
      choice. Defaults per binding decision 7: inherits A when specific; on
      "all", new-thread if never emailed, else latest thread. No modal —
      a modal would fork the compose experience (second TipTap) or
      interrupt for a single field.
- [x] The existing from-scratch "compose to a number or customer" flow
      takes an email address as well as a number (binding decision 12), and
      gains a subject field once what was typed resolves to email — that
      flow is already a discrete recipient-picking step, so the subject
      slots in there rather than in any new modal. The surfaces this
      actually touches, all of which are phone-only today:
      - `start-conversation-dialog.tsx`: the `looksLikeNumber` regex becomes
        a three-way read — email / number / neither — and decides which
        channel the draft is for. "Neither" is a field error shown straight
        away, not a server round-trip.
      - `GET /api/conversations/resolve` takes a `phone` parameter and
        hardcodes `Channel::Sms`. It becomes address-typed: takes the raw
        string, decides the kind, normalizes through that kind's adapter,
        and answers with the kind it settled on so the dialog can label the
        draft. Its `phone` validation key goes with it.
      - `StoreConversationRequest`, `ConversationController::store` and
        `ComposeMessage` all carry a `phone` field and a hardcoded
        `channel: Channel::Sms`; both generalize to an address plus the kind
        derived from it. The `phone` error key moves too — the dialog has
        one recipient field, so it needs one key.
      - `CustomerOption.phones` in `web/` and the matching `phones` key in
        `CustomerController` become every identity with its address type, so
        `pickedIsReachable` can be true for a customer the firm only holds a
        mailbox for, and picking that customer starts an email conversation.
      - Validation of an unreachable-but-well-formed address (right shape,
        wrong channel for this firm) reuses `ChannelAvailability`.

      **Done**, except the subject field, which needs a channel that can
      actually carry one. `AddressType::sniff()` / `isValid()` are the
      server-side decision and `sniffAddress()` in `web/src/pages/inbox/
      shared.ts` the client-side convenience; the `phone` request field and
      error key are now `address` throughout; `ComposeMessage` takes an
      `AddressType` rather than a `Channel` and no longer needs the channel
      registry at all. Composing to a bare customer picks a kind the firm can
      actually send on rather than the first one it holds.
- [x] TipTap compose for email (bold/italic/underline/lists/links only —
      StarterKit trimmed), plain textarea unchanged for SMS. Payload sends
      html + server derives/validates both `body` and `body_html` through
      the sanitizer.

      `canFormat` now gates the **extensions themselves**, not just whether
      the toolbar looks enabled — a disabled-looking button still answers
      Mod+B, so on SMS the marks are simply not registered. It follows the
      *exchange being replied into*, never the conversation as a whole: the
      old `identities.every(addressType === 'phone')` check is exactly what
      put `<p>` tags into SMS bodies once customers could hold a mailbox.

      The plain text is **derived** from the HTML server-side rather than
      taken from the client, so the two forms cannot drift apart, and a
      channel that carries no formatting has its html dropped rather than
      sent as tags (`ChannelCapabilities::supportsRichText`).

- [x] API: `ConversationMessageController` / `ComposeMessage` accept
      channel + thread target (existing thread id | new + subject);
      validation ties thread → identity → conversation → corporation
      (tenant-scope rule).
- [x] `_test/inbound-email` in `routes/testing.php` (the Playwright stack
      needs it, exactly like `_test/inbound-sms`; this is separate from the
      dev-only routes in slice 6).
- [x] Playwright: email arrives → badge on email identity → open thread →
      reply lands in Mailpit with the right From and subject; two-thread
      customer → selector B defaults and manual switch; new-thread compose;
      starting a conversation by typing an email address; a recipient that is
      neither a number nor a mailbox refused in the dialog; typing an email
      at a firm not switched on for email refused by name.

      The seeded firm stays an **SMS firm** — it gets a verified email
      address but no email module — because other specs assert on "a firm
      with no email channel" and must keep meaning it. `email.spec.ts`
      switches the module on for its own run and off again after.
- [x] Verify: `make unit` (264 passed) + `make test` (27 passed).

### Slice 6 — Dev inbound simulator (`devtools/` + `routes/dev.php`)

Goal: a developer on `make dev` can fake a customer texting or emailing in,
with a UI, touching no real recipient.

- [ ] `routes/dev.php`, loaded only when `app()->environment('local')`
      (never testing, never prod — fail closed). `_dev/inbound-sms` and
      `_dev/inbound-email`: same pattern as the `_test` twins — skip
      provider verification, run the real adapter `receive()` + ingest
      pipeline. No auth (environment gate is the fence, `_test/*`
      precedent).
- [ ] `devtools/`: standalone Vite + React app, own package.json, host-run
      (no Dockerfile, no Compose service — the `app/` precedent). Minimal:
      pick corporation address/channel, fake sender, subject (email), body,
      send. No Tailwind/shadcn/TanStack stack.
- [ ] Outbound viewer pane in `devtools/`: a `_dev` endpoint listing recent
      outbound messages with wire-level detail (from/to/body/fake SID), so
      devtools shows what would have hit the wire for SMS the way Mailpit's
      UI does for email. (Email outbound needs nothing: dev mail already
      lands in Mailpit — confirmed choice, no hosted Mailtrap.)
- [ ] `make devtools` target; note in CLAUDE.md's command table.
- [ ] Verify: manual — simulate an SMS and an email in dev, watch both land
      in the inbox; confirm the routes 404 in the test stack.

### Post-slices

- [ ] Update CLAUDE.md (modules, addresses, email channel, devtools) and
      `docs/product.md` current-state section.

## Open questions — status

1. **Provider: OPEN — supplier search in progress** (blocks slice 4 only).
   Scope grew: the phone side must also cover **voice** (inbound/outbound)
   alongside two-way SMS; MMS a plus, not required. A number lives with one
   provider, and SMS + voicemail on the same firm number must share an
   identity — so voice and SMS cannot be split across suppliers, which
   rules out the cheap Danish SMS-only aggregators (GatewayAPI, InMobile,
   CPSMS: no voice) for the phone side. Email may be a separate supplier.
   Research (2026-08): Twilio DK = $0.0592/SMS out + $0.0075 in + $15/mo
   per number; **Sinch DK = $0.0431/SMS out, inbound free** (~27% cheaper
   outbound, and free inbound matters for a product built on customers
   replying); 46elks (SE) = €0.055/SMS, voice + MMS, transparent but
   pricey; Infobip/Bird rates still behind sales. **Shortlist: Sinch first**
   (Swedish, SMS+voice+DK numbers, owns Mailgun/Mailjet → email under the
   same roof), **Infobip** second (Croatian, full suite incl. email),
   **46elks** third (no email, transparent pricing). Twilio stays the
   working fallback. **Number porting is a hard criterion**: a firm must be
   able to transfer its existing Danish number in (product.md's "keep the
   firm's existing phone number" goal). Danish regulation mandates number
   portability, so this is about each provider's port-in process, not
   possibility — Twilio documents DK porting; Sinch runs its own Nordic
   carrier network (confirm process via sales); 46elks doesn't document it
   publicly (ask). Next step (Sebastian): DK rate cards from Sinch and
   Infobip at expected volume; confirm DK port-in process; ask Sinch about
   joint invoicing with Mailgun/Mailjet. Email-side criteria unchanged: signed inbound webhooks,
   per-domain DKIM/SPF, EU-friendly, SMTP-relay outbound (no SDK);
   Postmark remains the email fallback if the CPaaS pick has no email arm.
2. ~~Inbound Parse signing~~ — folded into question 1 (it's a criterion for
   the provider choice, not a separate decision).
3. ~~Dropping `customers.email`~~ — **confirmed**: identities are the one
   store, column drops in slice 3, churn accepted.
4. ~~Module backfill~~ — **confirmed**: auto-enable `sms` for existing
   corporations with a number; fresh corporations start with zero modules.
5. ~~`email_message_ids` adapter-owned table~~ — **confirmed**: the
   channel-specifics rule bends to include adapter-owned schema; no
   channel column leaks into core `messages`.
6. ~~Subject entry UI~~ — **confirmed**: inline target bar above the
   composer (selector B and the subject as one element), no modal; the
   from-scratch new-conversation flow gains a subject field for email.
   Detailed in slice 5.
