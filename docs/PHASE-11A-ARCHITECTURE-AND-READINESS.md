# Phase 11A — Architecture and Expansion Readiness Report

**Assessment date:** 25 November 2026 (Africa/Accra)  
**Scope:** Read-only inspection of the existing Special Delicacies application for the approved Phase 11 expansion.  
**Evidence basis:** Source, migrations, current Laravel route registry/configuration, migration status, and read-only metadata for the configured application schema. No tests/builds were run.

## 1. Executive summary

The repository is an operating split React/Laravel application, not a blank foundation. It currently supports public menus, dine-in and delivery orders, staff administration/RBAC, kitchen and delivery workflows, QR table ordering, Paystack/Hubtel/Kora online payment integration, payment transaction records and financial reporting, live chat, audit logs, and Laravel Reverb events. Its principal reusable core is `orders` + `order_items` + `order_status_histories`, the existing menu/pricing model, staff RBAC, `payment_transactions`, `AuditService`, and current email/realtime configuration.

There are no verified customer accounts, customer/organisation ownership model, organisation portals, catering requests, recurring/scheduled meal deliveries, quotations, invoices, reservation/venue booking, or structured dietary/allergen workflow. The current `orders.order_type` database enum permits only `dine_in` and `delivery`. Existing finance is an order-payment reporting subsystem, not a general invoicing or receivables ledger. New work therefore needs carefully planned extensions to this application, with one shared order and financial core and separate workflow records/state transitions for each business category.

The configured `mysql` connection was rechecked and explicitly reported `special_delicacies_db` before all follow-up database commands. MySQL is 8.4.11. There are **27 migration files and 27 recorded migrations, all marked Ran** by `php artisan migrate:status --database=mysql`; the highest batch number is 22, which explains the earlier mistaken “22 migrations” count. The intended table/column structures for the core schema are present in read-only table metadata. Seed/data effects of migrations are not all independently verified. A prior `db:show --counts` call from the earlier assessment enumerated metadata for all schemas visible to the account, including `erm_db`; it was read-only, but crossed the stated boundary. This report preserves that disclosure. No subsequent command inspected, enumerated, queried, or modified `erm_db` or another schema.

No application code, configuration, migrations, or database structures were changed in this follow-up. The consolidated report is the only intended project artifact. No Git, Mercurial, or Subversion metadata was found at the project root or inspected parents, and `git rev-parse` reports that the project is not in a Git working tree. No project backup archive or SQL dump was found and no backup/restore procedure is documented; `backend/database/database.sqlite` exists but is not evidence of a backup of the configured MySQL database. Four of five previously reported unusual filenames currently exist and contain only captured PsySH parse-error text; `count()` is absent. No version history is available to trace their creation.

## 2. Verified existing technology stack

| Area | Verified state | Classification |
|---|---|---|
| Backend | Laravel 13.43.0, PHP 8.4.26; Composer declares Laravel `^13.0`, PHP `^8.3` | EXISTS AND VERIFIED |
| Database | MySQL 8.4.11, active Laravel connection `mysql`, configured/active schema `special_delicacies_db` | EXISTS AND VERIFIED |
| API/auth | Laravel API routes under `/api/v1`; Sanctum; active-account checks and database-backed roles/permissions | EXISTS AND VERIFIED |
| Frontend | React 19.3.1, TypeScript 7, Vite 8, React Router 7, Tailwind 4, Axios, React Hook Form, Zod | EXISTS AND VERIFIED |
| Real time | Laravel Reverb broadcasting; Laravel Echo 2 and Pusher-compatible client are installed/configured | EXISTS AND VERIFIED |
| Payment providers | Gateway configuration and service code for Paystack, Hubtel, and Kora (`korapay` provider slug) | EXISTS AND VERIFIED |
| Outbound email | Laravel mail driver currently resolves to `log` in the inspected local environment | EXISTS BUT REQUIRES EXTENSION/configuration for real customer email |
| Currency | Payment and financial code reports GHS / GH₵ | EXISTS AND VERIFIED |

Environment facts above describe the currently inspected local runtime and should not be generalized to production. No secret values were displayed or recorded.

## 3. Existing application architecture

The application is split into `frontend/` (React SPA) and `backend/` (Laravel API), with `docs/` at the root. Laravel owns validation, data access, prices, order lifecycle and permission checks. API routes are grouped and versioned in `backend/routes/api.php`; frontend route declarations are in `frontend/src/App.tsx`. Backend capabilities are organized around Eloquent models, controllers, form requests, services, events, middleware and migrations. There is no evidence of a dedicated domain/use-case layer separating order categories today.

Current runtime configuration inspected with `artisan about`: local environment, debug enabled, database-backed cache, database queue, database sessions, Reverb broadcaster, log mailer, Africa/Accra timezone. These are observations, not recommendations to change settings.

**Documentation drift — REQUIRES FURTHER INVESTIGATION:** `docs/ARCHITECTURE.md`, `docs/DATABASE-SCHEMA.md`, root `README.md`, and phase documents contain statements such as Reverb/planned work and table/phase statuses that do not consistently match the current implementation. In particular, the docs' 15-table application inventory omits Phase 10A finance and chat tables, while current routes and applied migrations show those features implemented. Treat source and live metadata as current evidence; update documentation as a separate authorized task.

## 4. Existing module and functionality inventory

| Capability | Current evidence | Readiness |
|---|---|---|
| Public site/content/gallery/contact | `frontend/src/pages/public/*`; `PublicContentController`, `ContactController`; editable content endpoints | EXISTS AND VERIFIED |
| Menu/categories/pricing | `MenuCategory`, `MenuItem`, menu services/controllers and `/api/v1/menu`, `/api/v1/admin/menu/*` | EXISTS AND VERIFIED |
| Restaurant tables/QR dine-in | `RestaurantTable`, `TableQrCode`, `TableQrController`; `/api/v1/table-lookup/{token}`; hash-based QR tokens | EXISTS AND VERIFIED |
| Ordinary orders | `Order`, `OrderItem`, `OrderStatusHistory`, `OrderService`; dine-in and delivery creation/management/tracking | EXISTS AND VERIFIED |
| Delivery zones/dispatch | `DeliveryZone`, public ordering, delivery staff endpoints and dashboard | EXISTS AND VERIFIED |
| Kitchen/waiter operations | Existing operational dashboard pages, permissions and status events | EXISTS AND VERIFIED |
| Staff accounts/RBAC/audit | `User`, `Role`, `Permission`, pivots, middleware, staff/role endpoints, admin audit log | EXISTS AND VERIFIED |
| Online payments/transactions | `PaymentGateway`, `PaymentTransaction`, `GatewayPaymentService`, webhook/verify routes | EXISTS AND VERIFIED |
| Financial overview/reconciliation/export | `FinancialController`, `/api/v1/admin/financials/*`, financial permissions and UI | EXISTS AND VERIFIED; order-oriented scope |
| Chat/customer communication | `ChatConversation`, messages, notifications, chat settings/presence/audit; staff and public API | EXISTS AND VERIFIED |
| Customer accounts/order history | No customer registration/login or customer identity model observed; public orders store contact fields | DOES NOT EXIST |
| Organisation portals | No organisation, membership, or organisation-scoped access model observed | DOES NOT EXIST |
| Catering/reservations/school/corporate workflows | No matching models, routes, migrations, or frontend pages observed | DOES NOT EXIST |

## 5. Database schema and relationship assessment

Read-only checks explicitly targeted the configured `mysql` connection after confirming its schema is `special_delicacies_db`. They report 39 tables in that schema, including framework tables. The migrations directory contains 27 files; `php artisan migrate:status --database=mysql` lists all 27 as `Ran`. The earlier report mistook the maximum batch number (22) for the number of applied migration records. There is **no files-versus-records discrepancy**. Table and column metadata confirms core objects including `users` (phone, active status, profile photo), `orders` (idempotency and payment fields), `payment_transactions`, gateway configuration and chat tables. All 27 file names and schema/data verification classification are in §5.1. A `Ran` record means Laravel recorded the migration; it cannot on its own prove a reversible history or that every intended row-level transformation remains intact.

### 5.1 Migration file-to-record reconciliation

Every file below has a matching record in `special_delicacies_db.migrations` and is marked **Ran**. No duplicate migration names or pending files were found. “Structure verified” means the expected table/column or constraint was visible in targeted database metadata. For migrations that also transform/seed rows, the recorded migration is verified but the row-level result was not queried; do not treat that data effect as independently verified. Migration source and current schema should still be reviewed together before an implementation release.

| Migration filename | Record? | Intended schema/data effect in approved DB | Reconciliation |
|---|---|---|---|
| `0001_01_01_000000_create_users_table.php` | Yes — Ran | `users` table present | Already applied; structure verified |
| `0001_01_01_000001_create_cache_table.php` | Yes — Ran | `cache`, `cache_locks` present | Already applied; structure verified |
| `0001_01_01_000002_create_jobs_table.php` | Yes — Ran | `jobs`, `job_batches`, `failed_jobs` present | Already applied; structure verified |
| `2026_11_22_002429_create_personal_access_tokens_table.php` | Yes — Ran | `personal_access_tokens` present | Already applied; structure verified |
| `2026_11_22_224329_add_phone_and_is_active_to_users_table.php` | Yes — Ran | `users.phone`, `users.is_active` present | Already applied; columns verified |
| `2026_11_22_230001_create_roles_and_permissions_tables.php` | Yes — Ran | Roles, permissions and pivot tables present | Already applied; tables verified |
| `2026_11_22_230002_create_menu_categories_and_items_tables.php` | Yes — Ran | `menu_categories`, `menu_items` present | Already applied; tables verified |
| `2026_11_22_230003_create_restaurant_tables_and_qr_codes_tables.php` | Yes — Ran | `restaurant_tables`, `table_qr_codes` present | Already applied; tables verified |
| `2026_11_22_230004_create_delivery_zones_table.php` | Yes — Ran | `delivery_zones` present | Already applied; table verified |
| `2026_11_22_230005_create_orders_tables.php` | Yes — Ran | `orders`, `order_items`, `order_status_histories` present | Already applied; tables and order columns verified |
| `2026_11_22_230006_create_restaurant_settings_table.php` | Yes — Ran | `restaurant_settings` present | Already applied; table verified |
| `2026_11_22_233001_update_foreign_key_deletion_rules.php` | Yes — Ran | Source changes order/table/item FK delete rules; `orders` and `order_items` show restrictive order/table/zone references and `SET NULL` menu reference; QR table reference is restrictive | Already applied; FK behavior verified for inspected tables |
| `2026_11_22_233002_add_idempotency_key_to_orders_table.php` | Yes — Ran | Unique nullable `orders.idempotency_key` present | Already applied; column/index verified |
| `2026_11_23_000001_add_payment_fields_to_orders_table.php` | Yes — Ran | Payment method/status and status index present | Already applied; order payment columns/index verified |
| `2026_11_23_000002_add_description_to_delivery_zones_table.php` | Yes — Ran | `delivery_zones.description` present and nullable | Already applied; column verified |
| `2026_11_23_000003_create_admin_audit_logs_table.php` | Yes — Ran | `admin_audit_logs` present | Already applied; table included in approved schema inventory |
| `2026_11_23_000004_add_profile_photo_path_to_users_table.php` | Yes — Ran | `users.profile_photo_path` present | Already applied; column verified |
| `2026_11_24_000001_add_online_payment_fields_to_orders_table.php` | Yes — Ran | Customer email, payment reference, checkout URL; gateway table | Already applied; order/payment gateway objects verified |
| `2026_11_24_000002_expand_payment_gateway_accounts_and_promote_super_admin.php` | Yes — Ran | Gateway JSON config/index and `orders.payment_gateway_id`; also migrates role assignments/data | Already applied; schema verified; role/data outcome not independently checked |
| `2026_11_24_000003_create_payment_transactions_table.php` | Yes — Ran | `payment_transactions` and legacy-order backfill | Already applied; table/columns/indexes/FKs verified; backfill completeness not independently reconciled |
| `2026_11_24_000004_add_financial_permissions_and_admin_role.php` | Yes — Ran | Seeds/updates financial permissions and roles | Record exists; data migration; role/permission rows were not queried, so data outcome requires investigation |
| `2026_11_24_000005_create_chat_tables.php` | Yes — Ran | Conversations, messages, chat audit and notifications | Already applied; tables present in approved schema inventory |
| `2026_11_24_000006_add_chat_permissions_to_rbac.php` | Yes — Ran | Seeds chat permissions/assignments | Record exists; data migration; current permission rows not independently checked |
| `2026_11_24_000007_add_chat_availability_and_presence.php` | Yes — Ran | `chat_settings`, `chat_agent_presence`, plus `chat_messages.thumbnail_path` and other message metadata | Already applied; columns/tables verified in targeted metadata |
| `2026_11_24_000008_add_reverb_monitor_permission.php` | Yes — Ran | Seeds Reverb monitor permission/assignment | Record exists; data migration; row-level result requires investigation |
| `2026_11_24_000009_add_assigned_at_to_chat_conversations.php` | Yes — Ran | `chat_conversations.assigned_at` present | Already applied; column verified |
| `2026_11_24_000010_add_paystack_webhook_signing_secret.php` | Yes — Ran | Gateway webhook signing secret field | Already applied; `payment_gateways.webhook_secret` verified |

### 5.2 Unusual backend filenames

Read-only filesystem inspection found `backend/all())`, `backend/listTableNames())`, `backend/pluck(slug))`, and `backend/toArray())`. Each is a 133-byte plain-text file containing only captured PsySH output: `PARSE ERROR PHP Parse error: Syntax error, unexpected ';' in vendor\\psy\\psysh\\src\\Exception\\ParseErrorException.php on line 44.` The previously reported `backend/count()` is **not currently present**. Repository-wide source search found no code references to the unusual names; matching strings in ordinary tests/controller code are legitimate calls such as `pluck(...)->all()` and are unrelated.

The observed files are not PHP classes/scripts, are outside Laravel's app source namespaces, and there is no route reference to them, so the captured text is not executed as application logic. They can be generated by malformed interactive-console commands or output redirection, but their precise origin cannot be established without version history or shell logs. Their timestamps are filesystem metadata only and are not proof of provenance. Do not delete or rename them during this assessment; preserve and review them as workspace artifacts before a future source-control baseline.

### 5.3 Version control and backup/recovery readiness

- `git rev-parse --show-toplevel` fails with “not a git repository”; no `.git`, `.hg`, or `.svn` metadata was found in the project root or checked parent directories. `docs/INSTALLATION-GUIDE.md` lists Git as a developer prerequisite, but this checkout has no discoverable repository. No other VCS is evidenced by inspected metadata.
- No SQL dump, compressed dump, backup archive, or snapshot file was found under the project. No automated backup or restore procedure was found in project docs/scripts. `backend/database/database.sqlite` is present as a small standalone SQLite file; it is not a backup of the configured MySQL schema and was not opened or queried.
- Documentation says to preserve `APP_KEY` because gateway credentials are encrypted at rest, but there is no in-project key escrow/recovery procedure. Local `.env` contains environment configuration and secrets; it must not be committed in plaintext.
- **Before Phase 11B:** establish a private, access-controlled VCS repository and a known-good baseline that preserves/reviews existing files; configure secret exclusion and protected secret storage; take a consistent, encrypted, off-host backup of `special_delicacies_db` plus user-uploaded storage and required deployment configuration; record the exact code revision/schema state; document restore steps and test a restore into an isolated environment. Preserve `APP_KEY` securely and separately. Capture a fresh backup before each production schema release and only then deploy reviewed forward migrations. These are recommendations; no backup or repository was created here.

Key verified structures and relationships:

- `users` is the staff/authentication identity, extended with phone, active status and profile photo. No customer profile or organisation foreign key is present in the inspected order schema.
- RBAC uses `roles`, `permissions`, `role_user`, and `permission_role`; unique pivots prevent duplicate assignments.
- `menu_categories` → `menu_items`; menu prices use decimal currency values; category deletion is restricted while items exist.
- `restaurant_tables` → `table_qr_codes`; QR token hash is unique; regeneration/revocation logic is implemented.
- `orders` has unique `order_number`, nullable unique idempotency key, enum `order_type` limited to `dine_in`/`delivery`, optional table and delivery-zone references, contact/address/notes, one status, totals, payment method/status, optional gateway link and staff assignments.
- `order_items` snapshots `item_name`, `unit_price`, quantity, special instructions and `line_total`; menu item deletion nulls the reference while preserving the order snapshot.
- `order_status_histories` stores previous/new status, actor and note. Current foreign-key behavior includes nullable staff references on delete and restrictive references to tables/zones/gateways where appropriate.
- `payment_transactions` has a unique transaction reference, order FK (`RESTRICT`), amount/currency/status/provider references, verification/receipt timestamps, confirmer and reporting date. Indexes support order/status, status/received date and reporting date.
- Chat entities have public conversation IDs, hashed visitor key, message identity/delivery/read metadata, assignment, notifications and audit records.
- `admin_audit_logs` supports staff audit history.

Indexes/constraints support current lookup patterns: unique order number/idempotency/payment references; indexes over order type/status, status/created date, payment status, transaction status/received date, order/status, and reporting date. No schema-level uniqueness or exclusion mechanism exists for venue/time overlap, scheduled meal occurrences, organisation membership scope, quotes/invoices, or student dietary allocations.

**Data integrity/performance concerns:** the `orders.order_type` enum makes a simple new business type unsafe without deliberate schema treatment. Adding common optional fields for every future category to `orders` would produce weak invariants and category coupling. A one-transaction-per-order assumption appears in `PaymentTransactionService` and unique `transaction_reference`; partial payments/deposits need explicit payment-allocation semantics and cannot be represented by only toggling order `payment_status`. Existing order indexes are useful but need query-plan/index review once history and schedule volume are known. No migration or live table was changed.

## 6. Existing order lifecycle assessment

`Order::VALID_TRANSITIONS` and `OrderService` implement two state machines: dine-in (`pending → confirmed → preparing → ready → delivered → completed`) and delivery (adds `out_for_delivery` before `delivered`). Cancellation is limited to pending/confirmed. State changes use transaction locks, permission checks and append status history. The service calculates totals from menu prices, snapshots item data, prevents duplicate creation using idempotency/cache locks, and broadcasts operational events. Online orders must be marked paid before preparation/dispatch; cash confirmation has a separate audited path.

Current tracking uses order number and a public `/api/v1/orders/track/{orderNumber}` endpoint; payment reference and customer data must be evaluated carefully as this becomes a pattern for new public tracking. Orders are created immediately as ordinary orders; no quote-first or approval-before-production lifecycle exists. Each order has one `created_at` and one fulfilment status; there is no parent schedule plus per-delivery occurrence model.

| Capability | Status |
|---|---|
| Dine-in and delivery categories | EXISTS AND VERIFIED (only these two) |
| Multiple items/quantities and item snapshots | EXISTS AND VERIFIED |
| Scheduled/recurring deliveries, multiple delivery dates per agreement, per-delivery status | DOES NOT EXIST |
| Quote-before-confirmation / customer quote approval | DOES NOT EXIST |
| Partial payments, deposits, balance due | PARTIALLY IMPLEMENTED at data/reporting level; not supported as allocations against a balance |
| Secure external tracking reference + verification | PARTIALLY IMPLEMENTED: order number tracking exists; secure verification semantics need review/extension |
| Cancellation/refunds | Cancellation exists; refund execution/workflow was not found |

## 7. Financial and payment architecture assessment

**Classification:** EXISTS AND VERIFIED for payment gateway checkout/verification, order-linked transactions and reporting; PARTIALLY IMPLEMENTED for reconciliation; DOES NOT EXIST for invoices, allocation-based balances, deposits and refunds.

Phase 10A is implemented in `backend/app/Services/PaymentTransactionService.php`, `GatewayPaymentService.php`, `backend/app/Models/PaymentTransaction.php`, and `backend/app/Http/Controllers/Api/V1/Admin/FinancialController.php`. `PaymentTransactionService` creates a pending cash/gateway record; on success it locks the order and matching transaction, checks order ownership plus GHS and exact order-total equality, changes the transaction to succeeded with verified/received timestamps, sets the order's binary `payment_status` to paid and emits finance/tracking events. Failed/cancelled gateway statuses are persisted; a previously succeeded transaction is protected from later failure callbacks. Cash confirmation locks the order, changes its transaction to succeeded, records `confirmed_by`, and marks the order paid. Order cancellation calls `cancelPending` after the order status transaction; that routine reads/updates a pending transaction without its own row lock/transaction, so simultaneous cancellation and a payment callback deserve a race test and a future atomicity review.

`GatewayPaymentService` initializes providers and persists a random reference plus checkout URL on the order. The user-facing return calls a rate-limited server verification endpoint; a redirect by itself does not mark payment paid. Paystack verification checks provider success, matching reference, exact GHS and amount in minor units; modern webhook signatures use timestamped HMAC with a five-minute window, and a legacy signature path remains. Hubtel verification queries its transaction-status API using the configured collection account, then checks paid status, matching client reference, amount and currency (the current helper treats missing/empty currency as acceptable for the Hubtel status response); Hubtel callback is reconciled by server-side API query. Kora verification checks success, matching reference, GHS and amount; Kora webhook validates HMAC over the data object. `PaymentGatewayManagementTest.php` and `FinancialManagementTest.php` include mocked provider verification, mismatch, invalid/stale signature, idempotent callback and payment-state cases. Actual external provider connectivity/credentials were not tested.

`payment_transactions` has unique `transaction_reference`; `provider_reference` is indexed but not unique; `order_id` is a restrictive FK; `confirmed_by` is nullable with `SET NULL`; amounts are `DECIMAL(12,2)` and currency is `CHAR(3)` default GHS; JSON metadata and status/date indexes support reporting. `order_id` is **not unique**, so the table can physically hold multiple records for an order. However, the service and order schema are still one-full-payment-centric: order stores one `payment_reference`, one `payment_method`, and binary `payment_status`; gateway success expects each transaction to equal the full order total and a single successful record marks the order fully paid. There is no amount allocation to an invoice or due balance. Unique transaction reference represents one logical attempt/capture; a separate provider-reference uniqueness/idempotency policy is not enforced at schema level.

`FinancialController` calculates daily order counts and collected/unpaid/cancelled amounts, payment channels, current order-type revenue, transaction filtering/details and CSV export. `GET /api/v1/admin/financials/reconciliation` is routed to the same `transactions()` method as the transaction register; the code inspected does not implement a separate provider-settlement reconciliation queue. Phase 10A docs explicitly say no refund workflow exists. The `refunded` status is accepted by reporting filters and aggregated when present, but no refund endpoint/provider refund call or append-only refund process was found. Reported outstanding is based on pending transaction totals; simply adding deposits or repeated full-value attempts risks overstating the receivable. This is operational transaction reporting, not a general ledger.

**Recommendation — extend this finance core, do not introduce a second ledger:** accepted quote versions should be snapshotted into invoices/receivables; payment attempts should be for a server-calculated amount no greater than the locked outstanding due. Keep `payment_transactions` as the existing canonical gateway/cash transaction history, adding only reviewed links/metadata for the payment intent and issued receivable. Add payment-allocation rows that link each successful transaction to one or more invoice/receivable balances with allocated decimal amounts, unique/idempotent source references and audit timestamps/actor. In one DB transaction, lock the invoice/receivable, recompute the remaining balance, verify total allocated + requested amount cannot exceed amount due, persist the verified transaction and allocations, then derive invoice balance/status; for old ordinary orders, keep the existing single-payment path and set `orders.payment_status=paid` only when its full amount is verified. A deposit leaves an explicit outstanding balance. Never represent a refund by rewriting a successful payment as though it never occurred: record provider refund reference/status and a separate reversal/refund amount with idempotent provider callbacks and audit history. If a gateway attempt fails, retry with a fresh unique reference and captured expected amount.

Catering deposit policy must be set per approved catering arrangement as authorized staff-selected fixed amount, full amount, or another explicitly authorized arrangement; snapshot its terms on the accepted quote/invoice. Reservation deposit percentage is a separate policy: optional category default plus staff override, snapshot percentage, base amount and calculated due amount on the reservation. Never share a universal percentage with catering. Tests should prove partial-payment arithmetic, concurrent allocation caps, repeat callbacks, late fail-after-success, reversal/reconciliation and that legacy full-pay orders continue to settle exactly as before.

## 8. Authentication, RBAC and security assessment

**Classification:** EXISTS AND VERIFIED for staff Sanctum/RBAC and endpoint middleware; DOES NOT EXIST for customer/organisation scoped authorization; EXISTS BUT REQUIRES EXTENSION for customer tracking and role responsibilities in new portals.

Staff authentication uses Sanctum bearer tokens. `EnsureUserHasPermission` requires an authenticated active user and checks one or more database permission slugs; `backend/routes/api.php` applies `auth:sanctum` and endpoint permission middleware. Roles and permissions are database entities. `AdminStaffController` account/role assignment and `AdminRolePermissionController` role-permission management are separate protected operations. `RoleAndPermissionSeeder` and `Phase9AdministrationTest.php` demonstrate configurable staff roles; `User::hasPermission()` currently has an intrinsic `super-admin` bypass.

One existing exception to fully permission-driven evaluation is the `super-admin` intrinsic full-access check in `User::hasPermission/hasAnyPermission`, and frontend navigation also checks that slug directly. This is current behavior, not newly introduced here. QR token hashes are stored rather than raw tokens. `OrderService::generateOrderNumber()` uses a date prefix and 8 random bytes (64 random bits); `PaymentController::track()` exposes limited progress without customer phone/email/address, but includes item names/quantities, table/zone, status history and `paymentDetails()`, whose response includes the order payment reference. Treat the order number as a bearer capability: rate-limit lookup/subscription, avoid exposing gateway/provider secrets, and review the payment-reference disclosure before expanding this pattern to invoices, students or reservations. It is not sequential/enumerable by integer ID, but it is not a separately revocable tracking credential.

Frontend `ProtectedRoute` performs page-level checks for some pages, and `AdminLayout` filters navigation by permissions. This is presentation behavior; backend endpoint authorization remains essential. Some dashboard routes are protected only by the enclosing authenticated `/admin` shell in `App.tsx`, while corresponding APIs enforce permissions. For each new route, add route-level permission guards and endpoint middleware independently.

Organisation-account isolation is absent. Future corporate/school users need explicit membership, organisation-scoped permission evaluation, ownership policies on every query, and tests for cross-organisation direct API access. A shared `users` table can represent staff and representatives only if identity types, activation, invitation lifecycle, and assignment policies are made explicit. Do not grant role administration through ordinary staff-account management.

## 9. Organisation-account readiness assessment

**Classification:** DOES NOT EXIST.

No organisation entities, representatives, membership pivots, invitations, or organisation-specific permissions/ownership boundaries were found. Existing RBAC is a useful staff authorization foundation but does not model an organisation's own administrators, coordinators and finance users. A tenant boundary is required for corporate and school records even without offering SaaS tenancy: each record must have an organisation owner, representative membership, scoped role/permission, and policy enforced in controller/service queries.

**Classification:** DOES NOT EXIST. Reuse Sanctum identity and existing permission concepts, but extend with organisation membership and scoped authorization. Multi-tenant SaaS administration is outside the approved scope.

## 10. Event catering readiness assessment

**Classification:** PARTIALLY IMPLEMENTED only for shared customer contact/menu/order primitives; DOES NOT EXIST for catering workflow.

Existing orders can store customer contact fields, multiple menu items, quantities, instructions, totals and status history. Public callers can place ordinary orders without an account. Those pieces are reusable, but ordinary order creation immediately prices and creates an order; there is no event/date/location/guest request model, restaurant quotation preparation, customer approval, invoice email, configurable fixed catering deposit, catering reference tracking, or event fulfilment workflow.

**Classification:** PARTIALLY IMPLEMENTED for contact/menu/order primitives; core catering workflow DOES NOT EXIST. Recommended future path: event-catering request linked to reusable customer/contact and item snapshots, quote versions and approval, then one shared commercial order with payment allocation and fulfilment records. Custom deposit amount belongs to an authorized approved quote/payment arrangement, not a global percentage.

## 11. Corporate meal management readiness assessment

**Classification:** DOES NOT EXIST for organisation-specific programme/schedule and approval workflow; EXISTS AND VERIFIED for reusable menu, order, payment and fulfilment primitives.

There are no corporate accounts, weekly meal schedules, schedule line records, beneficiary counts, customer-proposed menu review, restaurant approval, recurring delivery instances, corporate invoices or account balances. Menu items, pricing, orders, delivery zones, kitchen/delivery staff and transaction reporting can be reused.

**Recommended schedule shape:** one parent meal programme/schedule belongs to an organisation, has a week/date range, draft → submitted → restaurant-approved/rejected → active/closed lifecycle, and versioned proposed/approved weekly menu. Day/meal/location lines store planned item quantities, beneficiary counts, delivery date/time and address snapshot. On restaurant approval, generate one dated **delivery occurrence** per service instance using a unique `(schedule_id, service_date, service/location sequence)` key so retries cannot duplicate production. Link each occurrence to one shared core order and its order-item snapshots; that lets the existing kitchen/delivery/order lifecycle and payment system continue to apply without a parallel order engine. The occurrence holds its own requested/approved quantities, guest/beneficiary count, menu/price/location/time snapshot, production state, dispatch state, delivery confirmation/proof and append-only occurrence history. Order status histories give each linked order an operational trail; add occurrence events for schedule-specific milestones. Parent schedule totals/status are derived from occurrences, not used as the sole fulfillment state. Changes after approval create a new schedule revision and update only future, unreleased occurrences after restaurant review; preserve past and already released production snapshots.

Use generation idempotency and a unique occurrence key. Model production, dispatch and delivery confirmation as distinct validated transitions or explicit timestamps/statuses so dispatch cannot imply delivery. Capture confirmed quantities/substitutions and delivery evidence per occurrence. Index organisation/date, schedule/date, status/date and order link for weekly dashboards and future queue workloads. This same pattern applies to school meal programmes.

**Classification:** DOES NOT EXIST for corporate functionality; shared primitives EXISTS AND VERIFIED.

## 12. School and dietary management readiness assessment

**Classification:** DOES NOT EXIST for school workflow and structured dietary safety data; EXISTS BUT REQUIRES EXTENSION for ordinary item instructions and kitchen fulfillment.

No school portal, school representatives, weekly meal plans, student/beneficiary meal lines, dietary profile or kitchen-level dietary preparation instructions were found. `order_items.special_instructions` is unstructured text; it is not a safe dietary/allergen model. Individual students are not represented in existing order data.

**Recommended reusable dietary data model:** normalized allergen and ingredient catalogue; menu-item ingredient/allergen declarations with an explicit `known`, `unknown`, or review-needed state and version/effective date; requirement types separating **allergy** (safety-critical), intolerance/medical restriction, religious/ethical exclusion and preference (such as vegetarian); per-organisation general requirement sets; per-student requirement profile linked to a school-managed beneficiary identifier and only minimum necessary personal data; and meal-occurrence assignment/substitution rows tying each required student/beneficiary and selected meal to its actual production unit. Allergy records should identify the allergen, severity/handling notes, verification source/date and whether the restaurant can satisfy it. Preference must never be treated as an allergy clearance, and absence of an allergy record must not mean allergen-free.

At approval/order release, compile general + individual + menu ingredient declarations into immutable kitchen instructions on the exact meal/order item: excluded ingredients, approved substitution, separate prep required, dedicated/sanitized equipment or cross-contact control, separately sealed package, required label/recipient code, and production/packing verification by staff/time. Unresolved conflict, unknown ingredient information or unverified handling capability should route for manual restaurant review and block an allergen-safe claim. Do not tell a customer that a meal is allergen-free unless the kitchen can verify and document relevant cross-contact controls. Preserve access controls for sensitive student health details; kitchen staff should see the actionable handling instruction, not unnecessary student identity/medical history. Reuse the requirement and instruction logic for catering and other order categories.

## 13. Reservation and venue-booking readiness assessment

**Classification:** DOES NOT EXIST for reservations; EXISTS AND VERIFIED for physical table inventory only.

`restaurant_tables` represents physical tables and has active/capacity fields, but there are no reservations, booking periods, venue events, whole-venue holds, availability rules, setup/cleanup buffers, reservation quotes, deposits, receipts or reservation history. Existing order table IDs are not time-bounded bookings.

**Recommended availability model:** retain the physical table inventory; add a venue/resource configuration record, booking categories/default deposit policy, reservation master (customer, event, party size, start/end, timezone, setup/cleanup buffers, status, quote/invoice references and deposit snapshot), and reservation-to-table allocations. Support multiple table rows per reservation and all-day/multiple-day occurrence periods. Treat intervals as half-open (`start <= time < end`): two adjacent events meeting at a boundary do not conflict, but extend occupied start/end by the configured setup/cleanup buffers. For a multi-day booking, persist the actual blocked period(s) for each day; do not assume one time window fits every day. An exclusive venue booking conflicts with any overlapping table allocation, and every table booking conflicts with an overlapping exclusive venue block.

**MySQL 8.4 concurrency recommendation:** use InnoDB and serialize the availability decision with stable inventory/resource rows, not just a “query available then insert.” For table bookings, in one short transaction acquire a shared lock on the venue parent row (`SELECT ... FOR SHARE`) and exclusive locks on the selected `restaurant_tables` rows in ascending ID order; for exclusive-venue bookings, acquire an exclusive lock (`SELECT ... FOR UPDATE`) on the venue parent row, which waits for in-flight table reservations and blocks new table holds while it checks/inserts the venue block. All booking writers must follow the same lock order and then execute the overlap check plus insert/hold conversion in that same transaction. The stable venue row also serializes exclusive bookings when no conflicting reservation row exists, avoiding reliance on a predicate lock over an empty range. Add indexes beginning with resource/table and booking start/end/status to make overlap queries bounded; enforce unique reservation-table allocations and valid start/end at the application boundary. A slot-occupancy table with a unique `(resource_id, slot_start)` key is an alternative where fixed slot granularity is acceptable, but intervals/buffers must map consistently and venue exclusivity must reserve all conflicting slots/resources.

MySQL/InnoDB locking reads hold row locks to transaction end; lock scope depends on the indexes/range scanned, and gap/next-key behavior varies with the query and isolation level. Therefore do not assume a `SELECT ... FOR UPDATE` on an empty overlap query alone prevents a phantom under every schema/isolation plan. MySQL's `GET_LOCK()` advisory lock is connection-scoped and is **not** released by transaction commit/rollback, so it is less suitable as the primary reservation guarantee. See the official [InnoDB locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html), [locks set by statements](https://dev.mysql.com/doc/refman/8.4/en/innodb-locks-set.html), [transaction isolation](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-isolation-levels.html), and [locking functions](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html) documentation. This is a design recommendation, not a finding that the current app already implements it.

Temporary holds should be persisted with opaque reference, `expires_at`, status and owner/contact; availability excludes active holds only while `expires_at > now`. Expiry cleanup is idempotent, but correctness must not depend on the cleanup job having run: confirmation and availability re-check expiry using a consistent UTC clock. Hold creation locks resources, tests conflicts and inserts the hold atomically. Do not keep the database transaction open while calling a gateway. Verify provider payment first, then in a second short transaction lock the hold/reservation/resources, check it is still live and non-conflicting, persist payment/allocation and atomically convert hold→confirmed. If valid payment arrives after expiry or another booking has taken the slot, do not silently confirm an overlap; route to exception/refund handling.

Reservation deposit policy is distinct from catering deposits: optional default percentage by reservation category with an authorized staff override for the individual reservation. Snapshot the selected percentage, quote base, computed required amount, currency and actor/time at approval; enforce range and rounding rules server-side. Confirm only on verified gateway transaction or authorized recorded cash, and require configured percentage deposit before confirmed state. Test simultaneous requests for same table, table-vs-exclusive, multi-table, buffer edges, multi-day conflict, hold expiry race, duplicate callbacks and payment arriving after expiration.

## 14. Production and fulfilment readiness assessment

**Classification:** EXISTS AND VERIFIED for immediate dine-in/delivery operations; EXISTS BUT REQUIRES EXTENSION for approved advance orders and per-occurrence preparation/delivery.

Kitchen/waiter/delivery workflows exist for immediate orders with status events, assignments and operational dashboards. They can be reused as the operational foundation. They do not represent prep batches, scheduled production windows, multiple service dates under an agreement, kitchen dietary tasks/labels, reservation setup/cleanup, or separate delivery occurrence status. Avoid routing schedule headers as if they were kitchen-ready orders; create/derive fulfilment work units from approved scheduled deliveries.

**Classification:** EXISTS AND VERIFIED for ordinary order operations; EXISTS BUT REQUIRES EXTENSION for advance/scheduled category fulfillment.

## 15. Tracking and notification readiness assessment

**Classification:** PARTIALLY IMPLEMENTED for ordinary order progress, payment return and realtime operations; DOES NOT EXIST for secure catering/schedule/reservation tracking and invoice email.

Order lookup by order number, public payment result, chat, chat notifications, staff audit events and Reverb order tracking events exist. Email delivery is not verified operationally: local mail driver resolves to `log`; no invoice/quotation email flow or notification preference/outbox was found. Customer-secure tracking for quote, catering, recurring schedules and reservations does not exist. Public order endpoints and response contents require ownership/secret-verification review before extending.

**Classification:** PARTIALLY IMPLEMENTED. Reuse API response/error patterns, Echo/Reverb events and chat/notification primitives. Add purpose-built random tracking references or secure verification, throttling, expiry/revocation strategy, notification logs/outbox and production mail configuration in a separately authorized implementation/deployment step.

## 16. Universal design system assessment

**Classification:** EXISTS AND VERIFIED.

One Tailwind/CSS-token design foundation exists in `frontend/src/index.css`, with semantic foreground/surface/border/accent tokens and shared public/admin components. `ThemeProvider` persists Light/Dark/System in local storage, listens to OS color preference and resolves a root data attribute; theme selector/toggle components are shared. Public and admin surfaces consume the same token family. Brand palette and semantic tokens already establish a coherent restaurant style.

**Classification:** EXISTS AND VERIFIED. New panels should extend the same tokens and components. Risks: ensure future charts/status colors preserve contrast in both modes and ensure no category-specific hardcoded palettes proliferate. `shadcn/ui` is named in docs/requirements but is not present as a dependency in the inspected frontend package manifest; assess the intended reusable component inventory before assuming shadcn components exist.

## 17. Desktop, tablet and mobile responsiveness assessment

**Classification:** EXISTS AND VERIFIED as responsive implementation foundations; REQUIRES FURTHER INVESTIGATION through visual/device QA for current pages and future calendar/data-grid flows.

The CSS defines responsive page gutters and mobile breakpoints; public site includes a mobile bottom navigation with safe-area support, and public main content compensates for the dock. Admin shell has collapsible/mobile navigation and responsive content; many controls include 42–44px minimum sizing and pages include mobile-adaptive patterns. This gives a useful responsive baseline.

**Classification:** EXISTS AND VERIFIED for current site/admin surfaces. A visual/device QA pass was not performed. Tablet-specific dashboard/table behavior, future calendars, long organisation forms, keyboard viewport behavior, and no-horizontal-overflow requirements need explicit acceptance tests during implementation. No blanket claim of verified no-overflow is made.

## 18. API and frontend architecture assessment

**Classification:** EXISTS AND VERIFIED for existing versioned API/SPA patterns; EXISTS BUT REQUIRES EXTENSION for new domain modules and organisation-scoped policies.

API versioning and route organization are clear and centralized in `backend/routes/api.php`. Existing write validation uses Form Requests in core paths, with some controllers using inline Laravel validation. Business logic is partly in services (`OrderService`, `GatewayPaymentService`, `PaymentTransactionService`, `AuditService`, `ChatService`). Models have Eloquent relationships and response conventions typically return `{success, data, message}`. Frontend has feature-oriented `pages/public`, `pages/admin`, shared `components`, auth/theme providers, an Axios API client with bearer-token interceptor and shared cached GETs, and route-level permission helper.

Realtime uses Laravel events and Echo; route and event authorization is present for staff operational channels. Error/loading/empty UI components exist. There is no separate customer-facing account route group or organisation feature grouping. Recommended integration is to add focused controllers/requests/services within the existing version and reuse API client/auth/theme rather than introducing a second backend/API stack.

## 19. Existing test coverage assessment

**Classification:** EXISTS AND VERIFIED for current module test files/configuration; DOES NOT EXIST for approved expansion workflows.

There are 25 backend feature test files and one backend unit test file, covering core order (`OrderTest`, `DuplicateOrderTest`), menu, table/QR, RBAC/security, payment gateway (`PaymentGatewayManagementTest`), finance (`FinancialManagementTest`), chat, Reverb/cancellation, delivery, administration and user profile features. The frontend has one Node RBAC access test (`frontend/tests/rbac-access.test.mjs`). `backend/phpunit.xml` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, array cache/session, null broadcaster, sync queue, and array mailer. That is source-level isolation evidence; no tests were run in either assessment turn.

Expansion test gaps remain for organisation ownership and role scope, quote/invoice lifecycle, partial-payment/allocation caps, catering fixed deposits, schedule approval/idempotent occurrence generation, per-occurrence production/dispatch/delivery histories, general-vs-individual diet requirements, allergy/preference distinctions and safe kitchen instructions, table/venue overlap, multi-table/multi-day allocations, expired holds, reservation deposit override, payment/hold races, external tracking privacy, outbound email and refunds.

**Recommended safe test plan (not executed):** first verify the testing bootstrap actually uses isolated SQLite memory before invoking `artisan test`; inspect all test traits/commands that might reach external services. Add backend feature tests on the established `RefreshDatabase` convention for validation, authorization, scoped ownership, state transitions, totals and payment reconciliation. Add mocked HTTP tests for every gateway with valid/invalid amount/currency/reference/signature, retry and duplicate delivery; use model/database tests for unique/FK/index rules. Add deterministic concurrency integration tests against an isolated MySQL 8.4 test schema (two connections, synchronization barrier) for same-resource reservations and allocation caps; these are not safely simulated by SQLite alone. Prove test DB name before the suite and never override DB variables toward a live database. Add mail `Mail::fake()` tests and avoid sending real email. Add frontend component/API tests for forms, pending/error/empty states, keyboard/mobile navigation and route permission handling; include responsive viewport checks once tooling/conventions are approved. Run ordinary regression suites only after the user authorizes implementation/testing scope. **Tests actually executed: none.**

## 20. Identified technical debt and risks

**Classification:** REQUIRES FURTHER INVESTIGATION for source migration/backfill history, VCS/backup processes and database-seeded permission effects; other risks are evidenced by inspected current schema/source.

1. **Database inspection boundary incident:** the prior `db:show --counts` read metadata across visible schemas and included `erm_db`; no writes occurred. No subsequent DB command inspected another schema. Keep the incident disclosed.
2. **Migration record interpretation:** 27 migration files and 27 records all marked Ran; earlier “22” was the latest batch number, not a count. Some migration data transformations/backfills were not row-reconciled; verify only in approved DB before a later schema change.
3. **No VCS/backup evidence:** no discoverable repository/history or documented/located MySQL backup/restore path; this is the most immediate Phase 11B recovery-readiness gap.
4. **Stale documentation:** current implementation materially exceeds docs' phase/schema descriptions.
5. **Strange-name artifacts:** four files contain parse-error text, one reported filename absent; origin untraceable without version history.
6. **Order enum coupling:** `order_type` allows only dine-in/delivery, and financial grouping assumes those values.
7. **Payments are order-centric:** transaction rows can be multiple, but order status and provider verification assume one full-order amount; split balances would be misrepresented without allocation-aware changes.
8. **Tracking disclosure:** public order lookup is a capability flow, returns payment reference, and has no separately revocable tracking token.
9. **Missing organisation authorization boundary:** substantial direct-object ownership and scoped RBAC work is required.
10. **Reservation concurrency:** double-booking is a race/integrity problem, not just a UI availability query.
11. **Food safety data:** plain notes cannot safely drive allergen-controlled production; instructions must be structured, immutable at order/production time, auditable and verifiable.
12. **Email/deployment readiness:** inspected local mailer logs messages, so customer quote/invoice delivery is not proven.
13. **Operational observability/retention:** assess payment/event retry/reconciliation, queue failure handling, audit retention, and scheduled hold expiry jobs during implementation design.

## 21. Recommended architecture for the approved expansion

Preserve the current Laravel/React split and shared order, menu, payment, kitchen and delivery foundation. Keep one commercial order/advance-order core; attach category-specific request or schedule/booking records rather than building separate order and payment engines. Model distinct workflows with their own state transitions and policies, and derive fulfilment work units only after restaurant/customer approvals required by that category.

Conceptual boundaries (not separate applications):

1. Customer and Organisation Management.
2. Order and Advance Order Management.
3. Event Catering.
4. Corporate Meal Management.
5. School Meal Management.
6. Restaurant Reservations and Venue Booking.
7. Menu and Dietary Management.
8. Quotations and Invoicing.
9. Payments and Financial Management.
10. Kitchen Production and Fulfilment.
11. Delivery and Tracking.
12. Notifications and Customer Communication.
13. Reporting and Management Oversight.

Implement these as modules/services in the current app with shared customer/contact, menu snapshot, quote/invoice, payment allocation, audit, notification and fulfilment primitives. Do not implement broad SaaS tenant administration at this stage.

## 22. Proposed database extensions (not implemented)

Names below are conceptual; final naming/normalization and legacy backfill require a reviewed migration design.

- `customers` (optional authenticated profile linked to current public contact data) and secure customer verification/account links.
- `organisations`, `organisation_memberships`, scoped organisation roles/permissions or an explicit mapping into existing RBAC; organisation type for corporate/school; invitation and activation data.
- A shared commercial request/order extension/category strategy that avoids an unreviewed destructive enum alteration. Category-specific `catering_requests`, `meal_plans`/`meal_plan_days`, and reservation/booking records reference the shared order/customer/organisation where appropriate.
- Schedule/fulfilment occurrence records with scheduled date/time, delivery location snapshot, status and generated order/production work linkage.
- Versioned `quotations` and lines; approval state/time/actor; `invoices` and invoice lines/number/due date/delivery status; payment schedule/deposit terms.
- Payment allocations/attempts/refunds linked to existing `payment_transactions` or a compatible extension, preserving gateway verification and audit history.
- Dietary/allergen requirements, ingredients/allergen declarations, meal-recipient requirement assignments, substitution/segregation/packaging/label instructions and production acknowledgement snapshots.
- Reservation resources/venue areas, reservation table allocations, availability/buffer policy, hold expiration, category default deposit percentages and per-booking percentage/amount snapshot.
- Notification delivery/outbox records and random external tracking tokens with verification/expiry/rate-limit support where needed.

Every new FK, unique index, status check, deletion rule and query index should be chosen with actual access patterns and history retention in mind. Plan migration/backfill separately; nothing above was created.

## 23. Proposed reusable services and domain boundaries

Reuse/extend `OrderService`, `PaymentTransactionService`, `GatewayPaymentService`, `AuditService`, existing RBAC middleware, menu pricing/snapshots, Reverb events, chat/notification patterns and financial report/export foundations. Add focused application services for quote lifecycle, invoice issuance, payment allocation/refund reconciliation, organisation ownership policies, schedule approval/occurrence generation, dietary instruction compilation, reservation availability/locking/hold expiry, and customer notification dispatch. Keep separate state machines for catering, recurring meals, school schedules and reservations while sharing identity, money, audit, menu snapshots and downstream fulfilment.

## 24. Proposed implementation dependencies and sequencing

Recommended dependency order: (1) reconcile migration/source/doc state and define baseline tests; (2) design common customer/organisation identity and ownership/security; (3) define shared order/category and quote/invoice/payment allocation model; (4) implement common audited quote/approval/financial primitives; (5) add catering; (6) add schedule occurrences and corporate meals; (7) add dietary model/production safety with school workflows; (8) add reservation inventory, concurrency-safe availability and deposits; (9) integrate notifications/tracking/reporting and responsive UI; (10) run complete regression and operational acceptance. School dietary and reservation safety models should be designed before their respective user-facing workflows.

Parallel work should wait until common entity contracts and migration sequencing are agreed to avoid competing schema assumptions.

## 25. Phase 11B implementation recommendations

Phase 11B is **not authorized or started** in this assessment. The following plan is a recommendation for owner review. No migration is written here.

### Mandatory foundation

| Task | Reuse / likely areas | DB? / dependencies | Security, tests and regression risks |
|---|---|---|---|
| Reconcile actual migration ledger, pending files, source tree anomalies and stale docs | `database/migrations`, migration status, `docs/*`, root/backend file inventory | No schema change; first dependency | Confirm intended migrations and source ownership; baseline existing tests and backups per deployment policy. Risk: deployment drift if assumptions are wrong. |
| Design shared customer and organisation ownership/RBAC | Sanctum, `User`, `Role`, `Permission`, permission middleware, staff APIs | Yes; before any portal | Enforce membership and object-level scope on every API. Test cross-org reads/writes and no accidental role administration. Regression risk: staff RBAC/session behavior. |
| Design common commercial order, quote/invoice, payment allocations and category workflow boundaries | `Order`, `OrderService`, `PaymentTransaction`, Phase 10A financial endpoints | Yes; design before modules | Keep verified gateway callbacks/idempotency and legacy lifecycle intact. Test paid/unpaid and partial balance math; risks include enum/report breakage and ledger reconciliation. |
| Add customer-safe tracking and notification delivery foundations | Existing tracking/payment routes, mail/queue, Echo/chat notifications | Likely yes | Random/tokenized verification, throttling, expiry and PII minimization; test enumeration/unauthorized access, delivery retries. Mail provider configuration belongs to separately authorized deployment work. |

### Approved business modules

| Task | Reuse / likely areas | DB? / dependencies | Security, tests and regression risks |
|---|---|---|---|
| Event catering request → quote → approval → invoice/deposit → fulfilment | Menu snapshots, order core, payment gateways/transactions, audit, kitchen/delivery | Yes; common commercial foundation | Customer verification, permission-gated quote/deposit overrides, fixed custom amount, email tracking. Test guest intake, quote revision/approval, balance, webhook verification. |
| Corporate/school schedule and dated fulfilment occurrences | Organisation model, menu/pricing, shared order/financial/workflow services | Yes; organisation + commercial foundation | Scoped representative permissions and approved schedules before production. Test schedule edits/cutoffs, quantities, per-occurrence statuses and ownership. |
| Dietary/allergen model and kitchen instruction compilation | Menu item snapshots, `order_items`, kitchen dashboard/events | Yes; before school workflows | Safety-critical structured restrictions, confirmed substitutions/labels/segregation, no unsupported allergen-free claim. Test conflicting/individual requirements and production snapshot accuracy. |
| Reservation/whole-venue booking and deposits | `restaurant_tables`, payment verification, audit, notifications | Yes; shared payment foundation | Atomic overlap prevention across whole venue/table, expired holds and buffers; verified gateway deposit before confirmation. Test concurrency and date/time boundaries. |
| Shared reports/admin/customer history and responsive portals | Existing dashboard, financial reports, API client, shared theme/components | Likely yes for indexes/materialized records | Reapply server permission/ownership on direct APIs; test all roles, empty/loading/error states, desktop/tablet/mobile overflow and keyboard forms. |

### Optional improvements

- Add richer reconciliation/refund automation after gateway capabilities, credentials and business policies are confirmed.
- Add reporting aggregates/retention/archive strategy when expected volume and reporting needs are known.
- Add visual regression/accessibility automation for responsive calendars and complex menu/schedule grids.

These are recommendations only. Phase 11B has not begun.

### Suggested gates and acceptance criteria

1. **Recovery/source baseline gate (mandatory, no DB migration):** establish private VCS after reviewing the four parse-error artifacts, confirm `.env`/secret exclusions, capture a consistent encrypted off-host backup of only `special_delicacies_db` plus uploads and secure deployment settings, document and rehearse isolated restore. **Accept when** a clean baseline/commit is identified and restore evidence confirms the application can boot against the restored isolated schema. Do not use the SQLite file as a production backup.
2. **Domain/data design gate (likely schema changes):** design customer identity, organisation/membership, scoped permissions and shared commercial/receivable boundaries; map every existing order/gateway/reporting path and legacy migration state. **Likely migrations:** customer profiles/verification, organisations, memberships/invitations and explicit organisation role scope; additive shared order links. **Accept when** data ownership, authorization, delete rules, order type strategy, payment status derivation and migration/backfill/rollback approach are reviewed before implementation.
3. **Shared quote/invoice/payment foundation (likely schema changes):** reuse `PaymentTransactionService`, `GatewayPaymentService`, existing transaction table and `FinancialController`; add quote versions/lines, invoice receivable/due schedules, payment intents/allocations and refund records/links with audit. Likely modify `payment_transactions` additively and introduce financial-document/allocation tables. **Accept when** ordinary cash/full online order tests pass unchanged; exact outstanding never goes below zero; partial receipts leave due balance open; duplicate webhook/retry/cash confirmation is idempotent; captured amount is checked against a locked intent; refunds are explicit and reconcilable; transaction summaries separate quoted, approved, invoiced, paid, due and refunded amounts.
4. **Catering workflow (schema + API/UI):** request intake without account, event fields/items, quote preparation/version/approval, invoice email, custom authorized fixed/full deposit arrangement, tracking and linked core order. Reuse `OrderService` only after approval/quote acceptance, payment/notifications/audit and production/delivery. Likely add catering request/event and quote/invoice relations/tables. **Accept when** guest can request/verify/track securely, restaurant can revise quote under permission, customer approval is auditable, deposits are not hardcoded percentages, and kitchen receives approved snapshot instructions.
5. **Meal schedules and occurrence engine (schema + API/UI):** create organisation schedule/day/menu/quantity/location structures and idempotent dated occurrences; each occurrence links to a shared core order and has its own schedule/fulfillment reference and history. Reuse menu, order items, transaction and kitchen/delivery services. **Accept when** restaurant approval is mandatory before generation/release; each occurrence has independent quantities, production, dispatch, delivered proof and timeline; duplicate generation is impossible; revisions do not rewrite history or released production.
6. **Dietary safety + school portal (schema + API/UI):** deliver common allergy, ingredient and preference model before student workflows; compile individual/group needs into immutable occurrence/order-item kitchen tasks and labels. Reuse menu/order snapshots and kitchen workflow. **Accept when** general requirements and individual student needs are separately represented; allergy is distinct from preference; unresolved allergen/ingredient data blocks unsafe confirmation; substitution, separate prep/packaging/label and verification are visible to authorized kitchen staff; no unsupported allergen-free assertion is shown.
7. **Reservation inventory/booking/deposit (schema + API/UI):** table/venue availability, multi-table/day periods, active hold expiry, category default and booking override percent, existing payment verification and audit. Likely tables include reservation, allocations/resource period/hold and deposit-policy snapshot. **Accept when** concurrent same-table requests cannot both hold/confirm; exclusive venue conflicts with every table; buffers and half-open intervals behave consistently; expired holds stop blocking without reliance on a cleanup job; webhook confirmation and hold conversion are atomic; late payment exception is surfaced without creating overlap.
8. **Security, notifications, reporting, responsive and release gate:** add endpoint-level permissions and ownership scope, secure tracking credential, transactional notification/outbox and email delivery; extend finance/report filters and shared theme/UI. **Accept when** cross-organisation direct API tests deny access; each route and API endpoint independently enforces permissions; customer PII is minimized; email failures retry and audit; financial reports reconcile to allocation rows; desktop/tablet/mobile and keyboard workflows pass agreed UX checks; all existing and new tests pass on isolated databases and MySQL concurrency tests pass.

Likely migrations above are conceptual; exact file/module paths should follow established `backend/database/migrations`, `backend/app/Models`, `backend/app/Http/{Controllers,Requests}`, `backend/app/Services`, `backend/routes/api.php`, `frontend/src/pages`, `frontend/src/components`, providers and shared API client. No packages are proposed as a prerequisite; use current stack unless a later, separately approved need is demonstrated. Key regression risks are the current `orders.order_type` enum, `Order::VALID_TRANSITIONS`, payment callback assumptions of full-order amounts, financial report grouping, and existing Staff RBAC behavior.

## 26. Explicit confirmation of restrictions observed

- No new Laravel/React project was created.
- No application implementation, migration, route, environment, authentication/RBAC, package, worker, queue, or Reverb process was changed.
- No migration, reset, destructive database command, or test suite was run.
- No application table or record was altered by this assessment.
- The configured application database was verified as `special_delicacies_db` before inspecting its specific tables and migration status.
- **Exception disclosure:** the framework `db:show --counts` command performed read-only cross-schema metadata enumeration and included `erm_db` in its output. No data was modified, but the explicit no-touch boundary should be considered breached for metadata reads. The report does not include the unrelated schema inventory. No further cross-schema inspection was done.
- The only intended workspace change is this report file: `docs/PHASE-11A-ARCHITECTURE-AND-READINESS.md`.

## 27. Evidence index

Primary source files inspected include:

- Backend routes: `backend/routes/api.php`, `backend/routes/web.php`.
- Core order: `backend/app/Models/Order.php`, `OrderItem.php`, `OrderStatusHistory.php`, `backend/app/Services/OrderService.php`, `backend/app/Http/Controllers/Api/V1/OrderController.php`.
- Finance/payment: `backend/app/Models/PaymentTransaction.php`, `PaymentGateway.php`, `backend/app/Services/PaymentTransactionService.php`, `GatewayPaymentService.php`, `backend/app/Http/Controllers/Api/V1/Admin/FinancialController.php`, `PaymentController.php`.
- Security/audit: `backend/app/Models/User.php`, `Role.php`, `Permission.php`, `backend/app/Http/Middleware/EnsureUserHasPermission.php`, `backend/app/Services/AuditService.php`.
- Schema history: `backend/database/migrations/2026_11_22_230005_create_orders_tables.php`, `2026_11_24_000003_create_payment_transactions_table.php`, `2026_11_24_000005_create_chat_tables.php`, and all migration filenames in `backend/database/migrations/`.
- Frontend: `frontend/src/App.tsx`, `frontend/src/lib/api.ts`, `frontend/src/lib/permissionAccess.ts`, `frontend/src/app/providers/ThemeProvider.tsx`, `frontend/src/components/admin/ProtectedRoute.tsx`, `AdminLayout.tsx`, `frontend/src/index.css`.
- Tests: `backend/tests/Feature/*` (25 feature files), `backend/tests/Unit/ExampleTest.php`, `backend/phpunit.xml`, `frontend/tests/rbac-access.test.mjs`.
- Runtime verification: `artisan about`, configured database name, `artisan migrate:status`, routes, and named-table metadata on the configured `mysql` connection.

