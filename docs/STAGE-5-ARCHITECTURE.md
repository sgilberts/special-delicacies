# Stage 5 — Reservations Architecture

**Project:** Special Delicacies
**Phase:** 11B.2 — Stage 5 (Bookings, Reservations and Future Module Integration)
**Scope of this document:** the domain architecture established by Stages 5.1 and 5.2 only.
**Status:** Stages 5.1 and 5.2 implemented; Stage 5.3 onwards not started.

---

## 1. Design principles

1. **One physical table registry.** The existing `restaurant_tables` record serves dine-in QR ordering and reservations (D-5A). No second table identity exists.
2. **Additive only.** Every Stage 5.1/5.2 schema change adds tables or nullable/defaulted columns. No existing column, identifier, relationship or endpoint was renamed, removed or rewritten.
3. **Reuse existing architecture.** Customers, organisations, commercial documents, payments, RBAC and audit are reused as-is. No duplicate customer, organisation, payment, receipt or ledger structure exists.
4. **Configuration, not calculation.** Stages 5.1/5.2 store reservation records and configuration. Availability calculation, lifecycle transitions, deposits and payments are explicitly out of scope.
5. **Time is venue-local wall clock.** Dates and times are stored as supplied and interpreted in the application timezone (`APP_TIMEZONE`, default `Africa/Accra`). No other timezone is assumed.

---

## 2. Entity relationship overview

```
customers ──┐
organisations ──┤
venues ─────┼──< reservations >── commercial_documents (optional)
users ──────┘        │
                     ├──< reservation_table_allocations >── restaurant_tables >── venues
                     └──< reservation_status_histories >── users (actor)

venues ──< venue_operating_hours
       ──< venue_special_hours
       ──< venue_closures >── users (created_by)
       ──< restaurant_tables

restaurant_tables ──< table_unavailability_periods >── users (created_by)
                  ──< table_qr_codes            (existing)
                  ──< orders                    (existing, dine-in occupancy)
                  ──< reservation_table_allocations
```

---

## 3. Reservation domain (Stage 5.1)

### 3.1 Reservation header — `reservations`

| Column | Purpose |
|---|---|
| `reference` | Unique human-readable reservation reference |
| `customer_id` | Existing customer record (`restrictOnDelete`) |
| `organisation_id` | Existing organisation record (`restrictOnDelete`) |
| `venue_id` | Venue association, including exclusive-venue reservations (`restrictOnDelete`) |
| `category` | `single_table`, `multiple_tables`, `exclusive_venue` |
| `reservation_date`, `start_time`, `end_time` | Requested booking window (venue-local) |
| `guest_count`, `requested_table_count` | Capacity demand |
| `status` | `requested`, `under_review`, `confirmed`, `cancelled`, `completed`, `no_show` |
| `commercial_document_id` | Optional link to an existing quotation/invoice |
| `booking_value`, `booking_currency` | Historical snapshot of the agreed value; the commercial document remains authoritative |
| `deposit_*` | Configuration snapshot fields reserved for Stage 5.5; nothing is calculated here |
| `special_instructions`, `internal_notes` | Guest-facing and staff-only notes |
| `created_by` | Staff actor (`nullOnDelete`) |

**Snapshot rule.** `booking_value` and the `deposit_*` columns are the only duplicated financial values. They exist to preserve what was agreed at booking time for audit and reporting. The authoritative amounts are the commercial document total and, from Stage 5.5, the deposit settings. No deposit percentage, amount or policy is seeded or invented.

### 3.2 Allocations — `reservation_table_allocations`

One row per allocated physical table. A multiple-table reservation is represented by several rows; a table can appear in several reservations in non-conflicting periods. Columns: `reservation_id` (cascade), `restaurant_table_id` (restrict), `occupancy_start`, `occupancy_end`, `released_at`.

- Unique key `(reservation_id, restaurant_table_id, occupancy_start)` prevents duplicate allocation rows.
- Index `(restaurant_table_id, occupancy_start, occupancy_end)` supports the future overlap queries.

### 3.3 History — `reservation_status_histories`

`reservation_id`, `previous_status`, `new_status`, `changed_by`, `reason`, `metadata` (JSON), timestamps. This is the reservation's own transition trail; it does not create a parallel audit framework. Consequential administrative changes also flow through `AuditService` into `admin_audit_logs`.

---

## 4. Venue and capacity configuration (Stage 5.2)

### 4.1 Venue — `venues`

Name, unique code, description, type (`dining_area`, `private_room`, `terrace`, `event_space`, `other`), location, maximum capacity, operational status (`active`, `inactive`, `maintenance`), `reservation_enabled`, `exclusive_reservation_enabled`, default duration and slot size, internal notes.

### 4.2 Shared table extension — `restaurant_tables`

| Added column | Purpose |
|---|---|
| `venue_id` | Venue association (`restrictOnDelete`) |
| `table_type` | `standard`, `booth`, `bar`, `private`, `outdoor`, `other` |
| `operational_status` | `available`, `maintenance`, `out_of_service` — reservation-side state |
| `reservation_eligible` | Whether the table may be offered to reservations |
| `seating_configuration` | Optional structured seating detail |
| `admin_notes` | Staff-only notes |

**Existing `is_active` is unchanged.** It continues to control whether a table is offered in QR/dine-in ordering. `operational_status` is the reservation-side operational state. Both are independent by design, so existing dine-in behaviour is preserved.

**Capacity** remains the existing `capacity` column. A multiple-table reservation's capacity is the combined capacity of its allocated tables; no combined value is stored.

### 4.3 Operating hours and closures

- `venue_operating_hours` — one row per weekday (`0` Sunday … `6` Saturday), `opens_at`, `closes_at`, `is_closed`; unique `(venue_id, day_of_week)`.
- `venue_special_hours` — date-specific override; unique `(venue_id, date)`.
- `venue_closures` — `closure` or `maintenance` periods with start/end and reason.
- `table_unavailability_periods` — table-level temporary unavailability/maintenance.

Nothing here computes availability; the Stage 5.3 engine will consume these records.

---

## 5. D-5B occupancy rule (documented for Stage 5.3)

- Authoritative active order statuses: `pending`, `confirmed`, `preparing`, `ready`, `out_for_delivery`, `delivered` (`Order::ACTIVE_STATUSES`).
- Terminal statuses: `completed`, `cancelled` (`Order::TERMINAL_STATUSES`).
- A dine-in order occupies its table from placement until a terminal status. Elapsed time never releases it.
- `RestaurantTable::hasActiveDineInOrder()` reports current occupancy.
- Stage 5.1/5.2 enforce a configuration safeguard: a table with an active dine-in order cannot be moved to `maintenance`/`out_of_service`.
- The Stage 5.3 engine must reject conflicting allocations and use a database transaction with row locking. No override exists or may be added without owner approval.

---

## 6. Extension points deliberately left open

- Stage 5.3 availability engine (allocation conflict checks, slot generation, venue-wide exclusivity).
- Stage 5.4 lifecycle transitions and their permission rules.
- Stage 5.5 deposit settings and calculation (the snapshot columns are ready, empty).
- Stage 5.6 payable/gateway extension for reservation deposits.
- Stage 5.7–5.9 customer/staff portals, notifications and reporting.

None of these behaviours is implemented in Stages 5.1/5.2.
