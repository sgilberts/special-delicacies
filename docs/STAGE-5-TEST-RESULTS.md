# Stage 5.1 and 5.2 — Test Results

**Project:** Special Delicacies
**Run date:** 28 November 2026 (Africa/Accra)
**Environment:** sandbox compose stack — PHP 8.x / Laravel 13, MySQL 8.4.13, React 19 + Vite, Node 24.
**Database for PHPUnit:** SQLite `:memory:` (project default).
**Database for migration verification:** disposable MySQL schema `sd_stage5_verify` (dropped afterwards).

---

## 1. New automated tests

Command (inside the `backend` container):

```
php artisan test --filter "Stage5ReservationFoundationTest|Stage5VenueTableConfigurationTest"
```

**Result: 16 tests, 89 assertions, 0 failures.** PHPUnit reports 16 warnings; every warning is the same pre-existing environment notice (`file_get_contents(/workspace/backend/.env): No such file or directory`) emitted by phpdotenv because this sandbox supplies configuration through Compose environment variables rather than a `.env` file. It is not produced by application code.

### Stage 5.1 — `Stage5ReservationFoundationTest` (7 tests)

| Test | Result |
|---|---|
| Reservation links existing customer, organisation and venue records | PASS |
| Multiple tables represented as separate allocation records | PASS |
| A table can be allocated to different reservations in non-conflicting periods | PASS |
| Reservation reference must be unique | PASS |
| Status history records actor and metadata | PASS |
| Customer with reservations cannot be deleted (FK RESTRICT) | PASS |
| Reservation migrations roll back and re-apply without touching dine-in tables | PASS |

### Stage 5.2 — `Stage5VenueTableConfigurationTest` (9 tests)

| Test | Result |
|---|---|
| Admin can create and update a venue with audit | PASS |
| Venue code is unique and validated | PASS |
| Staff without permission cannot manage venues (403) | PASS |
| Admin can associate a table with a venue and configure reservation attributes | PASS |
| Existing table payload still works with reservation defaults (regression) | PASS |
| Table with active dine-in order cannot be taken out of service (D-5B) | PASS |
| Operating hours, special hours and closures are configured and audited | PASS |
| Table unavailability periods are managed | PASS |
| Venue with tables cannot be deleted | PASS |

---

## 2. Existing regression suites

Command:

```
php artisan test --filter "Phase5TableManagementTest|TableQrTest|Phase6DineInOrderTest|OrderTest|RbacTest|Stage5ReservationFoundationTest|Stage5VenueTableConfigurationTest"
```

**Result: 89 tests, 1 failure, 387 assertions.** The single failure is
`Phase5TableManagementTest > existing qr urls are migrated to query token format`, which asserts a hard-coded `https://special-delicacies.local` frontend URL. This sandbox sets `FRONTEND_URL=https://3000-<sandbox host>` in Compose, so the assertion fails on the environment value, not on table behaviour. It is unrelated to the Stage 5 changes (no QR code or URL code was modified).

Existing table and dine-in behaviour covered by these suites — table CRUD, QR generation/revocation, QR resolution, dine-in ordering, order lifecycle, RBAC — continues to pass.

---

## 3. Full backend suite

Command: `php artisan test`

**Result: 378 warnings, 13 failures, 1,856 assertions, 0 Stage 5 failures.**

All 13 failures are pre-existing environment failures in this sandbox, unrelated to the Stage 5 files:

| Failing tests | Cause |
|---|---|
| `AdminMenuItemTest` (4), `PaymentGatewayManagementTest` (favicon), `UserProfileTest` (photo), `ChatFeatureTest` (image attachment) | GD extension is not installed in the backend image |
| `ChatFeatureTest` (2 capacity tests) | Pre-existing chat assignment semantics under SQLite |
| `LocalFrontendCorsTest` | Sandbox CORS environment configuration |
| `Phase5TableManagementTest` (QR URL migration) | `FRONTEND_URL` set by Compose |
| `Stage4bCreditAndSecurityTest` (2 mail tests) | Mail transport in the sandbox environment |

No test that exercises venues, reservations, allocations, table configuration, operating hours, closures or unavailability fails.

---

## 4. MySQL 8.4 verification

A disposable schema `sd_stage5_verify` was created on the sandbox MySQL 8.4.13 instance.

1. **All 47 migrations applied successfully**, including the four Stage 5 migrations.
2. **Structure verified** via `information_schema`:
   - `restaurant_tables` gained `venue_id`, `table_type`, `operational_status` (default `available`), `reservation_eligible` (default 1), `seating_configuration` (JSON), `admin_notes`.
   - Foreign keys: reservation → customer/organisation/venue/commercial document RESTRICT; allocation → table RESTRICT; allocation/history → reservation CASCADE; actor columns SET NULL; venue children CASCADE.
   - Unique constraints: `reservations.reference`, `(reservation_id, restaurant_table_id, occupancy_start)`, `(venue_id, day_of_week)`, `(venue_id, date)`, `venues.code`.
   - Indexes: `(reservation_date,status)`, `(venue_id,reservation_date)`, `(customer_id,reservation_date)`, `(organisation_id,reservation_date)`, `(restaurant_table_id,occupancy_start,occupancy_end)`, `(venue_id,reservation_eligible)`.
3. **Rollback safety:** with an existing dine-in table (`LEGACY-1`) present, the four Stage 5 migrations were rolled back (`migrate:rollback --step=4`) and re-applied. The legacy row survived with defaults (`operational_status = available`, `reservation_eligible = 1`, `venue_id = NULL`). The first rollback attempt exposed a MySQL ordering issue (composite index needed by the foreign key); the `down()` method was corrected to drop the foreign key before the index, and the rollback then passed.
4. The disposable schema was dropped after verification. No destructive test ran against the application database.

---

## 5. Browser verification

Verified in the running application (signed in as the seeded super-admin development account), at the mobile viewport and desktop, in light and dark themes. Test records were created through the UI/API and deleted afterwards (all deletes returned 200; no test venue or table remains).

| Check | Result |
|---|---|
| Venue management page renders (`/admin/venues`) | PASS |
| Create venue through the form | PASS — card shown, toast confirmed, audit recorded |
| Configure dialog opens | PASS |
| Operating hours saved | PASS — 7 weekday rows persisted, re-read from API |
| Special hours added | PASS — persisted and listed |
| Closure / maintenance period added | PASS — persisted and listed |
| Table create form shows venue, type, reservation status, eligibility, notes | PASS |
| Table-to-venue association persisted | PASS — API returned `venue_id` and venue code |
| Table type and capacity persisted | PASS — `booth`, 8 and 4 seats in separate runs |
| Table maintenance period added and dialog closed cleanly | PASS |
| Dark theme applied on the new screens | PASS — `data-resolved-theme="dark"` |
| Mobile layout (venue cards and table cards) | PASS |
| Console errors during the flows | 0 |
| Loading state | Observed on first paint of the venues screen |
| Error state | Not triggered (no failure path exercised) |
| Permission-based visibility for a restricted staff user | NOT BROWSER-VERIFIED — enforced by automated test (403) and permission-gated navigation; not driven in the browser |

---

## 6. Static checks

- `tsc --noEmit` (frontend): PASS, no output.
- `php -l` on all new PHP files: PASS.
- `php artisan route:list --path=admin/venues`: all venue and schedule routes registered.
