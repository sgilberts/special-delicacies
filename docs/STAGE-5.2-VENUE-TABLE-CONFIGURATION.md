# Stage 5.2 — Venue, Table and Capacity Configuration

**Project:** Special Delicacies
**Status:** Implemented
**Authorisation:** Stages 5.1 and 5.2 only; Stage 5.3 not started.

---

## 1. What was inspected before building

- **No venue/branch/location model existed.** `rg` across backend and frontend found only a free-text physical address in restaurant settings. A minimal additive `venues` table was therefore introduced.
- **`restaurant_tables` existed** with `table_number` (global unique), `capacity`, `is_active`, `table_qr_codes` and `orders.restaurant_table_id`. It was extended, not replaced (D-5A).
- **No operating-hours or scheduling model existed.**
- **RBAC** is `roles` / `permissions` / pivots with `permission:` route middleware.
- **Audit** is `AuditService::log()` → `admin_audit_logs` with actor, action, entity, old/new values, IP and user agent.
- **Timezone** is `APP_TIMEZONE` (default `Africa/Accra`).

---

## 2. Database changes (additive)

### 2.1 New tables — `2026_11_28_000001_create_venues_and_venue_configuration_tables.php`

| Table | Columns (summary) |
|---|---|
| `venues` | `name`, unique `code`, `description`, `venue_type`, `location`, `max_capacity`, `operational_status`, `reservation_enabled`, `exclusive_reservation_enabled`, `default_reservation_duration_minutes`, `reservation_slot_minutes`, `internal_notes`, timestamps |
| `venue_operating_hours` | `venue_id` (cascade), `day_of_week` 0–6, `opens_at`, `closes_at`, `is_closed`; unique `(venue_id, day_of_week)` |
| `venue_special_hours` | `venue_id` (cascade), `date`, `opens_at`, `closes_at`, `is_closed`, `reason`; unique `(venue_id, date)` |
| `venue_closures` | `venue_id` (cascade), `closure_type` (`closure`/`maintenance`), `starts_at`, `ends_at`, `reason`, `created_by` (set null) |
| `table_unavailability_periods` | `restaurant_table_id` (cascade), `starts_at`, `ends_at`, `reason`, `created_by` (set null) |

### 2.2 Extended table — `2026_11_28_000002_extend_restaurant_tables_for_reservation_configuration.php`

Adds `venue_id` (nullable, RESTRICT), `table_type`, `operational_status` (default `available`), `reservation_eligible` (default true), `seating_configuration` (JSON), `admin_notes`, plus index `(venue_id, reservation_eligible)`.

**Unchanged:** `id`, `table_number` (still globally unique), `capacity`, `is_active`, timestamps, QR relationships, order relationships and all existing endpoints.

Rollback drops the foreign key before the supporting composite index (MySQL requirement), then removes the added columns. Verified on MySQL 8.4.

### 2.3 Permissions — `2026_11_28_000004_add_reservation_configuration_permissions.php`

Registers `venues.view`, `venues.create`, `venues.update`, `venues.delete`, `venues.configuration.manage`, `tables.availability.manage`. **No role or user is granted anything by this migration**, as instructed; the owner assigns access through Roles & Permissions.

---

## 3. API endpoints

All under `auth:sanctum` + `EnsureStaffAccount`, protected by the existing `permission:` middleware.

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/admin/venues` | `venues.view` / `venues.create` / `venues.update` |
| POST | `/api/v1/admin/venues` | `venues.create` |
| GET | `/api/v1/admin/venues/{id}` | `venues.view` / `venues.update` |
| PUT | `/api/v1/admin/venues/{id}` | `venues.update` |
| DELETE | `/api/v1/admin/venues/{id}` | `venues.delete` |
| PUT | `/api/v1/admin/venues/{venueId}/operating-hours` | `venues.configuration.manage` |
| POST | `/api/v1/admin/venues/{venueId}/special-hours` | `venues.configuration.manage` |
| DELETE | `/api/v1/admin/venues/{venueId}/special-hours/{specialHourId}` | `venues.configuration.manage` |
| POST | `/api/v1/admin/venues/{venueId}/closures` | `venues.configuration.manage` |
| DELETE | `/api/v1/admin/venues/{venueId}/closures/{closureId}` | `venues.configuration.manage` |
| GET | `/api/v1/admin/tables/{tableId}/unavailability` | `tables.view` / `tables.manage` / `tables.availability.manage` |
| POST | `/api/v1/admin/tables/{tableId}/unavailability` | `tables.availability.manage` / `tables.manage` |
| DELETE | `/api/v1/admin/tables/{tableId}/unavailability/{periodId}` | `tables.availability.manage` / `tables.manage` |

Existing table endpoints (`/admin/tables`, `/admin/tables/{id}`, status toggle, QR routes) are unchanged and now also accept/return the reservation configuration fields.

---

## 4. Validation rules

- Venue: name required ≤120; code required ≤40, `A-Z a-z 0-9 _ -`, unique; type in the known list; operational status in the known list; capacity 1–5000; duration 15–720 minutes; slot 5–240 minutes.
- Operating hours: 1–7 rows, distinct `day_of_week` 0–6, `H:i` times.
- Special hours: unique date per venue.
- Closures: type in `closure`/`maintenance`, `ends_at` ≥ `starts_at`.
- Table unavailability: `ends_at` ≥ `starts_at`.
- Table configuration: `venue_id` must exist; type/status in known lists.
- **D-5B safeguard:** moving a table to `maintenance`/`out_of_service` while it holds an active dine-in order returns HTTP 422.

---

## 5. Audit integration

`AuditService` records: `venue.created`, `venue.updated`, `venue.deleted`, `venue.operating_hours.updated`, `venue.special_hour.created`, `venue.special_hour.deleted`, `venue.closure.created`, `venue.closure.deleted`, `table.created`, `table.updated`, `table.status.toggled`, `table.deleted`, `table.unavailability.created`, `table.unavailability.deleted`. Old/new values are captured for updates where applicable.

---

## 6. Administrative interface

- **`/admin/venues`** (`AdminVenuesPage.tsx`): venue list with search/status filter, create/edit/delete, and a configuration dialog with three tabs — operating hours (7 weekday rows), special hours, closures/maintenance.
- **`/admin/tables`** (`AdminTablesPage.tsx`): the existing table screen now shows venue and table type per row, adds venue/type/reservation-status/eligibility/notes to the create-edit form, and adds a maintenance-period dialog per table.
- Navigation: "Venues & Booking" under Restaurant Management, permission-gated with the existing `hasAnyPermission` helper.
- Design system: existing Tailwind tokens, modal conventions, toasts, light/dark/system theme, responsive layouts. No new design system.

---

## 7. Explicitly not implemented

Availability calculation, slot generation, reservation submission/approval, status transitions, deposit configuration or calculation, payments, refunds, portals, staff reservation operations, notifications. Operating hours, closures and unavailability are stored configuration only.
