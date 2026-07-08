# Self-Service Booking Layer — Working Doc
 
_Last updated: 2026-06-12_
 
A **customer-facing self-service layer** for service businesses — it owns the
*customer's side* of an interaction (booking, rescheduling, status, comms) and
hands off to whatever back-office the business runs. First concrete scenario:
a **carpenter's apartment visits** — a group of customers each book their own
visit slot from a link in their email. **Broad by design**: the visit is a
typed primitive, so surveys, progress checks, handovers, and (future) lead
surveys all use the same machinery.
 
This is a **product/functional** doc — *what* it does. Technical design is left
to implementation, except the few load-bearing invariants noted below.
 
Living doc: settled → **Decisions**; unsettled → **Open questions**;
planned-not-now → **Future direction**.
 
---
 
## Positioning
 
- Owns the **customer-facing surface** (book, reschedule, status, communicate); **never the back-office** (invoicing, routing, job management, lead CRM) — those belong to the host system.
- **Standalone-first, integration-capable, host-agnostic.** Build standalone for the carpenter now; host integration (Minuba / Clientflow / e-conomic) is additive later, and is the source of truth for business data when connected.
- **Versatility through composable primitives, not feature sprawl.** New scenarios are configurations of the same primitives; anything drifting into back-office territory is out of scope.
---
 
## Core entities
 
- **Corporation** — the firm/account/tenant. Owns everything below.
- **User** — an admin inside the corporation.
- **Customer** — the client (was "party"): the **durable anchor**, corporation-scoped (never global — same person at two corporations = two customers), holds a **single email for now** (multiple contact points are future), owns the visits/cases. Not a login account. Optional external reference to the host's record.
- **Case** — a matter at an address (was "Job" / "work order"): belongs to a customer, carries the address, **optional** (a standalone/lead visit has none). Optionally grouped into a **project** (nullable `project_id`). Optional external reference.
- **Project** — an optional grouping of cases (e.g. a building). Purely a **grouping/populate convenience** — a source you pull cases from into a round. A round does **not** belong to a project.
- **Visit** — a **typed appointment** (survey / progress / handover / warranty / …) — the thing a customer books. Absorbs the old "booking": it carries the scheduled time (unscheduled until booked) and a soft-cancel. Can be standalone or created by a round.
- **Round** — an optional **batch-scheduler**: opens booking for many visits of one type at once (was "inspection round" / "sendout"). Holds the **visit type**, **appointment length + buffer**, a **close-off date**, an optional **window** (the date range it runs in), and its own **draft → open → closed** lifecycle. It targets an **explicit set of cases** (from anywhere — no grouping required). May optionally **file under a project** (nullable `round.project_id`) for **organization only** — this is *belonging* (which project it was run for, so it lists on that project's page), **not membership**: it does not constrain or define which cases the round contains. It **reads** staff availability to produce slots — it never owns availability, capacity, or staff allocation.
- **Staff** — a person who does the work and holds the bookable time (availability); the **shared calendar owner**. A corporation **always has at least one** staff member (provisioned at creation — the founder himself). One person does one visit at a time, so **parallel capacity is simply how many staff are free at once** — there's no separate capacity number. Per-staff availability assignment and **teams** are future.
- **Lead** — _future_: a separate entity (not a customer) that can own a visit before conversion; converting creates a customer (+ case). See Future direction.
---
 
## Relationships (how a Visit links)
 
A visit carries three links that answer three different questions:
 
- **owner** — *whose* visit. **Required.** **Polymorphic** (`owner_type` + `owner_id`): a **Customer** today, a **Lead** later. This is the *only* polymorphic link in the model — it's the seam that lets a lead own a visit before it's a customer, without migrating the visit table. Everything else is a plain foreign key.
- **case** — *what matter/apartment* it's about. **Plain FK, optional and late-bound** (null for a standalone or lead-survey visit; set when the work is defined).
- **round** — *was it batch-scheduled*, and under which. **Plain nullable FK** (null = standalone/one-off visit). Not polymorphic — a round is always just a round.
And:
- **A round targets an explicit set of cases and spins up a visit per targeted case** (initially unscheduled; the customer books by choosing the time). The cases can come from anywhere — one building, several, or none; unrelated cases can share a round. **Project is a populate shortcut**: "add all of Building A" reads `case.project_id` and drops those cases into the set, after which they're just cases in the round. The round stores no project link. A single-customer round targets one case.
- **Staff hold availability; the no-double-book guarantee is per-staff** — a staff member can't be in two visits at once, and parallel capacity at any moment is just how many staff are free then. A visit **resolves its staff at booking time** (whoever's free); the round never pre-assigns staff. With one staff member this is trivial.
- **A round needs assignable staff to exist** — it can't be created/opened unless at least one staff member has availability it can draw slots from (within its window). No assignable availability → no bookable slots → no round.
---
 
## Decisions
 
### Access & identity
| ID | Decision |
|----|----------|
| A1 | Customers are **not accounts**. Access via **magic link** (day-one default) — frictionless, sidesteps identity matching. |
| A2 | Auth is **pluggable** (magic-link / OTP / social) and resolves to a **(corporation, customer) + scope**. OTP/social/accounts add later without rework. Social deprioritized (its email often won't match the invited one). |
| A3 | Re-access = "**resend the link to the address on file**" (never a typed address). Tokens unguessable, lifecycle-bound, scoped to a visit or a customer's set. |
| A4 | A visit's **owner** is a customer (required); **visits/cases reference the customer, never a bare email** (lookups go email → customer → visits). Single email per customer for now — contact-points later is a migration-only change, precisely because nothing keys off the email directly. |
 
### Availability & rounds
| ID | Decision |
|----|----------|
| S1 | **Availability lives on Staff; the no-double-book guarantee is per-staff**, shared across all rounds and standalone visits — everything books into one real calendar per person. **Parallel capacity = how many staff are free at a time** (no separate capacity number). |
| S2 | A corporation **always starts with one staff member** (the founder) and can never have zero. Multiple named staff, per-staff availability, and teams are future — until then it's effectively a single-person calendar. |
| S3 | A **round** batch-schedules visits of one type for an **explicit set of cases** (from anywhere — unrelated cases can share a round; or a single case). A **project** is a populate shortcut ("add all of Building A" via `case.project_id`), not something the round belongs to. It **needs assignable staff to exist** — no staff availability, no round. Sending the links is an action on the round; **campaigns** are deferred. |
| S4 | Three different dates do three jobs: the round's **window** = which days it's offered in; **availability** = when staff are actually free (staff-level); **close-off** = the deadline to book/reschedule. Offered slots = **round window ∩ staff availability − already-booked**, sliced to the round's appointment length + buffer. Windows may **overlap** across rounds — the per-staff guarantee (S1) still stops two rounds double-booking the same person. |
 
### Sendouts & recipient status
| ID | Decision |
|----|----------|
| SO1 | A **sendout** has two independent axes: **timing** — *scheduled* (fires at a datetime) or *manual* ("send now") — and **audience** — *all* due recipients or an *individual/subset*. "Send now to all", "schedule to all", and "send to one" are the same mechanism with two knobs, not separate features. |
| SO2 | **Resend** to a single recipient is a first-class action (the "I lost my link" case). |
| SO3 | Any **bulk send shows a confirm-and-preview** ("this will email N customers") before firing — bulk sends are unrecallable. |
| SO4 | **Per-recipient status**, carried on the visit and aggregated on the round dashboard: **not yet sent → sent → booked → cancelled**, plus **failure** (a send/delivery failure — bad address or provider error). **No _delivered_ or _opened_ tracking** for now. (not-yet-sent / sent / failure = invite delivery; booked / cancelled = the visit's scheduling state.) This is invite-and-booking tracking, distinct from the post-visit outcome workflow, which stays absent (B5). |
| SO5 | **Late-adds:** adding a case/customer mid-round creates a visit as **not yet sent** — never auto-emailed on add. Before close-off, the admin sends to them via a **manual individual sendout**. **After close-off, no sendout** goes out (the admin books them manually if it's still needed). |
| SO6 | **Reminders are future** — they'd be an automatic sendout over the still-unbooked ("sent") set. Not built now. |
| SO7 | **Project populate is point-in-time, not a live link.** "Add all of Building A" selects the matching cases *at that moment* and adds them; a round's membership is its set of visits, not "whatever currently matches this project." A case added to a project *after* a round exists does **not** auto-join it — it's a normal late-add (SO5): the admin adds it (or re-runs populate, which picks up new matches and skips existing ones), landing as not-yet-sent. A live/dynamic "always all of Building A" membership is a separate, deferred feature. |
| SO8 | **`round.project_id` = organization, not membership.** Two different project↔round links, kept apart: *membership* (which cases are in the round) is the explicit visit set and has **no** project link; *belonging* (which project the round was filed under) is the nullable `round.project_id`, set when a round is created from a project page, used only so the round lists on that **project page** (a reverse lookup `rounds where project_id = A`). It is explicitly **not an integrity constraint** — a round filed under Building A may still contain unrelated cases. The **live-membership subscription** (a round that auto-pulls newly-added project cases) remains deferred and, if ever built, must be gated and still land new visits as not-yet-sent (never auto-email). |
 
### Visit behaviour (carried over)
| ID | Decision |
|----|----------|
| B1 | Customers **book a free time themselves**; two visits can never collide or exceed capacity on the resource, even under simultaneous booking. |
| B2 | Customers can **reschedule themselves** until the round's **close-off date**; locked after. |
| B3 | Admins can **manually move, resize, or cancel** any single visit, to any time. |
| B4 | If an admin **changes availability** and scheduled visits fall outside it, the product **prompts** to leave or cancel them. |
| B5 | Visits have **no post-visit outcome workflow** (no done/no-show/revisit) for now. (The invite/booking status — sent/booked/cancelled/failure — is tracked separately per SO4; this B5 rule is about the *outcome after the visit*, which stays absent.) A "what's going on" state, if wanted, belongs on the **case**. |
| B6 | **Visit type** is a small **extensible** set (survey / progress / handover / warranty, room to add) — not a frozen enum, not a user-defined type builder. Type is load-bearing: rounds filter on it. |
| B7 | **Address is a plain field** on the case; no physical-unit entity; travel-routing out of scope (buffer is a simple gap). |
 
### Emails
| ID | Decision |
|----|----------|
| E1 | Emails via a real service for: visit **invite/link**, **reschedule confirmation**, **cancellation**, **appointment-change**. |
| E2 | Cancellation/change emails **individual or bulk**; the setup **must support mass sendout**. |
 
### Architecture spine (from day one)
| ID | Decision |
|----|----------|
| X1 | **Event spine** — every state change emits an event; notifications and connectors subscribe (also the outbox for bulk sends). |
| X2 | **Ownership boundary** — interaction data (visits, messages, events) is ours; business records (case, customer) are the host's when connected, mirrored via an **optional external reference** (host-linked *or* standalone). |
| X3 | **Polymorphic only where the type truly varies** (visit.owner). Everywhere else, plain FKs. |
| X4 | **Multi-tenant scoping + GDPR/data-minimization** baked in. Minimal customer PII; soft-delete/erasure seam. |
 
---
 
## Open questions
 
None blocking the current build. Customer source is parked (a spreadsheet for now); scheduling is the focus.
 
---
 
## Future direction (post-MVP)
 
- **Leads.** A lead is a **separate entity** (not a customer). A lead can **own a visit** (a pre-qualification survey) via the polymorphic owner seam; converting a lead **creates a customer (+ case)** and the visit carries over. Built later; the seam is in from the start so it's additive.
- **Multiple staff & per-staff availability.** Staff already exist (one to start); the future work is **adding more named staff**, assigning availability **corporation-wide or per staff member**, resolving a visit's staff at booking time across several people, and **teams** after that. Additive, since availability already hangs off staff.
- **Reminders / nudges** — an automatic sendout that chases the still-unbooked ("sent") recipients (e.g. a few days before close-off). High value, deferred; the per-recipient status already makes it a targeting filter, so it's additive.
- **Campaigns** — the richer round: multi-phase (interest signup → booking round), audience allocations, reserving a slice of availability. For now: just rounds.
- **Multiple contact points per customer** — move the single inline email into a child table of emails/phones (property-manager & SMS cases). Clean migration, since nothing keys off the email directly.
- **Integration & entity sourcing.** When connected, the host supplies the **nouns** (customers, cases/jobs, staff, addresses, service types) so this layer never re-creates them; the layer owns the **verbs** (book/reschedule/communicate). List/identity entities are easy to pull; **bookable availability** usually isn't and stays ours.
- **Richer auth** (OTP/social/accounts) and a **consumer-unified identity** (linking per-corporation customers without merging tenant data) — a strategic pivot, not a schema tweak.
- Reminders, `.ics`, visit statuses, travel-aware routing, broad rebooking cascades, roles, billing/onboarding.
---
 
## Out of scope (for now)
 
Leads, campaigns, host integration, OTP/social/accounts, consumer identity, multiple staff/crews, multiple contact points, and **all back-office features** — plus low-level implementation choices beyond the load-bearing invariants above.
 
---
 
## Changelog
 
- **2026-06-12** — Added **`round.project_id` as organizational belonging** (nullable), distinct from membership (SO8): lets a round file under a project so it lists on a **project page** (reverse lookup), without constraining the round's cases. Live-membership subscription stays deferred + guarded.
- **2026-06-12** — **Project kept as a case-level grouping only.** A round targets an **explicit set of cases from anywhere** (unrelated cases can share a round); `case.project_id` (nullable) stays as a grouping/**populate** convenience ("add all of Building A"). Dropped the **round→project** link, project-via-case, and the round/project integrity rule — a round no longer belongs to a project. Populate is **point-in-time, not a live link** (SO7): late-matching cases are a normal late-add.
- **2026-06-12** — **Sendout model.** Two axes — timing (scheduled / manual) × audience (all / individual-subset). Added **resend** (single recipient) and a **confirm-and-preview** before bulk sends. Per-recipient status: **not yet sent → sent → booked → cancelled**, plus **failure**; no delivered/opened. **Late-adds** appear as not-yet-sent (never auto-emailed) → manual sendout before close-off, no sendout after. **Reminders deferred**.
- **2026-06-12** — **Resource → Staff.** Availability and the no-double-book guarantee are **per-staff**; **capacity is just how many staff are free** (no separate number). A corporation **always starts with one staff member** (the founder) and can't have zero. A **round needs assignable staff** to be created. Split the round's **window** (which days it's offered) from staff **availability** (when they're free) and **close-off** (booking deadline) — offered slots = window ∩ availability − booked; windows may overlap, the per-staff guarantee still holds.
- **2026-06-12** — Vocabulary settled and the visit relationship model added. **Party → Customer**, **Job/Work Order → Case**, **Sendout → Round**, old **Booking absorbed into Visit** (a **typed** appointment: survey/progress/handover/warranty, "inspection" demoted to a type). Visit links: **owner** (polymorphic — customer now, lead later, the only polymorphic link), **case** (optional/late-bound FK), **round** (nullable FK); **project reached via case, never stored**; round-linked visit's case must be in the round's project (app-level check). Lead confirmed as a **future separate entity** a visit can own pre-conversion.
- **2026-06-12** — Party simplified to a single inline email; locked "references point at the party/customer, not the email".
- **2026-06-12** — Party as durable anchor; job late-bound.
- **2026-06-12** — Reframed as a customer-facing self-service layer (carpenter = first scenario): positioning, magic-link access, resource-scoped availability, event spine, ownership boundary, tenancy/GDPR.
- **2026-06-12** — (Earlier) Work-order-centric spec; then product-level refocus.