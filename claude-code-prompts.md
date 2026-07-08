# Claude Code — Phased Build Prompts
 
Run these **one at a time**, reviewing between each. They deliberately don't build
everything at once, and they keep the UI **generic/unstyled on purpose** so you can
apply a real design later without rework.
 
**Before you start:** put the working doc in the repo at `docs/product-spec.md`.
Every prompt treats it as the source of truth.
 
---
 
## Standing rules (these apply to every prompt below)
 
Paste this block once at the top of each phase, or tell Claude Code to treat it as project-wide:
 
```
Standing rules for this project:
- Source of truth is docs/product-spec.md. If something isn't specified there, make the
  smallest reasonable assumption and note it — do NOT invent back-office/business features
  (no invoicing, routing, job management, lead CRM). This product only owns the
  customer-facing side.
- Build ONLY the current phase. Do not scaffold, stub, or start later phases. Stop when the
  phase's "done when" is met and summarise what you did.
- Design stance — keep the UI intentionally generic, no visual identity yet:
    * Put every colour, font, spacing and radius behind design tokens (CSS variables) with
      neutral placeholder values, so a real design can be dropped in later without touching
      markup or logic.
    * Do NOT adopt a branded/opinionated component library or design system. No brand, logo,
      colours, imagery, animation, or decorative polish.
    * Keep presentation isolated from business logic so the UI can be fully re-skinned later.
- Multi-tenant from the start: every domain record is scoped to a corporation.
- Use the "optional external reference" pattern: records that may later link to a host system
  carry an optional {system, external_id}, absent for now (we're standalone-first).
- Migrations must be reversible. Keep a seed that creates one corporation + one admin user.
- State invariants as hard requirements; you choose the implementation.
```
 
---
 
## Phase 0 — Foundation & conventions
 
```
Phase 0 — foundation only, no features. Set up a new project for the product in
docs/product-spec.md using [MY STACK — e.g. TypeScript + a standard web framework +
PostgreSQL + a migration tool; PostgreSQL is recommended because of the concurrency
guarantees later]. Establish: project structure, DB + migrations, config/env handling,
the multi-tenant convention (a corporation/tenant id on domain tables), and a skeleton
event log (an events table + a way to emit and subscribe to events — no consumers yet).
Add a neutral UI shell per the design stance (a plain layout + a design-token stylesheet
with placeholder values, nothing branded) and a health-check route. Add the seed (one
corporation + one admin). Build no domain features. Done when: the app boots, migrations
run, and the seed works.
```
 
## Phase 1 — Data model & migrations
 
```
Phase 1 — data model only, per docs/product-spec.md. Add migrations + models for:
Corporation; User (belongs to a corporation, role defaults to admin); Customer/party
(corporation-scoped; the DURABLE anchor; a separate contact-identities table for multiple
emails/phones); Job (belongs to a party, carries an address, optional external reference,
optional project grouping — LATE-BOUND: it may not exist for a given interaction yet);
Booking (belongs to a party ALWAYS; job link optional/nullable; references a resource and
a time slot); Resource (one per corporation for now, with a capacity integer); Availability
window (belongs to a resource); Sendout (corporation-scoped; appointment length, buffer,
absolute close-off date; targets a set of customers); Notification (outbox row: recipient,
type, status). All tenant-scoped. Enforce at the DB level that a resource can never be
double-booked or exceed its capacity for overlapping times — you choose the mechanism, but
it must hold under concurrent inserts. Reversible migrations + updated seed. No business
logic or UI beyond migrating and seeding. Done when: migrations + seed run and the
invariant is enforced by the schema.
```
 
## Phase 2 — Availability & slot computation
 
```
Phase 2 — availability + slot computation, per the spec. Implement a service that, given a
resource + a sendout (appointment length, buffer, capacity) + the resource's availability
windows, computes the list of free slots ON READ — capacity-aware, subtracting all active
(non-cancelled) bookings, never offering a slot that would breach capacity. Slots are
computed, never stored. Add a minimal internal endpoint or test to inspect the output. No
customer UI, no booking writes yet. Done when: I can call it and see correct free slots that
shrink as bookings exist.
```
 
## Phase 3 — Self-service booking (the core)
 
```
Phase 3 — self-service booking, per the spec. Implement booking a computed slot: find/create
the party, create the booking (party required, job late-bound/optional), and enforce the
no-double-book / no-over-capacity invariant ATOMICALLY under concurrency (on conflict, fail
cleanly so the caller can retry another slot). Add a minimal, generic booking page reachable
with a plain identifier for now (token comes next phase) that lists free slots and books one.
No reschedule yet. Done when: two simultaneous attempts on the last slot can't both succeed,
and a booking reduces the free list.
```
 
## Phase 4 — Customer access via magic link
 
```
Phase 4 — customer access, per the spec. Add scoped, unguessable, expirable tokens that
resolve to a (corporation, party, scope), where scope is a single booking or a party's whole
set. Gate the booking page behind a token. Add a "resend my link" flow that sends only to an
address already on file (never a typed address). Build the resolver as a pluggable auth
abstraction, so OTP/social/accounts can later be added as alternate ways to reach the same
(corporation, party, scope) — but implement only magic link now. Keep pages generic. Done
when: the page is only reachable with a valid token and resend works to on-file addresses.
```
 
## Phase 5 — Self-reschedule + close-off
 
```
Phase 5 — self-reschedule, per the spec. Let a customer reschedule their own booking to
another free slot until the sendout's absolute close-off date; lock it after. Claim the new
slot BEFORE releasing the old one (respect the invariant); if the claim fails, keep the old
and tell them to pick another. Emit a "booking changed" event. Minimal UI on the token-gated
page. Done when: reschedule works before close-off, is blocked after, and never loses a slot
to a race.
```
 
## Phase 6 — Admin schedule management
 
```
Phase 6 — admin schedule management, per the spec. Give the admin a minimal, generic view of
the schedule and let them manually move, resize, or cancel any single booking to any time
(respecting the invariant; a booking may sit off-grid). Add the availability-edit flow: when
an admin changes a resource's availability and active bookings now fall outside the new
windows, detect them and prompt to leave (kept as-is) or cancel (released). Emit events for
moves and cancels. Done when: admin can move/cancel a booking and the leave/cancel prompt
appears for stranded bookings.
```
 
## Phase 7 — Sendouts + email on the event spine
 
```
Phase 7 — sendouts + email, per the spec. Let an admin create a sendout targeting a specified
list of customers (a simple manual list/upload is fine for the source) and generate a
per-customer magic link. Wire the email triggers as SUBSCRIBERS to the event spine: invite,
reschedule confirmation, cancellation, appointment-change. Support bulk sendout via the
Notification outbox drained by a worker that sends with per-recipient status + retry. Abstract
the channel (email now; SMS later is just another channel). Bulk actions show a preview of how
many contacts will be emailed. Done when: creating a sendout emails each customer a working
booking link, and cancel/change (single or bulk) queue and send notifications with status.
```
 
## Phase 8 — Hardening (optional)
 
```
Phase 8 — hardening, per the spec. Add GDPR basics (customer data minimisation, an
erasure/retention path), an audit view over the event log, and tests around the booking
invariant (including concurrent-booking tests) and the reschedule / close-off / availability-
edit flows. Done when: the invariant tests pass under concurrency and an erasure path exists.
```
 
---
 
## Notes
 
- You can merge adjacent phases into bigger runs if you want fewer, larger review cycles
  (e.g. 3+4, or 6+7) — but keep 1, 3, and 7 as their own runs; they carry the load-bearing
  bits (schema/invariant, concurrency, the event/outbox spine).
- Nothing here commits a stack beyond "PostgreSQL recommended"; fill in `[MY STACK]` in Phase 0.
- Nothing here commits a visual design — that's deliberate. When you're ready to design, it's a
  separate pass that only touches the token stylesheet and presentation layer.
```