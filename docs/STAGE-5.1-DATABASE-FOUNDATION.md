# Stage 5.1 — Reservation Domain and Database Foundation

**Project:** Special Delicacies
**Status:** Implemented
**Authorisation:** Stage 5.0 completed; Stages 5.1 and 5.2 authorised by the project owner.

---

## 1. Scope delivered

- Reservation model and migration.
- Reservation table allocation model (one row per physical table).
- Reservation status history model.
- Foreign keys to the existing customer, organisation, venue, commercial document and staff actor records.
- Backend validation for the shared table configuration fields used by allocations.
- Domain tests, MySQL verification and rollback verification.

Not delivered (by instruction): availability calculation, reservation submission/approval workflows, status transition engine, deposits, payments, portals, notifications.

---

## 2. Migration

`backend/database/migrations/2026_11_28_000003_create_reservations_tables.php`

Creates `reservations`, `reservation_table_allocations`, `reservation_status_histories`.

Dependencies: `customers`, `organisations`, `commercial_documents`, `users` (all pre-existing) and `venues` (Stage 5.2 migration `2026_11_28_000001`, which runs first).

Deletion rules:

| Relationship | Rule | Rationale |
|---|---|---|
| `reservations.customer_id` | RESTRICT | Preserve reservation history |
| `reservations.organisation_id` | RESTRICT | Preserve organisation-scoped history |
| `reservations.venue_id` | RESTRICT | A venue with reservations cannot be deleted |
| `reservations.commercial_document_id` | RESTRICT | Do not orphan the agreed document |
| `reservations.created_by` | SET NULL | Actor removal must not destroy the record |
| `reservation_table_allocations.reservation_id` | CASCADE | Allocations belong to their reservation |
| `reservation_table_allocations.restaurant_table_id` | RESTRICT | Physical table history is protected |
| `reservation_status_histories.reservation_id` | CASCADE | History belongs to its reservation |
| `reservation_status_histories.changed_by` | SET NULL | Actor removal must not destroy history |

Indexes:

- `reservations.reference` UNIQUE.
- `(reservation_date, status)`, `(venue_id, reservation_date)`, `(customer_id, reservation_date)`, `(organisation_id, reservation_date)`.
- `(reservation_id, restaurant_table_id, occupancy_start)` UNIQUE on allocations.
- `(restaurant_table_id, occupancy_start, occupancy_end)` on allocations.
- `(reservation_id, created_at)` on status histories.

Rollback: drops the three reservation tables only. The shared `restaurant_tables` data and all dine-in records are untouched. Verified on MySQL 8.4 (see `STAGE-5-TEST-RESULTS.md`).

---

## 3. Models

| Model | Key behaviour |
|---|---|
| `App\Models\Reservation` | Category/status constants, relations to customer, organisation, venue, commercial document, creator, allocations, status histories; `status`/`category` scopes |
| `App\Models\ReservationTableAllocation` | Relations to reservation and the shared `RestaurantTable`; datetime casts for occupancy window |
| `App\Models\ReservationStatusHistory` | Actor relation and JSON `metadata` cast |

Reservation categories: `single_table`, `multiple_tables`, `exclusive_venue` (string column + model constants, so future categories are a controlled addition rather than a schema change).

Reservation statuses defined for later stages: `requested`, `under_review`, `confirmed`, `cancelled`, `completed`, `no_show`. No transition engine is implemented.

---

## 4. Allocation semantics

- A reservation with several tables has several allocation rows; no JSON/text list of table IDs exists.
- `occupancy_start`/`occupancy_end` record the period the table is held.
- `released_at` supports later release without deleting history.
- A table may appear in many allocations across non-conflicting periods.
- Allocations reference `restaurant_tables` directly, so table numbers, capacities and identifiers are never duplicated.

---

## 5. Validation

Stage 5.1 does not expose a reservation write endpoint (submission/approval belong to later stages). The shared table fields that allocations depend on are validated by `StoreRestaurantTableRequest` / `UpdateRestaurantTableRequest`:

- `venue_id` must exist in `venues`.
- `table_type` must be a known type.
- `operational_status` must be a known status.
- `reservation_eligible` boolean, `seating_configuration` array, `admin_notes` string (max 2000).

---

## 6. Tests

`backend/tests/Feature/Stage5ReservationFoundationTest.php` covers:

- Customer, organisation and venue relationships.
- Single- and multiple-table allocations as separate records.
- The same table allocated to different reservations in non-conflicting periods.
- Unique reservation references.
- Status history with actor and metadata.
- RESTRICT deletion of a customer that has reservations.
- Rollback and re-apply of the reservation migration with a dine-in table preserved.

---

## 7. Known limitations

- No reservation reference generator is provided because no reservation creation path exists yet (Stage 5.4). Callers must supply a unique `reference`.
- No occupancy conflict enforcement yet — Stage 5.3 owns it, using the indexes above.
- Deposit snapshot fields are intentionally unpopulated.
