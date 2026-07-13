# Inspection Book — Product

A self-service booking tool for trade inspections. An inspector (e.g. a
carpenter) sets up a project, registers the apartments and their contacts,
opens an examination (a round of inspections) with available time, and each
apartment books its own slot via a link in their email.

This is a product/functional doc — it captures what the product does and the
decisions made so far. Technical design (data model, how bookings are stored
and kept conflict-free, slot calculation, email delivery, etc.) is
intentionally left to implementation and not specified here.

This is a living document. When something is decided, move it into the
Decisions list. When something is still up in the air, keep it in Open
questions.

## Core flow

1. An admin sets up a project and registers its apartments and a contact for
   each.
2. The admin opens an examination (a round of inspections) and defines when
   they're available.
3. Each contact gets a personal booking link by email.
4. The contact opens the link, sees the available times, and books one
   themselves.
5. They can reschedule until a close-off date; the admin can also move or
   cancel bookings, with contacts notified by email.

## Decisions

| ID  | Decision |
|-----|----------|
| D1  | Customers are represented as a corporation (company) that owns its projects and users. |
| D2  | Everyone in a corporation is an admin for now. Finer roles may come later. |
| D3  | Single customer for now. Corporations and their users are set up manually — no self-serve signup or billing yet. (The product should still be built so multiple customers are possible later.) |
| D4  | A project holds the apartments (units) being inspected. Apartments may be at different addresses (scattered sites). |
| D5  | Each apartment has a contact who books via a personal link in their email — no account or login. |
| D6  | An examination is a round of inspections across a project (e.g. pre-handover). The admin defines availability, the length of each appointment, a gap/buffer between appointments, and a capacity — how many inspections can run in parallel in the same block (default 1, can be higher). |
| D7  | No concept of individual workers — the product doesn't track who performs each inspection. (Capacity is just a number of parallel slots.) |
| D8  | Smart travel-time routing between scattered addresses is out of scope for now; the buffer is a simple configurable gap. |
| D9  | Contacts book a free time themselves from the available slots. The product must never allow two bookings to collide or exceed an examination's capacity, even with simultaneous bookings. |
| D10 | Contacts can reschedule themselves until a fixed close-off date set per examination. After that date the booking is locked. |
| D11 | Admins can manually move, resize, or cancel any single booking, to any time. |
| D12 | If an admin changes availability and existing bookings fall outside the new availability, the product prompts the admin to leave them (kept as-is) or cancel them. |
| D13 | Bookings have no status workflow (no done / no-show / needs-revisit) for now — a booking is simply active or cancelled. |
| D14 | Emails are sent through a real email service (not manual sending) for: the booking invite/link, a reschedule confirmation, a cancellation notice, and an appointment-change notice when a booking is moved. |
| D15 | Cancellation and change emails can be sent individually or in bulk (e.g. when an availability change strands many bookings). The email setup must support mass sendout. |
| D16 | Broad rebooking cascades (cancelling/moving an entire examination and mass-rebooking everyone) are out of scope for now. |

## Open questions

None open right now. Remaining choices are implementation details (data model,
how conflict-free booking is enforced, slot calculation, email delivery
mechanism) and are left to the build.

## MVP scope

In scope:

- An admin (in a manually-created corporation) creates a project and adds its
  apartments + contacts (addresses allowed).
- The admin creates an examination: availability, appointment length, buffer,
  capacity, and the close-off date.
- Each contact receives a personal booking link by email.
- The contact opens the link, sees available times, and books one.
- The contact can reschedule until the close-off date.
- The admin sees the schedule and can move, resize, or cancel any booking;
  affected contacts are emailed.
- The admin can change availability; bookings that fall outside it prompt
  leave-or-cancel, and cancelled contacts are emailed.
- Email supports both individual and bulk sendout.

Out of scope (for now):

- Reminders and calendar (.ics) attachments.
- Broad rebooking cascades for a whole examination (D16).
- Booking status tracking — done / no-show / revisit (D13).
- Travel-time-aware routing between addresses (D8).
- Roles beyond admin (D2).
- Self-serve customer signup / billing (D3).
- Individual worker tracking (D7).
- All technical/implementation decisions — data model, conflict-free booking
  enforcement, slot calculation, email delivery — handled at build time.

## Build status

- **Done:** corporation + user slice — manual provisioning
  (`php artisan app:create-corporation`), login, corporation-scoped dashboard.
- **Done:** architecture spine — central event log (`events` table +
  `EventRecorder::record()`, first events: `corporation.created`,
  `user.created`, `user.logged_in`) and the optional external-reference
  convention (`$table->externalRef()` macro + `HasExternalReference` trait).
- **Done:** customer (was party) — the durable anchor (person/organization,
  corporation-scoped, single inline email for now, optional host reference,
  soft-delete erasure seam). Resolution goes email → customer → interactions.
- **Done:** project setup — projects (optional grouping) + work orders
  (implementing the spec's Case: apartment address + customer, optional
  project link). Admin adds a customer by name/email/address; the customer is
  resolved-or-created by normalized email, so one property manager = one
  customer with many work orders. Events: `project.created`,
  `customer.created`, `work_order.created`. Minimal Inertia UI: projects
  list / create / detail with add-customer form.
- **Done:** cases page — corporation-scoped list of all work orders at `/cases`
  (sidebar item); delete a case (soft-delete + event, from project page or cases
  page); edit a project name (modal on project show page); renamed "Add apartment"
  → "Add case" in the UI.
- **Done:** staff — the person who does the work and will later hold bookable
  time. Corporation-scoped list at `/staff` with create/rename/delete in modals.
  Every corporation is provisioned with one staff member at creation (the
  founder), and the last staff member can never be deleted — a corporation
  never has zero staff. Events: `staff.created`, `staff.updated`,
  `staff.deleted`. **Decision: availability windows are deferred** — for now a
  staff member is assumed available all the time; slot computation (future
  slice) will treat staff as always free until availability is built.
- **Done:** visits — the typed appointment (survey / progress / handover /
  warranty, extensible), standalone and admin-managed for now. A visit is
  always a scheduled appointment (staff + times required at creation); it does
  not exist in an "unscheduled" state. Owned by a customer (polymorphic seam
  for future leads), optionally tied to a case, soft-cancelled (`cancelled_at`
  — stays visible as history, releases its time). Scheduling must go through
  one sanctioned path (`ScheduleVisit`) that enforces the per-staff
  no-double-book invariant atomically (lock + overlap check; the loser gets a
  clean "just taken" error). Case detail page at `/cases/{id}` with new/move/
  cancel dialogs. Events: `visit.created`, `visit.scheduled`,
  `visit.cancelled`.
- **Done:** rounds — the batch-scheduler: opens booking for many visits of
  one type at once. A round holds visit type, appointment length + buffer,
  an optional window (date range), a close-off date, and a draft → open →
  closed lifecycle. It targets an explicit set of cases via a membership
  table (`round_work_orders`) — **not** pre-created visits, since a visit
  only exists once booked; a "populate from project" shortcut adds a
  project's cases point-in-time (re-running skips existing). Opening
  requires ≥ 1 case; late-adds are allowed while open; editing, removing
  cases, and deleting are draft-only; closed is read-only. Optional
  `project_id` files the round under a project (organization only, never
  membership) so it lists on that project's page. Rounds page at `/rounds`
  + detail at `/rounds/{id}`. Events: `round.created`, `round.updated`,
  `round.opened`, `round.closed`, `round.deleted`, `round.cases_added`,
  `round.case_removed`.
- **Done:** customer self-booking + magic links — each membership row gets an
  unguessable 64-char token at attach time; the public page at `/book/{token}`
  (no account, no login) shows the round's offered slots and the customer
  books or reschedules one. Booking is what creates the visit (owner =
  customer, case, `round_id`, type from the round) through `BookVisit` →
  `ScheduleVisit`, staff resolved at booking time — whoever's free; the
  no-double-book invariant holds and a race loser gets a clean "just taken"
  error. Slots = round window ∩ round day-hours − booked, sliced by
  appointment + buffer (`RoundSlots`); day-hours (default 08–16) are a
  per-round stopgap until staff availability windows exist, and opening a
  round now requires a window. Reschedule until close-off (B2); closed/past
  close-off shows a locked page. Admin sees per-case booking links (copy
  button) and derived booked state on the round page. No new event types —
  `visit.created`/`visit.scheduled` with a null actor is the customer acting.
- **Done:** scheduled round start — a draft round can carry a
  `scheduled_open_at` instant; a scheduler process (`schedule:work`
  container in dev and prod Compose profiles) runs `app:open-due-rounds`
  every minute and opens due rounds with the same invariants as a manual
  open (≥ 1 case + window). A due-but-invalid round is skipped with its
  schedule kept, so it opens as soon as it becomes valid (the round page
  shows the pending schedule and a waiting hint). Manual open overrides —
  it clears the schedule. `round.opened` carries `trigger:
  scheduled|manual`; the emails slice will subscribe to it, making the
  scheduled open the "send invites at this time" mechanism (SO1). Late-adds
  while open were already supported (SO5).
- **Done:** emails/sendouts — the event → outbox → Mailable path is live.
  Every state change records an event; a listener turns the relevant ones
  into durable `outbox_messages`; `app:send-outbox` (scheduled every minute)
  drains them through Mailpit in dev/test. Four emails (E1): booking invite
  (the magic link, one per round membership), booking confirmation, an
  appointment-change notice when an admin moves a scheduled visit, and a
  cancellation. Opening a round emails the invite to every recipient not yet
  sent — the manual-open dialog previews the count (SO3); a fresh
  admin-created visit also confirms, but carries a manage link only when the
  visit belongs to a round (self-management is round-only). Per-recipient
  invite status (not sent / sent / failed, SO4) shows on the round page with
  a per-case Send/Resend (SO2/SO5); late-adds are never auto-emailed. A visit
  can be linked to a round it's a member of (visit dialogs), which is what
  gives its confirmation a manage link; a round holds at most one active
  visit per case. Bulk mass-send and delivered/opened tracking beyond this
  stay out (SO4/SO6, campaigns).
- **Done:** admin-UX polish — customers carry an optional address and have a
  detail page listing their cases (with edit); cases gained an optional title
  (shown in lists when set) and notes, an edit dialog, a link to their
  customer, and address that prefills from the customer on creation. Projects
  and rounds now both **attach existing** cases (created on the Cases page)
  rather than creating them; the project list count reads "cases"; the rounds
  list has Draft / Open / Closed tabs and the round page shows a details grid.
- **Done:** admin schedule view — a week calendar on the dashboard (Mon–Sun,
  hour rows expanding to fit outliers, Prev/Today/Next). Visits render as
  blocks colored per staff member (legend when more than one); overlapping
  visits share the column side by side. Clicking an empty slot opens the
  New-visit dialog prefilled with that hour; clicking a block opens the Move
  dialog, which also gained a Cancel-visit action. No new endpoints — the
  calendar reuses the existing visit create/update/cancel paths, so the
  no-double-book invariant stays in `ScheduleVisit`. The New/Move dialogs are
  extracted into shared components used by both the dashboard and the Visits
  page. When availability windows arrive, this calendar is where they render.
- **Done:** detail-page polish — the case page shows its attributes in the
  same details grid the round page uses (shared `DetailsGrid` component);
  visits gained their own detail page at `/visits/{id}` (type, staff, times,
  status, plus links to the case, customer, and round they're tied to) linked
  from every visit list row; the visit dialog is "Edit visit" everywhere
  (was "Move visit"), and the case page now reuses the shared edit dialog
  instead of its own inline copy.
- **Next:** staff availability windows — real per-staff bookable time so
  slot computation stops assuming staff are always free and the per-round
  day-hours stopgap can retire.
