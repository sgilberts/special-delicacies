# SPECIAL DELICACIES RESTAURANT — PHASE 11A ARCHITECTURE AUDIT: FINAL CONTINUATION REPORT

**Assessment date:** 25 November 2026 (Africa/Accra)  
**Mode:** Inspection and reporting only; Phase 11B has not started.  
**Project:** `C:\ServBay\www\projects\special-delicacies`

## 1. Executive Summary

The current application is a working restaurant website and ordering/operations system with a React SPA, Laravel API, staff Sanctum authentication, database-driven staff RBAC, menu and pricing, QR dine-in orders, delivery orders, kitchen and delivery workflows, online gateway verification, payment transaction reporting, chat, audit logging and Reverb events. These existing components are the right foundation for the approved expansion. There is no evidence of a second/customer authentication platform or organisation portal, but no organisation-account domain exists today.

The approved expansion workflows are not implemented: event catering request and quote approval, invoices, corporate/school meal schedules, per-day delivery occurrences, student dietary/allergy data and kitchen safety instructions, reservations/venue booking and reservation holds are not present in the routes, models or migrations inspected. The ordinary order flow is useful for the eventual confirmed fulfilment unit, but it creates an order immediately and accepts only `dine_in` and `delivery` types. Its pricing/payment path assumes one payment for the full order amount.

**Verified high-priority risks before implementation:** no discoverable Git repository/remote/commit history or backup/restore procedure; the existing `frontend/.env` is not excluded by its local `.gitignore`; payment/order state needs an allocation-aware extension before deposits or split payments; organisation ownership checks do not exist; dietary notes are unstructured; reservation availability needs a transaction-level concurrency design. Seven existing public-storage assets were found, but no backup evidence was found.

One guarded, read-only query checked payment transaction aggregates after asserting the active Laravel `mysql` connection resolves to `special_delicacies_db`. The table contained 11 transactions at the time of inspection: four pending cash, two pending gateway, four succeeded cash and one succeeded gateway; all were GHS. No customer data, amounts, references, or other schemas were queried. No tests were run.

**Completion judgement:** the application is understood well enough to prepare a reviewed Phase 11B architecture/data plan, but not ready for schema implementation until project recovery/source control, migration baseline, organisation ownership/security, shared invoice/payment allocation and domain boundaries are reviewed. This report is not implementation authorization.

## 2. Scope of Inspection

Inspected source and documentation across `backend/app`, `backend/routes`, `backend/database/migrations`, `backend/tests`, `frontend/src`, `frontend/tests`, configuration and storage ignore files. Existing prior Phase 11A findings were used where applicable, including the read-only migration reconciliation: 27 files and 27 migration records marked Ran; “22” was the batch number, not the count.

The guarded aggregate query used the configured `mysql` connection and refused to continue unless its active schema equalled `special_delicacies_db`; it grouped only `payment_transactions` by status, payment method and currency. No migration, reset, table write, package command, test, queue manipulation or process restart was performed. No current-turn command inspected another schema. The earlier report disclosed that a prior `db:show --counts` read metadata across visible schemas, including `erm_db`; that command was not repeated.

**Verification key:** **VERIFIED** = directly confirmed in source or safe metadata; **PARTIALLY VERIFIED** = evidence exists but does not establish the complete behaviour; **NOT FOUND** = not present in the inspected areas; **UNKNOWN** = cannot be established safely from available evidence; **RECOMMENDATION** = proposed design, not current functionality.

## 3. RBAC, Authentication and Organisation Readiness

**Verification status:** **VERIFIED** for staff authentication/RBAC and staff activation controls; **NOT FOUND** for customer and organisation identity, membership, role scope and organisation-level data isolation.

| Existing component | Evidence and current operation | Expansion reuse / gap |
|---|---|---|
| Staff identity/auth | `backend/app/Models/User.php`; `AuthController::login`, `me`, `logout`; Sanctum personal access tokens | Reuse staff identity/token infrastructure. `users` is staff-oriented; no customer/organisation profile or account lifecycle exists. |
| Global roles and permissions | `Role`, `Permission`, `role_user`, `permission_role`; `User::hasPermission/hasAnyPermission`; role/permission endpoints in `routes/api.php` | Reuse permission vocabulary and service boundaries, but current assignments are global to a user and not scoped to an organisation. |
| Endpoint authorization | `EnsureUserHasPermission`, `EnsureUserHasRole`; protected `/api/v1` routes apply `auth:sanctum` and route permission middleware | Enforce each new API operation at backend and query by authorized organisation membership. Existing staff permissions alone cannot prove record ownership. |
| Staff administration | `AdminStaffController` creates/updates staff, assigns a role, resets passwords and toggles `is_active`; routes use `staff.manage` | Reuse staff account operations for restaurant staff only. It is not a representative invitation or organisation membership flow. |
| Revocation | Deactivation sets inactive and deletes the target user’s tokens; password reset also deletes tokens; logout deletes the current token. Permission middleware rejects inactive users. | Reuse. Role changes update database assignments but do not explicitly delete tokens; current backend permission checks load DB roles. Assess frontend stale state/session refresh and whether role-change revocation is desired. |
| Audit trail | `AuditService`, `AdminAuditLog`, `AdminAuditLogController`; staff/role/gateway changes and order transitions record audit/history | Reuse actor/action/before-after logging patterns. This is not yet an organisation audit model for representative, schedule or invoice actions. |
| Frontend access | `frontend/src/app/providers/AuthContext.tsx`, `ProtectedRoute.tsx`, `AdminLayout.tsx`, `permissionAccess.ts` | Navigation is hidden based on permission lists, and some routes check permissions; it is not an authorization boundary. Backend must continue enforcing direct API requests. |

`User::hasPermission()` grants `super-admin` intrinsic access, and frontend navigation tests `roles.includes("super-admin")` directly. This is existing behavior, not a new permission grant. `AdminStaffController::assignableRoles`/`store` guard assignment of the super-admin role, and `AdminRolePermissionController` is separate from staff management. The proposed corporate administrator, meal coordinator, finance officer and school equivalents should be **organisation-scoped assignments in the existing authorization architecture**, not new authentication. The current global `roles` and `role_user` model cannot safely express “finance officer for organisation A only.”

**Risks:** a guessed/modified ID in a direct API request could cross organisation boundaries if future controllers load records without a membership-scoped query/policy. Global role assignment is not an ownership check. No `organisations`, representative membership, organisation permission scope or ownership FK was identified. Do not grant `roles.manage` through organisation staff management. Tests must cover cross-organisation read, update, export, nested occurrence, invoice and attachment access, including direct API calls and revoked membership.

**Recommendation:** retain Sanctum and current staff RBAC. Add organisation and membership records with organisation-scoped membership roles/permissions and explicit policies/scoped query services. Check membership and permission at each backend operation; bind every schedule, occurrence, quote and invoice to its organisation and reject cross-boundary references. Keep role/permission administration separately guarded. Use invitation/activation and revocation flows appropriate to customer representatives rather than reusing staff creation blindly.

## 4. Event Catering and Bulk Order Readiness

**Verification status:** **PARTIALLY VERIFIED** for reusable guest order/menu/item/payment/operations primitives; **NOT FOUND** for catering request, quotation, approval, invoice and catering-specific deposit workflows.

`backend/app/Http/Requests/CreateOrderRequest.php` accepts customer name and phone without a user account; email is required for gateway payment only. `OrderService::createOrder()` resolves menu rows on the server, rejects unavailable items, derives price from `menu_items`, accepts quantity per item, snapshots item name/price/line total and persists special instructions. Menu availability and pricing are reusable inputs. `order_items.quantity` is unsigned integer and current create validation allows integer quantity >= 1, so bulk quantities are structurally possible, but no catering caps, tier price, quoted price, approval snapshot or scheduled fulfilment exists.

`Order::order_type` and `CreateOrderRequest`/`OrderService` allow only `dine_in` and `delivery`; the migration declares an enum with those two values. A direct extension without planning the enum, `Order::VALID_TRANSITIONS`, query filters, dashboards and financial categories would risk breaking ordinary orders. `customer_notes` and `order_items.special_instructions` are text, not structured event instructions. Current orders move from pending into staff confirmation/preparation; that is staff review of an ordinary submitted order, not quote preparation/customer approval.

There is no catering request or formal quote/invoice endpoint/model. No outbound mail send was found (`Mail::`, Laravel notification dispatch and `->notify()` have no application call sites in the searched source). `config/mail.php` supports SMTP and other transports, but inspected runtime uses the log mailer. `OrderService::generateOrderNumber()` is a date plus 8 random bytes; `PaymentController::track()` offers limited order progress, but its payment details include the order payment reference. This may inform a new tracking design; do not reuse the entire response for a private catering quote.

**Recommendation:** accept an unauthenticated request with verified contact channel and rate limits; store event name/category/date/time/location, guest count, requested menu/quantity, instructions and a private external tracking credential. Keep request/quote statuses separate from order production states. Staff prepares versioned quote lines and approves a specific version; customer acceptance is timestamped against that version. Only after approval should a linked shared order/fulfilment unit be created from frozen line items/prices. Invoice/deposit terms then reference that approved commercial snapshot. Let authorized staff choose a per-quote fixed catering deposit or full/custom authorized arrangement; record quoted amount, accepted amount, required deposit, deadline, receipts and balance distinctly. Reuse `OrderService`, `PaymentTransactionService`, `GatewayPaymentService`, `AuditService`, shared menu pricing/snapshots, kitchen/delivery transitions and notifications once the request becomes approved work.

## 5. Corporate and School Weekly Meal Readiness

**Verification status:** **NOT FOUND** for organisation accounts, weekly programmes, menus for days, delivery occurrences or recurring customer schedules; **VERIFIED** reusable order/menu/production primitives.

No schedule/date/time fields exist in the ordinary `orders` schema; it has one `created_at`, one status and optional delivery zone/address. `order_items` supports item quantities, and each `Order` has one lifecycle/history. There are no beneficiary/student records, school or company account routes, recurring scheduler, approval state or occurrence history. Existing operations can process one immediate order at a time.

**Recommendation:** make an organisation-owned weekly schedule the parent agreement, with week/date range, requested or restaurant-proposed menu revision, day/meal/location/time lines, proposed quantities/beneficiary count and restaurant review/approval state. After approval, generate dated delivery occurrences idempotently. Each occurrence should be its own downstream fulfilment unit linked to one shared core `Order`; its own `order_items` capture that day’s actual quantities/menu/price snapshot. Store schedule-specific delivery location/time and occurrence reference; use the linked order’s preparation, dispatch and delivery state plus `order_status_histories`, with occurrence history for schedule approval/revision/confirmation milestones. Parent status is an aggregate of its children, not the only production status.

This allows Monday delivered, Wednesday preparing and Friday scheduled to coexist without a parallel order system. Make occurrence generation idempotent with a unique schedule/date/meal/location key. Schedule changes after approval create a reviewed revision and affect only future unreleased occurrences; do not rewrite delivered or kitchen-released snapshots. Quotations/invoices and payments should attach to the shared commercial record and allocate across occurrences/invoice balances, not create one financial system for each organisation type.

## 6. School Dietary and Food Safety Readiness

**Verification status:** **NOT FOUND** for ingredient/allergen catalogue, dietary profiles, beneficiary-level restrictions or production safeguards; **VERIFIED** only for free-text order/item instructions and ordinary kitchen status flow.

`MenuItem` includes name, description, price, availability and image; there is no normalized ingredient or allergen relation. `order_items.special_instructions` and order customer notes are ordinary text. Kitchen screens and `OrderService` status transitions receive orders but do not compile restrictions into separate production tasks, segregation/packaging/label requirements or a verified safety gate. Therefore the existing text field cannot safely support school allergy management.

**Recommendation:** maintain one reusable dietary model for school, corporate, catering and ordinary orders:

- A controlled ingredient/allergen catalogue and versioned menu-item declarations, including `known`, `unknown` or review-required ingredient status.
- Separate requirement kinds: **allergy** (medically significant and safety-critical), **intolerance**, **dietary restriction** (for example vegetarian or religious exclusion) and **preference**. Preference must not be interpreted as allergy clearance.
- Group/class/menu requirements linked to the school/meal occurrence, and individual beneficiary/student requirement profiles with minimized identity data, allergen/restriction, severity or handling note, source and verified date.
- Meal selection/substitution records that map a specific beneficiary requirement to the actual dish and approved substitute.
- At schedule approval/order release, compile the applicable requirements into immutable production instructions on the occurrence/order item: excluded ingredients, approved substitution, separate prep, dedicated/sanitized equipment or other confirmed control, package separation and label identifier.
- Kitchen acknowledgement checkpoints for prep and final pack/label, with staff/time and unresolved warning state. Missing ingredient data, incompatible selections or an unverified cross-contact control must trigger manual review and prevent a safety claim.

Do not claim “allergen-free” unless the restaurant has documented and verified the relevant cross-contact controls. Kitchen staff need actionable warnings and meal identifiers, not unnecessary student medical details. Preserve a history of changes and who verified the safety instruction. Use the same compiler and production snapshot for catering and other applicable order categories rather than parallel dietary implementations.

## 7. Reservation and Venue Booking Readiness

**Verification status:** **VERIFIED** for physical `restaurant_tables`, capacity/active management and QR identity; **NOT FOUND** for reservations, availability periods, venue bookings, holds, conflicts, deposit policy, check-in, cancellation or reminder workflow.

`RestaurantTable` and `RestaurantTableController` manage physical table records. QR codes attach to tables for dine-in ordering; they do not represent reservations. No reservation route/model/migration, floor/area availability, booking duration, booking status, reservation history or check-in record was found. The public contact form has reservation-related placeholder text, not a reservation service. No existing DB constraint enforces overlapping intervals.

**Recommended data relationships:** venue/resource configuration; reservation master with customer/event, guest count, start/end/timezone, category, status, quote/invoice references and deposit snapshot; one-to-many table allocation rows for single/multiple table; exclusive-venue block represented as a resource reservation; multi-day bookings as explicit periods/occurrences; hold row/status and expiry; append-only reservation history. Occupancy uses half-open time ranges and includes setup/cleanup buffers. A whole-restaurant block conflicts with every overlapping table assignment. Multiple requested tables are checked and written all-or-nothing.

**MySQL 8.4 conflict prevention:** use InnoDB and a stable venue inventory row to serialize decisions. In a short transaction, table bookings acquire a shared lock on the venue row and exclusive locks on selected table rows in ascending ID order; whole-venue booking acquires an exclusive venue-row lock. All paths then check time overlaps and insert allocations/hold in the same transaction. Table requests can proceed concurrently for different tables; an exclusive venue request waits for in-flight table transactions and blocks later ones until its conflict check commits. Modifications, reschedules, cancellation, expiry and confirmation must follow a consistent lock order. Index resource/table plus start/end/status for bounded overlap checks. A slot-occupancy table with unique `(resource, slot_start)` is an alternative if fixed granularity works for duration and buffer rules.

Do not rely only on an overlap `SELECT ... FOR UPDATE` over an empty result: InnoDB lock scope depends on indexes, range scans and isolation level. See official [locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html), [locks set by statements](https://dev.mysql.com/doc/refman/8.4/en/innodb-locks-set.html), [transaction isolation](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-isolation-levels.html) and [locking functions](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html). `GET_LOCK()` advisory locks are connection-scoped and do not release on transaction commit/rollback, so should not be the primary booking guarantee.

Temporary unpaid holds require random reference, expiry timestamp, status and resource allocations; all availability checks ignore holds only after expiry, regardless of whether an expiry job ran. Gateway calls occur outside DB transactions. After authoritative provider verification, one short transaction locks hold/reservation/resources, rechecks expiry/conflict, stores the payment/allocation and atomically converts hold to confirmed. Late payment after expiry must become a visible exception/refund path, never an overlapping confirmation. Reservation status and payment status stay separate. Deposit defaults are percentage by reservation category; authorized staff may override per booking. Snapshot percent, calculation base, amount, actor/time and reason. Do not apply the reservation percentage to catering’s fixed deposit.

Cancellation/reschedule/refund, reminders and arrival/check-in are also absent. These need explicit policy and audit records before customer-facing launch.

## 8. Production and Delivery Integration

**Verification status:** **VERIFIED** for ordinary order preparation/ready/dispatch/delivery lifecycle; **PARTIALLY VERIFIED** for reuse with advance orders; **NOT FOUND** for dated production board, weekly workload, food-safety tasks or occurrence-based dispatch.

`Order::VALID_TRANSITIONS`, `OrderService::updateStatus()` and `OrderStatusHistory` implement immediate dine-in and delivery flows. `OrderService` locks orders for transitions, checks permissions, assigns waiter/kitchen/delivery staff and writes status history. `/api/v1/delivery/orders/*`, waiter/kitchen/delivery dashboards and `OrderPlaced`/status events provide operational components. This can be extended; no need to replace it based on inspected evidence.

It cannot currently sort by promised production date or weekly occurrence because those fields do not exist. One order’s status does not support several daily deliveries within a programme. Special instructions remain ordinary text and no safety acknowledgment is required before ready/dispatch. Reservation-related food may use the same shared order/production record after booking policy is defined.

**Recommendation:** create a kitchen/fulfilment work unit from each approved catering delivery or daily meal occurrence, linked to the existing `Order`/`OrderItem` snapshots. Add scheduled production/delivery timestamp and location snapshot plus explicit per-occurrence quantities. Keep production, dispatch and delivery confirmation as independently audited transitions. Sort workload by date/time, meal category and quantity; surface compiled dietary warnings/labels as blocking tasks where required. Historical completion must be immutable. Continue reusing existing order transition authorization, events and `order_status_histories` where transition semantics fit.

## 9. Tracking, Email and Notification Readiness

**Verification status:** **VERIFIED** for order-number progress and low-data Reverb tracking, staff chat notifications and internal chat audit; **NOT FOUND** for quotation/invoice email, customer notification workflow, reminders, or organisation-aware external tracking.

`OrderService::generateOrderNumber()` generates a date plus 64 random bits. `PaymentController::track()` returns order type/status, item names/quantities, table/zone, status history and payment details (including the payment reference), but excludes customer name, phone and address. `OrderTrackingUpdated` broadcasts only status on a channel hashed from the order number. Staff channels in `routes/channels.php` are authenticated and check role/permission/assignment.

Chat uses `ChatConversation`, `ChatMessage`, `ChatNotification`, `ChatAuditLog`, `ChatService` and Reverb. `ChatNotification` is an internal staff/customer-chat notification record; it is not a general order/finance notification center. `config/queue.php` supports database jobs; the inspected local runtime uses database queue. Some events implement `ShouldBroadcastNow`, while after-commit broadcasts use `ShouldBroadcast`/`ShouldDispatchAfterCommit`. No general outbound mail or Laravel notification dispatch call was found. Runtime mail is `log`; actual delivery is not established.

**Recommendation:** keep a new tracking credential random, purpose-scoped, revocable and rate-limited; do not expose internal IDs, customer PII, another organisation’s records, gateway secrets or reusable payment references. Add an audited communication/outbox record and retry policy for request acknowledgement, quote/invoice, payment receipt, schedule/occurrence and reservation messages. Customer notification failure must not corrupt order/payment state. Configure real email only in a separately authorized deployment task. Use Reverb for timely status updates, but persist the authoritative event/history so disconnected customers can recover history. Add due-date/reminder schedules only after policy and queue ownership are defined.

## 10. Universal Design System and Mobile Readiness

**Verification status:** **VERIFIED** for shared light/dark/system tokens and current responsive primitives; **PARTIALLY VERIFIED** for accessibility/mobile completeness; no runtime viewport/device QA was performed.

`frontend/src/app/providers/ThemeProvider.tsx` persists Light/Dark/System, listens to OS preference and sets `data-theme`/`data-resolved-theme`. `frontend/src/index.css` defines shared brand/semantic color, spacing and radius tokens and responsive breakpoints. Public and admin components use the same CSS token family. React Router, Tailwind v4 and shared form/toast/dialog components provide reusable UI infrastructure. The package manifest does not include shadcn/ui even though it appears in the requested stack; do not assume its components exist.

`PublicLayout`, `MobileBottomNav` and `PublicHeader` provide public mobile navigation and safe-area padding; `index.html` has responsive viewport metadata. `AdminLayout` provides a collapsible/navigation shell and responsive content. Existing pages use React Hook Form/Zod in places and common loading/empty/error components. `ConfirmDialog` has `role="alertdialog"`, `aria-modal` and an autofocus action, but no visible focus trap/Escape handling/restoration in the inspected component.

**Specific mobile finding:** in `PublicHeader.tsx`, `_mobileMenuOpen` is toggled by the mobile hamburger but is not used to render a menu. The public bottom dock still exposes Home/Menu/Gallery/About/Contact, but the hamburger itself appears non-functional. This is source-verified, not runtime-tested. Mobile dock action height is 60px; some admin navigation items are 42px, below the common 44px target cited in project docs. No calendars or responsive reservation grids exist. Horizontal overflow, virtual keyboard resizing and tablet data-grid behavior were not measured.

**Recommendation:** keep one shared token/component system for all new portals. Complete responsive forms, accessible calendars/dialogs, touch targets, focus handling, safe-area/keyboard layout and mobile-friendly schedule/table views. Carry out explicit desktop/tablet/mobile visual acceptance before release; source inspection cannot prove no overflow or contrast.

## 11. Financial Architecture and Payment Integration

**Verification status:** **VERIFIED** for order checkout, Paystack/Hubtel/Kora verification, payment transactions and reports; **PARTIALLY VERIFIED** for reconciliation; **NOT FOUND** for quotations, invoices, allocations, installment/deposit balances and refunds.

`GatewayPaymentService` initializes each configured provider and stores a payment reference/checkout URL on `orders`. The customer return invokes server-side verification; browser redirect is not sufficient to mark paid. Paystack verifies successful status, matching reference, GHS and amount in minor units; modern webhook uses timestamped HMAC with five-minute window, with a legacy signature path. Hubtel queries its transaction-status API using collection account, client reference, status, amount and currency (the current code accepts missing/empty currency from that status API). Hubtel callback is reconciled against its server API. Kora verifies successful status, reference, GHS and amount; its webhook checks HMAC of the data object. `PaymentGateway` casts `secret_key` and `webhook_secret` as Laravel `encrypted`; `AdminPaymentGatewayController::safeGateway()` returns configured booleans rather than secret values. These are good services to extend.

`PaymentTransactionService` locks order and matching transaction on gateway success, checks order association and exact equality to full order total, records `succeeded`/verified/received timestamps and marks the order `paid`. Failure/cancelled callbacks cannot downgrade a succeeded transaction. Cash confirmation creates/updates a transaction and records confirmer. `payment_transactions.transaction_reference` is unique; `provider_reference` is indexed but not unique; order FK is `RESTRICT`; amount is `DECIMAL(12,2)`, currency `CHAR(3)`; there is no unique constraint on `order_id`, so multiple rows are structurally possible. The current service nevertheless treats one full-order payment as the intended completion and the order stores one payment reference/method with binary pending/paid status.

The guarded, read-only payment-record aggregate found 11 GHS transactions: 4 pending cash, 2 pending gateway, 4 succeeded cash, 1 succeeded gateway. This contains no PII or references. Row-level amounts or cross-table reconciliation were not queried.

`FinancialController` reports daily collected/unpaid/cancelled amounts, cash/online channels, transaction register/details and CSV. Revenue grouping assumes `dine_in`/`delivery`. `/api/v1/admin/financials/reconciliation` maps to the same transaction-list method; a provider settlement reconciliation queue was not found. `refunded` is accepted in report filters/aggregates but no refund processing endpoint/service was found. Phase 10A documentation says refunds are not implemented. No formal quote, accepted quote snapshot, invoice number, due date, payment schedule, payment allocation, deposit calculation or receivable balance exists.

**Recommendation — extend the existing financial architecture, no second ledger:** add versioned quotations and invoice/receivable records linked to the shared order/request. Keep `payment_transactions` the canonical existing gateway/cash receipt history; add payment-intent/expected-amount information and allocation rows linking captured transactions to invoice balances. Under a DB transaction, lock the receivable, recompute due, enforce allocation ≤ due, persist successful transaction and allocation idempotently, then derive invoice/order status. Ordinary existing orders keep the full-total path and `payment_status=paid` compatibility only when fully paid. A catering deposit is a fixed staff-authorized amount or explicit full/custom arrangement snapshot on the approved quote; reservation deposit is its own percentage policy snapshot. Preserve successful payment records; refunds are explicit provider-confirmed reversal records/amounts with audit, not edits that erase receipt history. Test duplicate callbacks, partials, payment retries, cancellation/payment race and all legacy paths.

## 12. Testing and Regression Readiness

**Verification status:** **VERIFIED** for existing test files and test DB settings; **NOT FOUND** for approved expansion tests. **Tests actually executed during this continuation: none.** No builds or test suites were run.

Backend uses PHPUnit/Laravel feature tests. `backend/phpunit.xml` sets SQLite `:memory:`, array cache/session, null broadcasting, sync queue and array mailer. Backend has 25 feature test files and `tests/Unit/ExampleTest.php`. Relevant suites include `RbacTest.php`, `SecurityAndApiTest.php`, `Phase9AdministrationTest.php`, `OrderTest.php`, `DuplicateOrderTest.php`, `Phase6DineInOrderTest.php`, `Phase7OperationsTest.php`, `Phase7ReverbAndCancellationSecurityTest.php`, `Phase8DeliveryTest.php`, `FinancialManagementTest.php`, `PaymentGatewayManagementTest.php`, `TableQrTest.php` and `ChatFeatureTest.php`. Frontend has `frontend/tests/rbac-access.test.mjs`, invoked by package script `test:rbac` using Node's built-in test runner; no broad React component test suite/framework is declared.

Future coverage should add guest catering request/quote approval and revision; organization-role authorization and cross-organization direct API denial; invoice numbering/due/deposit/allocation caps; gateway verification for correct and incorrect amount/currency/reference/signature, duplicate webhook and late callback; weekly schedule approval and idempotent occurrence creation; day-specific quantity and independent preparation/dispatch/delivery history; allergy versus preference, group versus student requirement, unresolved ingredient and safety-verification blocking; table/table-combination/venue concurrency, half-open interval/buffers, multi-day allocation, hold expiry and late payment; cancellation/reschedule/refund; mail fake/retry; external tracking access/expiry; and responsive/accessibility interaction.

**Safe execution recommendation:** use the configured in-memory SQLite suite only after confirming test bootstrap and no test opts into live configuration. SQLite cannot establish MySQL locking correctness; run concurrency tests against a separately named, disposable MySQL 8.4 test schema only after explicit approval and an assertion of exact schema name before each worker connects. Use mocked gateways and `Mail::fake()`. Do not use the development/production schema for tests.

## 13. Project Protection and Recovery Readiness

**Verification status:** **VERIFIED** that no project `.git` directory exists and `git rev-parse` reports no repository; **NOT FOUND** for remote, commit history, SQL backup, archive or restore procedure; **UNKNOWN** whether local/ServBay infrastructure has external backups not documented in this project.

No Git, Mercurial or Subversion metadata was found for the project. Without a repository there is no discoverable remote, commit history or meaningful `git check-ignore` result. `docs/INSTALLATION-GUIDE.md` lists Git as a prerequisite but does not establish an actual remote. No in-project SQL dump/archive or automated backup/restore process was found. `backend/database/database.sqlite` exists but is not a backup of the configured MySQL schema. `backend/.env` and `frontend/.env` exist; their contents were not read. Backend `.gitignore` excludes `.env`, `.env.backup`, `.env.production`, `vendor`, `storage` public symlink, etc. Frontend `.gitignore` ignores `node_modules`, `dist` and `*.local`, but does not explicitly exclude `.env`; the root has no `.gitignore` observed. The backend `storage/app/public/.gitignore` ignores its contents, including uploaded assets.

Seven public uploaded image assets were observed under `backend/storage/app/public` (branding, gallery, menu-items and user-avatars); no backup evidence was located. Gateway credentials are encrypted at rest in `PaymentGateway` model casts. The Laravel `APP_KEY` is required to decrypt them; secure independent key recovery/escrow is **UNKNOWN**. Do not print, copy or commit either environment file.

**Recommended separately authorized protection sequence:**

1. Review repository contents and unusual parse-error artifacts before staging; preserve legitimate code/assets and exclude temporary/generated files.
2. Create a private, access-controlled Git repository; review the initial diff and commit deliberately. Confirm `.env`, frontend environment files, credentials, logs, `vendor`, `node_modules`, `dist` and generated temporary files are excluded, while source/docs/migrations are included. Do not rely on absent root ignore rules.
3. Place secrets in secure deployment storage; preserve/recover `APP_KEY` separately from encrypted DB backups. Ensure gateway credentials never enter Git history or frontend bundles.
4. Create an encrypted, access-controlled, off-host consistent backup of **only `special_delicacies_db`**, plus `storage/app/public` uploads and required deployment config. Set retention and access controls.
5. Document and rehearse restore to an isolated database and filesystem; verify app boot and encrypted gateway values with the preserved key. Record backup time, application revision and schema migration state.
6. Repeat a verified backup before each authorized database deployment. Do not initialize Git, create a backup or change ignores as part of this audit.

## 14. Existing Architecture Components Suitable for Reuse

| Component | Verified location | Reuse in approved expansion |
|---|---|---|
| Staff identity and permission evaluation | `backend/app/Models/User.php`, `Role.php`, `Permission.php`; `EnsureUserHasPermission.php` | Retain Sanctum/RBAC; add organisation-scoped policy/membership ownership. |
| Order and item snapshots | `Order.php`, `OrderItem.php`, `OrderStatusHistory.php`, `OrderService.php`, `CreateOrderRequest.php` | Reuse one fulfilment order per approved catering job or daily meal occurrence. Plan category extension carefully around enum/state maps. |
| Menu and pricing | `MenuItem.php`, `MenuCategory.php`, menu API/controllers | Reuse server-authoritative price and availability; add approved quote price snapshots and ingredient declarations. |
| Payments and finance | `PaymentTransactionService.php`, `GatewayPaymentService.php`, `PaymentGateway.php`, `FinancialController.php` | Extend existing verified transaction history for invoices, allocations, deposits and refund tracking. |
| Kitchen/delivery operations | `OrderService`, `DeliveryOrderController`, admin waiter/kitchen/delivery pages, order events | Reuse for individual occurrence work; add scheduled date and structured production instructions. |
| Audit | `AuditService.php`, `AdminAuditLog`, `OrderStatusHistory` | Reuse actor/action/snapshot approach for quote, membership, deposit override, dietary verification and booking changes. |
| Realtime and customer chat | Laravel Reverb, Echo, `OrderTrackingUpdated`, `ChatService`, chat models | Reuse for status alerts and support; add durable domain notifications and secure tracking scope. |
| Design/auth client | `ThemeProvider`, `index.css`, `api.ts`, `AuthContext`, shared components | Reuse shared semantic tokens, API/auth conventions and responsive shells across customer and organisation pages. |

## 15. Identified Gaps and Technical Risks

1. **Organization boundary missing:** there is no organisation model or row ownership scope; direct API exposure is a critical risk if portals are added without per-request scope policies.
2. **One-payment semantics:** gateway service verifies each transaction against full order total and flips a binary order payment state. Deposits/partial payments cannot safely be added as a UI-only feature.
3. **Order category coupling:** `CreateOrderRequest`, `OrderService`, `Order::VALID_TRANSITIONS`, migration enum and financial filters all assume dine-in/delivery.
4. **No quotes/invoices/refund workflow:** finance reports exist, but they are transaction/order reports. Reconciliation endpoint is a transaction-list alias.
5. **Food-safety insufficiency:** free text does not encode allergy, intolerance, exclusion, preference, separate prep, substitution, labeling or staff verification.
6. **No booking conflict model:** tables/QR do not provide time availability or atomic multi-table/whole-venue reservation.
7. **No dated occurrence:** order has no planned service date; status history belongs to one immediate order.
8. **Notification gap:** no customer quote/invoice email/reminder send path was found, and local mail logs rather than proves delivery.
9. **Tracking privacy:** current public status includes payment reference; new private organisation/student records need scoped and revocable external tokens.
10. **Mobile UX detail:** public hamburger’s state is unused; future complex booking/schedule forms need keyboard/focus/overflow QA.
11. **Recovery gap:** no source-control history or project backup/restore process was evidenced; public storage assets exist and are ignored by nested storage rules.
12. **Migration state:** 27/27 migration records are Ran, but prior data migration effects (permission/role transformations and payment backfill completeness) were not row-reconciled. No pending migration was found in the earlier status check.
13. **Previous database-boundary incident:** the earlier cross-schema metadata command is disclosed in the existing Phase 11A report. No such command/query was repeated in this continuation.

## 16. Dependencies Between Approved Expansion Modules

1. **Recovery and baseline first:** establish source control, backup and restore; reconcile migration/data baseline before designing schema.
2. **Identity/security before portals:** define customer identity, organisation membership, scoped roles and ownership policies before corporate or school records.
3. **Shared money before any quoted workflow:** agree quote/invoice/payment allocation/refund model and preserve gateway verification before catering or reservation deposits.
4. **Shared order/fulfilment boundary:** determine category and occurrence relationship without silently widening the enum or changing ordinary state transitions.
5. **Catering and weekly meals consume commercial primitives:** each requires approved quote/price snapshot and then linked orders/fulfilment.
6. **Dietary safety before school production UI:** build shared restrictions and production instruction compilation before exposing student meal selection.
7. **Reservation concurrency is a separate workflow but shares identity/payment/notification:** lock/availability semantics must be designed before availability UI or deposit collection.
8. **Notification/tracking after domain security:** external messages and tracking must respect approved quote state, organisation ownership, expiry and payment privacy.

## 17. Recommended Phase 11B Implementation Preparation

Phase 11B is the architecture/data foundation phase; the table below is a planning recommendation only. Exact migration names and schema are not designed here.

| Preparation task | Existing components to extend | Potential entities/relationships and likely DB work | Security and acceptance criteria |
|---|---|---|---|
| Source/recovery baseline | Current `backend`, `frontend`, `docs`, existing ignore files | No app schema change; establish reviewed VCS and backup process separately | Secret exclusion verified; encrypted backup of approved DB and uploads restores in isolated environment with correct APP_KEY. |
| Customer + organisation access model | Sanctum, `User`, RBAC middleware, audit | Customer/contact profile; organisation; membership/invitation; membership-scoped role/permission relation; likely additive migrations | All API queries scope to organization membership; direct cross-org reads/writes fail; staff role administration remains distinct. |
| Shared advance-order/category strategy | `orders`, `Order`, `OrderService`, status history | Additive order/category strategy or category-linked business record; avoid unreviewed enum replacement | Existing dine-in/delivery lifecycle and reports pass unchanged; category-specific transitions are validated independently. |
| Quote, invoice and allocation foundation | `payment_transactions`, `PaymentTransactionService`, `GatewayPaymentService`, `FinancialController` | Quote versions/lines; invoice/receivable and due schedule; payment intent/allocation; refund/reversal references; likely new tables and carefully additive transaction fields | Invoice/payment reconciliation, no over-allocation, webhook idempotency and full-payment compatibility; no second ledger. |
| Catering | Menu snapshots, quote/finance/audit, order, kitchen/delivery | Catering request/event linked to contact, quote and approved shared order; likely new request/line tables | Guest request works without account; audited quote acceptance; fixed authorized deposit; secure track; kitchen uses frozen accepted quote. |
| Weekly meal programme | Organisation, menu, quote/order, kitchen/delivery | Meal schedule/revisions/day lines; occurrence unique by schedule/date/meal/location linked to one shared Order; occurrence history; likely new tables | Parent approval required; every date has independent quantities, prep/dispatch/delivery status and history; generation idempotent. |
| Dietary/school | Meal occurrence, menu item and order-item snapshots, kitchen | Ingredient/allergen declarations; group and individual requirements; substitutions and immutable production instruction/verification; likely new tables | Allergy distinct from preference; unresolved safety conflicts block release; private student data is minimized; no unsupported allergen-free claim. |
| Reservations/venue | `restaurant_tables`, gateway payments, audit, notifications | Venue/resource; reservations/periods/table allocations/holds; deposit policy snapshot; history; likely new tables/indexes | Atomic multiple-table/venue conflict prevention, expiry, buffer and multi-day tests; provider verification before confirmation. |
| API/UI/tracking/notifications | `/api/v1`, Form Requests, Axios/AuthContext, theme and responsive shells, Reverb | Notification/outbox and tracking-token tables may be useful; APIs/controllers/forms/routes/pages required | Every route and endpoint has own permission/ownership checks; secure references and no cross-org leakage; responsive/a11y criteria pass. |

**Likely backend integration points:** `backend/database/migrations`, `app/Models`, `app/Http/Controllers/Api/V1`, `app/Http/Requests`, `app/Services`, `routes/api.php`, `routes/channels.php`, existing events and tests. **Likely frontend points:** `frontend/src/App.tsx`, feature pages under `pages`, shared `components`, auth/permission providers, shared API client and theme tokens. Use current stack; no new package is identified as a prerequisite.

**Migration/rollback principles for later authorized work:** prefer additive, backfillable changes; do not alter live schema until backup/restore and migration ledger are reviewed. Plan data backfill explicitly for current order payments, preserve historical snapshots and references, deploy compatible application/schema steps, and avoid destructive rollback after financial/booking data exists. Verify all new unique constraints/indexes against concurrency workloads. Use `special_delicacies_db` only. Migration execution is outside this report.

## 18. Final Phase 11A Completion Assessment

1. **Have all outstanding Phase 11A audit areas been examined?** Yes, by source/config/docs/filesystem inspection and the guarded payment-record aggregate. No tests were run.
2. **Which areas remain unverified?** External gateway credentials/connectivity and production webhooks; actual outbound email delivery; VCS remote/commit history because no repository exists; external/ServBay backups and APP_KEY escrow; full row-level reconciliation of migration backfills/seeded permission effects; runtime visual/mobile behavior; database lock behavior under actual concurrent booking requests. No private transaction amounts, customer rows or unrelated database were inspected.
3. **Critical risks before implementation?** No recovery/source baseline; missing organisation isolation; current order-payment path assumes one full payment; no structured food-safety model; no reservation conflict model; frontend `.env` not explicitly ignored; no customer email workflow.
4. **Is the existing application understood sufficiently to prepare Phase 11B?** Yes for a reviewed architecture/data design. No for safely coding migrations until recovery, ownership, money/allocation, category and concurrency boundaries are approved.
5. **Project-protection actions before implementation?** Review files, establish private Git and secret exclusions, preserve APP_KEY in secure recovery storage, create encrypted off-host backup of only `special_delicacies_db` plus uploads/config, and prove restore in isolation.
6. **Recommended next step?** Owner reviews this report and authorizes a separate project-protection baseline first. Then approve Phase 11B architecture/data design before any implementation or migration.

### Execution confirmation

- No application source, route, test, configuration, package, `.env`, queue, job, worker, Reverb or server process was changed.
- No migration was created or executed; no schema/table/record was modified.
- No tests or builds were executed.
- The only database read in this continuation was a guarded aggregate query against `special_delicacies_db.payment_transactions`, after asserting `DB::connection('mysql')->getDatabaseName()` equals `special_delicacies_db`.
- No command inspected or queried `erm_db` or another schema during this continuation.
- The new artifact is this report: `docs/PHASE-11A-ARCHITECTURE-AUDIT-FINAL-CONTINUATION-REPORT.md`.

