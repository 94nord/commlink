# Commlink — Product

A shared SMS/email inbox for trade firms, layered on top of Ordrestyring
(order/job management software used by Danish trade firms of 10–20
employees) — **not** a replacement for it.

## Core flow

1. A visit gets scheduled against a case in Ordrestyring.
2. That triggers an outbound notification (SMS first, email later) to the
   customer.
3. The customer replies.
4. The reply lands in a shared inbox the whole firm works from — not one
   inbox per staff member.

## Why

Ordrestyring owns scheduling and job management, but has no real customer
communication layer. Firms currently coordinate customer SMS/email by hand,
across personal phones, with no shared visibility into who's talked to whom.
This product owns the communication surface only; Ordrestyring stays the
system of record for customers, cases, and visits.

## Data model

- **Conversations** are grouped at the **customer** level, with an optional
  link to a specific case — a conversation doesn't require a case.
- **Channel matching**: an address resolves to a customer, whatever kind of
  address it is — SMS by phone number, email by the address the customer
  writes from. Both are `identities`, one store, so an address written on a
  customer's record is immediately one the inbox can answer on.
- **The firm's own addresses are per-corporation, not per-case.** A firm owns
  one or more numbers and mailboxes on its own DNS-authenticated domain, with
  one primary per kind deciding where *new* exchanges start. The per-case
  address idea this document used to describe is deferred until the
  Ordrestyring sync exists — there are no cases to address yet. See
  `docs/email-plan.md` for the decision and its consequences.
- The app keeps its own synced copy of Ordrestyring's customers, cases, and
  planned visits (read via an incremental background sync), so channels can
  plug in via an adapter without the core conversation model depending on
  Ordrestyring's shape directly.

## Collaboration model

Three separate concepts, deliberately not conflated:

- **Ownership** — one responsible person per conversation, so nobody
  double-replies or drops it.
- **Watchers** — who gets notified: automatically if you own the
  conversation or are active on the case, plus anyone added manually.
  Notifications go to watchers, not the whole firm.
- **Roles** — office/admin can see and write to every conversation; field
  staff can see everything but only reply to customers on their own jobs.
  All conversations are visible to all by default.

## Notifications

Inbound customer messages get matched to a customer/case and push a
notification to the relevant staff's phones. Field staff work from a mobile
app (view, reply, receive push); office works from a web inbox.

## MVP scope

Ordrestyring sync + a dedicated SMS number per firm + visit notifications +
inbound replies into a shared inbox (assignment, watchers, roles) + a mobile
app with push + a web inbox.

**Later, deliberately not now**: email channel, keeping the firm's existing
phone number, voice, further Ordrestyring-adjacent integrations.

## Current build state

The repo has been restructured for this product (`server/` Laravel API +
Sanctum, `web/` React SPA) on top of the tenancy foundation — Staff and
Customer. The inbox core model has landed: conversations, identities,
threads and messages, with the link/merge/unlink mechanics and phone
normalization (slice 1 of `docs/inbox-plan.md`).

The channel adapter interface and the Twilio SMS adapter have landed too
(slice 2): a firm with a number attached receives real texts into the
database and can send them back out. So has the inbox API (slice 3), and the
web inbox itself (slice 4) — the queue, a conversation's merged timeline,
replying, composing to a number or a customer, and putting a name to an
unknown number. The inbox has replaced the dashboard as the landing page.

**The product is usable end to end**: a text arrives, the firm sees it, and
somebody answers.

Conversations group by **person**, and a person is reached at addresses: a
phone number is one address whether the customer texts it, sends a picture
to it, messages it on WhatsApp or leaves a voicemail, so those channels will
join the same exchange when they arrive rather than splitting the person up.
An unknown number becomes a customer nobody has named yet — shown in the
inbox by their number, kept out of the customer list until somebody says who
they are.

Watchers and notifications were built and then removed again — deferred
until the team is ready to decide how notification should behave.

The mobile app (`app/`, Expo + React Native) exists as a shell: a field-staff
login on a phone reaches the API and comes back authenticated, holding a
bearer token in the device keychain. It has no inbox screens and no push yet
— this is the floor those stand on.

Still to come: archiving, watchers/notifications, and the email channel. No
Ordrestyring sync yet. See `CLAUDE.md` for the architecture and what's
actually built versus planned.
