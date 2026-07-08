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
- **Next:** rounds, visits/booking, emails (design-doc vocabulary:
  Round / Visit); staff availability windows when scheduling needs them.
