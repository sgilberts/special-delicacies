# Stage 5.0 — Prerequisite Verification and Reservation Domain Design

**Project:** Special Delicacies
**Phase:** 11B.2 — Stage 5 (Bookings, Reservations and Future Module Integration)
**Report date:** 28 November 2026 (Africa/Accra)
**Authorisation:** Stage 4D accepted by the project owner; Stage 5 authorised to commence.
**Method:** Read-only inspection of the working tree, Laravel route registry, migrations, models, services, middleware, seeders, frontend router/components, and read-only metadata queries against the running MySQL 8.4 instance in the sandbox compose stack.

**Nothing in this stage changed application code, database schema, data, or configuration.** This document is the Stage 5.0 deliverable, together with `docs/STAGE-5-DECISION-REGISTER.md`.

---

## 1. Executive summary

The repository is an operating Laravel 13 + React 19 application with a genuine, reusable foundation for Stage 5:

- **One customer identity** — `customers` (Sanctum-authenticatable) plus `organisations`, `organisation_memberships`, `organisation_membership_permissions`, `organisation_invitations`. Verified.
- **One commercial-document engine** — `commercial_documents` / `_lines` / `_histories` with quotation and invoice types, issue/accept/decline/cancel/void lifecycle. Verified.
- **One financial engine** — `payment_transactions` (now purpose-classified and optionally unlinked from orders), `payment_allocation_operations` / `payment_allocations` / `payment_allocation_reversals`, `payment_receipt_intents`, `customer_credit_ledger_entries`, with integer-minor-unit arithmetic helpers in `PaymentAllocationService`. Verified.
- **One permission system** — `roles`/`permissions`/pivots, `permission:` route middleware (any-of semantics), `EnsureStaffAccount`, and organisation-scoped `EnsureOrganisationPermission` + `OrganisationAuthorization`. Verified.
- **One audit convention** — `AuditService::log()` → `admin_audit_logs` (actor, action, entity, old/new values, IP, UA) for staff, and `customer_audit_logs` for customer-initiated actions. Verified.
- **One design system** — Tailwind 4 tokens, `ThemeProvider` with light/dark/system persisted to `localStorage`, `ConfirmDialog`, `Toast`, `PasswordInput`, `ThemeSelector`. Verified.

**Three prerequisites are not satisfied, and they gate Stage 5.6 (payments) and part of 5.5/5.7. They are recorded as owner decisions rather than worked around:**

1. **The payment gateway engine is hard-bound to `Order`.** `GatewayPaymentService::initialize(Order)`, `verify()` resolves `Order::where('payment_reference')`, and every webhook branch resolves an `Order`. There is no payable abstraction. A reservation deposit cannot be charged through it today without an additive extension.
2. **No refund workflow and no credit notes exist.** `refunded` appears only as a reporting `status` value counted in `FinancialController`. There is no refund record, permission, endpoint, or service. Stage 5.6/5.8 require "the existing refund workflow" — it does not exist.
3. **Reservation deposit percentages are not configured anywhere**, and no venue, table-availability, operating-hours, or closure model exists. Stage 5.5 explicitly forbids inventing percentages.

Two further findings materially affect the plan:

4. **`invoice`-purpose payment transactions have no creation path.** Every `PaymentTransaction::create()` call is in `PaymentTransactionService` and is order-bound. The allocation and credit screens therefore operate on a receipt category that the application cannot currently produce. Stage 5 must not depend on staff-recorded invoice receipts unless this gap is closed (see D-6).
5. **Stage 4D is not identifiable by name in the repository.** Reporting and reconciliation exist in substance (`FinancialController` `snapshot`/`transactions`/`showTransaction`/`export`, permissions `financials.reports.view`, `financials.reconciliation.view`, `financials.reports.export`, `financials.payments.confirm_cash`), but no document, migration, or code names Stage 4D, and `financials/reconciliation` is routed to the same handler as `transactions`. Confirmation of the accepted 4D scope is requested before Stage 5.6 (see D-7).

**Verdict:** Stage 5.1–5.4 can proceed on the current architecture without conflicting with accepted work. Stage 5.5 confirmation logic can be built but cannot be *configured* until D-2 is answered. Stage 5.6 is blocked pending D-1 (refund policy/workflow) and the payable-extension decision D-8. No prerequisite was found to be incompatible; no destructive change is required.

---

## 2. Repository and version-control state

| Item | Verified value |
|---|---|
| Working directory | Repository root (Laravel `backend/`, React `frontend/`, `docs/`) |
| Current branch | `feature/stage-4b-completion` |
| HEAD commit | `95d6517` — "Add Reverb broadcasting, local CORS and Vite build updates, and payment/delivery refinements" |
| Local `main` | Same commit `95d6517`, tracking `origin/main` |
| Upstream of current branch | `origin/feature/stage-4b-completion` — **gone** (remote branch no longer exists) |
| Remote | `origin` → `https://github.com/sgilberts/special-delicacies.git` |
| Working tree | Clean except one untracked sandbox artifact: `frontend/.vite.config.preview.ts` |
| Other branches / tags | None cached beyond `origin/main`; no tags |
| Migration files | 43 |
| Applied migrations in the running database | 43 (all ran) |
| Tables in the live schema | 45 |

**Notes for the owner.** The current branch has no upstream, so pushes create a new remote branch rather than updating one. Local `main` and the working branch are identical commits, so the accepted Stage 4A–4D work present in the tree is what both branches contain. No destructive Git operation was performed, and no branch was switched. Work for Stage 5 will continue on the current branch unless the owner directs a dedicated Stage 5 branch.

**Untracked file.** `frontend/.vite.config.preview.ts` is a sandbox preview wrapper, not application source. It was not created in this stage and was left untouched.

---

## 3. Runtime verification

Compose stack (`docker-compose.base44.yml`, external environment configuration), all services healthy:

| Service | Image | State |
|---|---|---|
| `backend` (php-fpm/artisan) | project dev image | Up, healthy |
| `db` | `mysql:8.4` → server **8.4.13** | Up, healthy |
| `queue` | project dev image | Up, healthy |
| `reverb` | project dev image | Up, healthy |
| `web` (Vite dev server) | `node:24-bookworm`, host port **3000 → 5173** | Up, healthy |

The supported MySQL version is therefore 8.4.13, matching the stated 8.4.11 target line. PHPUnit is configured for `sqlite` / `:memory:` (`backend/phpunit.xml`), so MySQL-specific verification must be run separately against a disposable schema (see §11).

---

## 4. Verified inventory (the 14 mandatory inspections)

### 4.1 Application structure
- `backend/`: Laravel 13 API under `/api/v1`, Sanctum tokens, `app/Models` (41 models), `app/Services` (16 services), `app/Http/Controllers/Api/V1` (+ `Admin/`), `app/Http/Middleware`, `app/Events`, `app/Mail`, `app/Contracts`.
- `frontend/`: React 19 + TypeScript + Vite 8 + Tailwind 4 + React Router 7, Axios, React Hook Form, Zod, `laravel-echo`/`pusher-js`. Routes in `frontend/src/App.tsx`; page groups `public/`, `account/`, `customer/`, `admin/`.
- No `app/Jobs` and no `app/Notifications` directory; mail is sent synchronously.

### 4.2 Customer and organisation modules — **exists**
`Customer` (name, email, phone, password, `is_active`, `email_verified_at`, `phone_verified_at`; `HasApiTokens`), `Organisation` (`reference`, `owner_customer_id`, `status` active/suspended), `OrganisationMembership` with roles `owner`/`administrator`/member, `OrganisationMembershipPermission` (per-organisation grants with `granted_by_customer_id`), `OrganisationInvitation`, `CustomerAuditLog`.
Organisation authorisation is centralised in `OrganisationAuthorization::allows()` over a static role→permission map (`view`, `profile.update`, `status.manage`, `members.*`, `documents.view|manage|accept`), enforced by `EnsureOrganisationPermission`, which returns 404 for non-members (no existence disclosure).
Customer routes: `/customer/profile`, `/orders`, `/quotations*`, `/invoices*`, `/credit`, `/invitations*`, `/organisations*`. Guest checkout exists on `orders` (contact fields), and customer accounts are a separate, additive identity — there is **no guest→customer conversion service**.

### 4.3 Quotation and invoice functionality — **exists**
`CommercialDocument` (`TYPE_QUOTATION`, `TYPE_INVOICE`; statuses draft/issued/accepted/declined/expired/cancelled/voided), lines with quantity/unit price/discount/tax, and an append-only `CommercialDocumentHistory`. Customer accept/decline endpoints exist; staff CRUD under `/admin/commercial-documents`. Monetary columns are `decimal(14,2)`; currency `char(3)` default GHS.
**No deposit field, deposit percentage, or deposit schedule exists on a commercial document.** `terms`/`notes` are free text.

### 4.4 Payment gateway integration — **exists, order-bound**
`PaymentGateway` records (provider `paystack`/`hubtel`/`korapay`, `public_key`, `secret_key`, `webhook_secret`, `configuration`, `is_default`, `is_enabled`) managed under `/admin/payment-gateways`.
`GatewayPaymentService`: `initialize(Order)`, `verify(string $reference): ?Order`, `consumeWebhook(provider, payload, signature, paystackSignature): bool`.
Security controls verified in code: Paystack HMAC-SHA512 signature with 300-second timestamp tolerance against `webhook_secret` (legacy secret-key fallback only for pre-`payment_gateway_id` orders); Kora HMAC over canonical `json_encode` of the data object; Hubtel callbacks re-verified against the authenticated transaction-status API before any state change; GHS currency check and integer-minor-unit amount equality before marking paid.
**Every entry point takes or resolves an `Order`.** There is no reservation/payable path.

### 4.5 Deposits, customer credit, refunds, credit notes
- **Deposits/advances:** `payment_receipt_intents` (`intent` = `deposit`|`advance`, unique `operation_reference`, actor snapshot, `designated_at`) — a *classification* of an existing verified receipt, not a new money record.
- **Customer credit:** `customer_credit_ledger_entries` (`entry_type` = `designation`|`application`, unique `operation_reference`, before/after balances, actor snapshots) with MySQL CHECK constraints `credit_ledger_single_payer` (exactly one of customer/organisation) and `credit_ledger_entry_shape` (designation requires a source payment and no document; application requires a document and no source payment). `CustomerCreditService` exposes `classifyReceipt`, `designateCredit`, `applyCredit`, `balanceMinor`, customer and staff views.
- **Allocations:** `PaymentAllocationService::allocate`/`reverse`, `payment_allocations` + `payment_allocation_reversals`, integer-minor-unit formatting.
- **Refunds:** **do not exist.** No refund table, model, service, endpoint, permission, or UI. `refunded` is only a `payment_transactions.status` value counted in `FinancialController`.
- **Credit notes:** **do not exist.** `CommercialDocument` has no credit-note type.
- **Receipt documents:** do not exist. A "receipt" is a `payment_transactions` row; `payment_receipt_intents` is the deposit/advance classification.
- Known structural gap: `invoice`-purpose receipts are read everywhere but **created nowhere** (see §5.4).

### 4.6 Stage 4D reporting and reconciliation
Present in substance, unnamed in the repository:
- `FinancialController@snapshot` (aggregates, daily series, refunded amounts), `@transactions` (filtered register), `@showTransaction`, `@export` (CSV stream).
- Routes `/admin/financials/{snapshot,reports,transactions,transactions/{id},reconciliation,export.csv}` guarded by `financials.view`, `financials.reports.view`, `financials.transactions.view`, `financials.reconciliation.view`, `financials.reports.export`.
- `financials/reconciliation` is routed to the same `transactions` handler; there is no distinct reconciliation queue or exception workflow.
- `FinancialPaymentUpdated` broadcast event exists.
No file, migration, or document in the repository references "Stage 4D". See D-7.

### 4.7 Authentication, RBAC, permissions, audit
- Sanctum personal access tokens, `ResolveRememberedApiToken` middleware, `EnsureStaffAccount` (active staff), `EnsureCustomerAccount` (active customer).
- `permissions` seeded by `RoleAndPermissionSeeder` and by migrations; `permission:` middleware accepts comma/pipe lists with **any-of** semantics.
- Financial permissions verified: `financials.view`, `financials.transactions.view`, `financials.reports.view`, `financials.reports.export`, `financials.reconciliation.view`, `financials.payments.confirm_cash`, `financials.allocations.view|manage|reverse`, `financials.deposits.view|manage`, `financials.customer_credit.view|manage`, `financials.invoice_balances.view`.
- Role construction: `super-admin` receives all permissions; `admin` receives all except `financials.*`, `chat.*`, `reverb.monitor`; operational roles receive explicit lists. New permissions are **not** auto-granted except where a migration explicitly grants them.
- Audit: `AuditService::log(User|int|null $actor, action, description, ?Model $entity, ?old, ?new, ?Request)` → `admin_audit_logs` with recursive redaction of sensitive keys. Customer-side actions use `customer_audit_logs` (`action`, `entity_type`, `entity_id`, `changed_fields`).
- **Constraint:** `AuditService` types its actor as `User|int|null`; there is no customer actor path in the staff audit log.

### 4.8 Notifications and communication
- Mail: `app/Mail/AccountOtpMail`, `app/Mail/PasswordRecoveryMail`. `.env.example` sets `MAIL_MAILER=log`; no runtime inspection of live mail configuration was performed.
- No `Notification` classes, no `notifications` table, no queued job classes (the `jobs` table is the queue transport).
- Realtime: Reverb + Echo, order/chat/financial broadcast events (`OrderPlaced`, `OrderConfirmed`, `FinancialPaymentUpdated`, chat events).
- Chat: `chat_conversations`, `chat_messages`, `chat_notifications` (in-app chat unread counters), settings/presence/audit.
- Contact enquiries: `ContactController` + public contact form (currently the only place the word "reservation" appears in the product: a subject option in `ContactPage.tsx`).

### 4.9 Design system
Tailwind 4 (`src/index.css` tokens), `ThemeProvider`/`useTheme`/`ThemeContext` with `light | dark | system` persisted under `special-delicacies-theme`, `ThemeSelector` and `ThemeToggle`. Shared UI: `ConfirmDialog`, `Toast`/`ToastContext`/`useToast`, `PasswordInput`. Admin navigation is permission-filtered in `AdminLayout.tsx` (sections: operations, commercial, finance, restaurant, settings). Logout confirmation modal exists in `AdminLayout.tsx` and `CustomerAccountPage.tsx`.

### 4.10 Database
45 tables. Core for Stage 5: `customers`, `organisations`, `organisation_*`, `commercial_documents*`, `payment_transactions` (nullable `order_id`, `payment_purpose`, `customer_id`, `organisation_id`, status, `verified_at`, `received_at`, `reporting_date`), `payment_allocation_*`, `payment_receipt_intents`, `customer_credit_ledger_entries`, `payment_gateways`, `restaurant_tables`, `table_qr_codes`, `orders`/`order_items`/`order_status_histories`, `roles`/`permissions`/pivots, `admin_audit_logs`, `customer_audit_logs`, `restaurant_settings` (key/value/group).
`restaurant_tables`: `table_number` (globally unique), `capacity`, `is_active` — **no venue, no table type, no availability window, no reservation flag**.
Financial conventions: monetary `decimal(14,2)` in the commercial/allocation/credit tables; `payment_transactions.amount` is `decimal(12,2)`; all arithmetic that matters is done in integer minor units.

### 4.11 Tests and conventions
36 PHPUnit files (`tests/Feature`, `tests/Unit`), SQLite in-memory, `RefreshDatabase`-style isolation, `Bootstrap::seed()` for RBAC where needed. Relevant existing suites: `FinancialManagementTest`, `PaymentAllocationTest`, `Stage4bCreditAndSecurityTest`, `CommercialDocumentsTest`, `OrganisationFoundationTest`, `CustomerIdentityTest`, `MigrationRollbackVerificationTest`, `RbacTest`, `PaymentGatewayManagementTest`, `PickupAndDeliveryPaymentTest`.
Frontend: no component-test framework; one Node test (`frontend/tests/rbac-access.test.mjs`, run via `npm run test:rbac`) plus `oxlint`. Browser verification is done against the running app.

---

## 5. Conflicts, gaps and risks

### 5.1 No reservation, venue, capacity or operating-hours domain exists
Nothing in the schema or code models a venue, table availability window, service period, closure, or reservation. `restaurant_tables` exists solely for dine-in QR ordering and is globally unique by table number. Stage 5 must add all of it additively. **No conflict**, but the table registry decision (D-5) must be taken before 5.2.

### 5.2 The gateway engine cannot charge anything that is not an `Order`
`initialize(Order)`, `verify()` and all three webhook branches resolve an `Order`; amount validation compares against `orders.total`; the Paystack `metadata` carries `order_number`; the callback URL is the order payment-result page. Reusing it for reservation deposits requires an additive payable extension (D-8). Building a second gateway engine would violate §4.2 of the Stage 5 mandate.

### 5.3 No refund workflow and no credit notes
Stage 5.6 ("use the existing refund workflow and permissions"), 5.8 ("initiate an authorised refund through the existing financial workflow") and 5.9 (audit "refund initiation and outcome") have no existing implementation to integrate with. Cancellation-and-refund policy is also undefined. This is a stop condition (§11 of the mandate) — D-1.

### 5.4 `invoice`-purpose receipts cannot be created
`PaymentAllocationService::eligiblePayments` and `CustomerCreditService::eligibleReceipts` filter `payment_purpose = 'invoice' AND order_id IS NULL`, but the only `PaymentTransaction::create()` sites are order-bound inside `PaymentTransactionService`. Consequence: the allocation and credit screens are functionally empty in a fresh environment, and Stage 5 cannot model a reservation deposit as an invoice-purpose receipt unless that path is built. D-6.

### 5.5 Stage 4D is unverifiable by name
Reporting/reconciliation exists, but the accepted 4D scope cannot be confirmed from the repository: no Stage 4D document, no reconciliation-specific service, and `/admin/financials/reconciliation` reuses the transactions handler. D-7.

### 5.6 Audit actor model is staff-only in `admin_audit_logs`
Reservations are created by customers as well as staff. Customer actions must go to `customer_audit_logs`, and reservation status history must carry actor snapshots (staff *or* customer). Extending `AuditService` to accept a customer actor is an additive change that must not alter existing staff logging.

### 5.7 Dine-in occupancy does not block reservations
`orders.restaurant_table_id` exists, but there is no occupancy window concept, so a dine-in order does not currently make a table unavailable. Whether Stage 5 availability must consider live dine-in orders is an owner decision (D-5b), because including it changes availability results but must not change accepted order behaviour.

### 5.8 Deposit percentages and cancellation policy are undefined
No percentage, deposit amount, cancellation window, or refund policy is stored or configured anywhere. Both must come from the owner (D-2, D-1). The mandate forbids inventing them.

### 5.9 Documentation drift
`docs/ARCHITECTURE.md`, `docs/DATABASE-SCHEMA.md`, `docs/PHASE-11A-ARCHITECTURE-AND-READINESS.md` (25 Nov 2026) still state that customer accounts, organisations, quotations, invoices and credit do not exist. They now do. Stage 5 documents must be written against the code, and those older documents should be corrected under separate authorisation.

### 5.10 Currency precision
`payment_transactions.amount` is `decimal(12,2)` while commercial documents, allocations and credit use `decimal(14,2)`. Reservation money columns will use `decimal(14,2)` and all computation will use integer minor units, matching `PaymentAllocationService`.

---

## 6. Domain design

### 6.1 Entity map (additive)

| Entity | Purpose | Key relationships |
|---|---|---|
| `reservations` | The booking itself: reference, category, window, party size, status, agreed commercial terms, deposit terms snapshot | `customer_id`, nullable `organisation_id`, nullable `venue_id`, nullable `commercial_document_id`, `created_by` (user), `approved_by`, `confirmed_by` (users) |
| `reservation_status_histories` | Append-only lifecycle record | `reservation_id`, actor user/customer + snapshots |
| `reservation_allocations` | One row per resource commitment: a table or a whole venue, with its own window and release timestamp | `reservation_id`, nullable `restaurant_table_id`, nullable `venue_id` |
| `reservation_venues` | Physical/bookable venue: type, capacity, operational and reservable flags, internal notes | — |
| `reservation_service_periods` | Configurable operating windows per weekday, globally or per venue | nullable `venue_id` |
| `reservation_venue_closures` | Closures, maintenance and temporary unavailability over a datetime range | `venue_id` |
| `reservation_deposit_settings` | Default deposit percentage per reservation category | `updated_by` (user) |
| `reservation_deposit_overrides` | Per-reservation percentage change with reason and approver context | `reservation_id`, `actor_user_id` + snapshots |

Table registry: either additive nullable columns on `restaurant_tables` (`reservation_venue_id`, `table_type`, `reservation_available`, `seating_configuration`) or a new `reservation_tables` table. Recommendation and rationale in D-5.

Payment linkage: nullable `reservation_id` on `payment_transactions` plus a new `payment_purpose` value (`reservation_deposit`). No new payment table. The existing allocation/credit queries filter `payment_purpose = 'invoice'` explicitly, so a new purpose cannot leak into Stage 4B/4C screens — this was verified in `PaymentAllocationService` and `CustomerCreditService`.

### 6.2 Reservation categories
`single_table`, `multiple_tables`, `exclusive_venue` stored as a string column with a service-level allow-list (not a DB enum), so future categories (`event_catering`, `corporate_account`) can be added additively without a schema redesign. Category drives: default deposit percentage lookup, allocation rules (tables vs whole venue), and capacity validation.

### 6.3 Lifecycle and transitions

```
draft ──► requested ──► under_review ──► awaiting_deposit ──► confirmed ──► checked_in ──► completed
                │              │                  │                │
                │              ├──► declined      │                └──► cancelled
                │              └──► cancelled     ├──► expired  (deposit window elapsed)
                └──► cancelled                    └──► cancelled
```

Enforced server-side as an explicit transition table (from → to → required permission → side effects). No arbitrary status writes. `confirmed` additionally requires: availability re-validated under lock, allocations persisted, and — when a deposit is required — a verified receipt covering the required deposit.

**Capacity is consumed only by `reservation_allocations`**, which are created at approval (as a hold, if holds are adopted) and retained at confirmation. A `requested` reservation consumes nothing, which satisfies "do not treat an unpaid request as a permanently confirmed reservation" without inventing a hold policy. Holds, if the owner wants them, are a single nullable `hold_expires_at` on the reservation plus a scheduled expiry command — D-4.

### 6.4 Availability engine
Single service (`ReservationAvailabilityService`) answering: given category, date window, party size, requested tables/venue, return per-resource and per-venue results plus a reason code (`available`, `insufficient_capacity`, `already_allocated`, `venue_closed`, `outside_service_period`, `resource_unavailable`). Inputs: allocations overlapping the window, exclusive-venue allocations for the venue, closures, service periods, table capacity/operational flags, venue capacity.

**Backend is the only authority.** Frontend results are advisory. Every submit/approve/confirm re-runs the engine inside a transaction that locks the candidate resource rows with `lockForUpdate()` in deterministic id order (the existing convention in `PaymentTransactionService` and `PaymentAllocationService`), then re-checks overlap before inserting allocations. MySQL 8.4 has no exclusion constraints, so overlap correctness is a service guarantee backed by composite indexes on `(resource_id, starts_at, ends_at)` and a concurrency test that races two confirmations for the same table.

### 6.5 Deposit engine
- Defaults per category in `reservation_deposit_settings` (no seeded values — D-2).
- Resolution order at request/approval time: staff override (if permitted) → category default → **missing configuration** (reservation may be reviewed but cannot be confirmed).
- The resolved percentage and computed amount are snapshotted onto the reservation with a documented reason: they are the agreed commercial term, and a later change to the global default must not rewrite an existing reservation (mirrors the actor-snapshot convention already used in Stage 4A).
- Arithmetic in integer minor units (`round(amount * 100)`), displayed as GHS with two decimals. Overpayment/underpayment never silently satisfies a deposit: satisfied only when verified receipts linked to the reservation are ≥ the required amount.
- `amount_paid` and `outstanding` are **derived** from linked verified `payment_transactions`; they are not stored on the reservation.
- Quotation-derived reservations: `booking_value` snapshots `commercial_documents.total` and `commercial_document_id` is preserved; no second financial obligation is created. Quotations carry no deposit field (verified), so no previously agreed deposit term can be silently overridden — see D-3.
- Reservation percentage deposits stay separate from any fixed-amount event-catering deposit policy; none exists in code today.

### 6.6 Permissions (proposed, not granted)
Staff slugs follow the existing `domain.action` convention; organisation-side capabilities extend the existing `OrganisationAuthorization` map rather than a new role system. Full catalogue, rationale and role mapping: §8.

### 6.7 Future-module seams
`reservations.commercial_document_id`, the category allow-list, the allocation table (resource-typed), and the deposit-settings table per category are the extension points for event catering, corporate/school accounts and venue operations. No future module is implemented in Stage 5.

---

## 7. Proposed additive schema

All tables `InnoDB`, `utf8mb4`, monetary columns `decimal(14,2)`, currency `char(3)`, FK deletion rules `restrictOnDelete` for financial/historical references and `nullOnDelete` for actor references, with actor snapshot columns on every audited transition (matching Stage 4A conventions). Every migration is additive and reversible with a safe `down()`.

| Table | Key columns | Indexes / constraints |
|---|---|---|
| `reservation_venues` | `code` unique, `name`, `venue_type`, `description`, `location`, `max_capacity`, `is_active`, `is_reservable`, `internal_notes` | `(is_active, is_reservable)` |
| `reservation_service_periods` | nullable `reservation_venue_id`, `weekday` 0–6, `opens_at`, `closes_at`, `is_closed`, `slot_minutes`, `label` | `(reservation_venue_id, weekday)` |
| `reservation_venue_closures` | `reservation_venue_id`, `starts_at`, `ends_at`, `closure_type`, `reason`, `created_by` | `(reservation_venue_id, starts_at, ends_at)` |
| `restaurant_tables` (additive) *or* `reservation_tables` | venue link, `table_type`, `reservation_available`, `seating_configuration` | see D-5 |
| `reservations` | `reference` unique, `customer_id`, `organisation_id`, `reservation_venue_id`, `commercial_document_id`, `category`, `status`, `reservation_date`, `starts_at`, `ends_at`, `party_size`, `tables_requested`, `booking_value`, `deposit_percentage`, `deposit_amount_due`, `deposit_required` bool, `special_instructions`, `internal_notes`, `created_by`, `approved_by`, `confirmed_by`, `hold_expires_at`, cancellation fields, `expired_at` | `(status, reservation_date)`, `(customer_id, created_at)`, `(organisation_id, created_at)`, `(reservation_date, status)` |
| `reservation_status_histories` | `reservation_id`, `from_status`, `to_status`, `actor_user_id` nullable, `actor_customer_id` nullable, `actor_name_snapshot`, `actor_reference_snapshot`, `reason`, `metadata` json, `created_at` | `(reservation_id, created_at)` |
| `reservation_allocations` | `reservation_id`, nullable `restaurant_table_id`, nullable `reservation_venue_id`, `allocation_type`, `starts_at`, `ends_at`, `released_at`, `created_by` | `(restaurant_table_id, starts_at, ends_at)`, `(reservation_venue_id, starts_at, ends_at)`, `(reservation_id)`; CHECK: exactly one resource per row |
| `reservation_deposit_settings` | `category` unique, `percentage`, `updated_by`, timestamps | unique `category`; CHECK `percentage BETWEEN 0 AND 100` |
| `reservation_deposit_overrides` | `reservation_id`, `original_percentage`, `new_percentage`, `actor_user_id`, snapshots, `reason`, `approved_context`, `created_at` | `(reservation_id, created_at)` |
| `payment_transactions` (additive) | nullable `reservation_id` FK restrict | `(reservation_id, status, received_at)` |

No existing column is dropped, retyped, or backfilled. No historical financial row is touched.

---

## 8. Permission catalogue (proposed)

| Slug | Purpose | Suggested roles (not granted) |
|---|---|---|
| `reservations.view` | View reservation register and details | admin, waiter/waitress, manager |
| `reservations.create` | Create reservations on behalf of a customer | admin, waiter/waitress |
| `reservations.review` | Move a request into review and record notes | admin, waiter/waitress |
| `reservations.approve` | Approve a request (allocates resources) | admin |
| `reservations.decline` | Decline a request with reason | admin |
| `reservations.allocate` | Allocate tables/venue to a reservation | admin, waiter/waitress |
| `reservations.allocate.modify` | Change or release an allocation | admin |
| `reservations.venues.manage` | Create/edit venues, closures, service periods | admin |
| `reservations.tables.manage` | Manage reservation table configuration | admin |
| `reservations.availability.manage` | Manage availability overrides and holds | admin |
| `reservations.deposits.settings.manage` | Configure default percentages per category | super-admin only (financial policy) |
| `reservations.deposits.override` | Override the percentage on one reservation | admin, with audit |
| `reservations.confirm` | Confirm an eligible reservation | admin |
| `reservations.checkin` | Check guests in | waiter/waitress, admin |
| `reservations.complete` | Mark completed | waiter/waitress, admin |
| `reservations.cancel` | Cancel with reason | admin, waiter/waitress |
| `reservations.financials.view` | View deposit/payment position on a reservation | admin (in addition to `financials.*`) |
| `financials.reservation_refunds.initiate` | Initiate an authorised refund (only once D-1 defines a workflow) | super-admin |

Organisation-scoped (customer-facing, via `OrganisationAuthorization`, not the staff RBAC): `reservations.view`, `reservations.manage` for `owner` and `administrator`; member default `reservations.view` only if the owner approves (D-9).

No permission is granted to any existing role by Stage 5 migrations. Super-admin's "all permissions" sync will pick new slugs up automatically only where an explicit sync runs; that behaviour will be documented and left to the existing grant process.

---

## 9. Implementation plan by stage

| Stage | Deliverable | Depends on |
|---|---|---|
| **5.0** | This report + decision register | — |
| **5.1** | Additive migrations (§7), models, relationships, factories, relationship documentation | D-5 (table registry) |
| **5.2** | Venue/table/service-period/closure configuration: admin endpoints, permissions, admin UI, tests | 5.1, D-5 |
| **5.3** | `ReservationAvailabilityService`, availability endpoints, locking + concurrency tests | 5.2 |
| **5.4** | Lifecycle service with explicit transition table, status history, expiry handling, APIs, tests | 5.3, D-4 |
| **5.5** | Deposit settings, resolution, override workflow + audit, calculation service, tests | D-2, D-3 |
| **5.6** | Reservation deposit payment initiation, server-side verification, idempotent webhook handling, financial classification, reporting visibility | D-1, D-8, D-6, D-7 |
| **5.7** | Customer and organisation reservation surfaces, org scoping, customer history/pay/ cancel-request | 5.4, 5.6 |
| **5.8** | Staff dashboard, register, detail, actions with confirmations | 5.4, 5.5 |
| **5.9** | Mail notifications via existing mail + events, audit coverage, operational queue views | 5.4, 5.8 |
| **5.10** | Backend/MySQL/frontend/browser verification, eight documents, final report | all |

Each stage stops at its boundary for owner review, as mandated. No stage is claimed complete on code alone.

---

## 10. Test plan (Stage 5.10 targets, mapped to the mandate's 32 cases)

Backend (SQLite for speed, MySQL for fidelity): creation, validation, customer/organisation association and isolation, all three categories, availability, overlap prevention, concurrent allocation prevention (two parallel transactions), closure handling, valid and invalid transitions, deposit defaults, missing configuration, override + audit, quotation-derived booking value, calculation edge cases (rounding, under/overpayment), payment initiation, verification, invalid/replayed payment rejection, duplicate webhook idempotency, failed/abandoned payment, confirmation preconditions, cancellation, expiry + allocation release, refund integration (after D-1), permission enforcement per endpoint, reporting visibility, audit records.

MySQL verification: disposable schema (e.g. `special_delicacies_stage5_test`) created and dropped by the test harness; assert FK integrity, composite indexes, CHECK constraints, `decimal(14,2)` precision, transaction/locking behaviour, and safe rollback of every new migration. Never against the development or production schema.

Frontend: `oxlint`, the existing Node RBAC test extended for reservation routes, and browser verification of creation, availability presentation, customer history, admin dashboard/register/detail, deposit configuration and override, payment progress, status actions, permission-filtered navigation, light/dark/system themes, and responsive layouts at the mobile viewport in use (675px) plus desktop.

---

## 11. Security review of the plan

- Every reservation route behind `auth:sanctum` + `EnsureStaffAccount`/`EnsureCustomerAccount` and an explicit `permission:` guard; frontend visibility is cosmetic only.
- Organisation isolation via `EnsureOrganisationPermission` and explicit `organisation_id` scoping on every query; 404 (not 403) for non-members, matching existing behaviour.
- No gateway secret reaches the frontend; reservation payment initiation returns only a checkout URL.
- Payment state changes only from server-side verification or a signature-verified webhook; amount and currency are compared in integer minor units against the reservation's stored deposit requirement.
- Idempotency: `transaction_reference` uniqueness plus purpose/reservation scoping, mirroring the existing order path.
- Concurrency: `lockForUpdate` on resource rows in deterministic order, re-validated overlap check inside the transaction.
- Audit: staff actions via `AuditService`, customer actions via `CustomerAuditLog`, plus `reservation_status_histories` with actor snapshots; old/new values preserved for percentage and allocation changes.
- No automatic refund, credit note, credit allocation, or confirmation without verified payment.

---

## 12. Classification of this stage

| Item | Status |
|---|---|
| Stage 5.0 prerequisite verification | **Implemented (read-only inspection)** |
| Domain design | **Implemented (this document)** |
| Decision register | **Implemented** (`docs/STAGE-5-DECISION-REGISTER.md`) |
| Automated-test verified | **Not applicable** (no code changed) |
| MySQL verified | **Read-only metadata only** (version, table list, migration count) |
| Browser verified | **Not applicable** |
| Stage 5.1 implementation | **Not started** — awaiting owner review at the stage boundary |
| Owner decisions pending | D-1 … D-9 |

No deployment, merge, publication, or release was performed. No Stage 6 work was begun.
