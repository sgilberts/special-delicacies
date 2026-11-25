# Phase 10A implementation map

This map records the existing implementation inspected before Phase 10A changes and the paths added for financial reporting.

## Existing application

| Area | Implementation |
| --- | --- |
| API and authentication | `backend/routes/api.php`; `AuthController`; Sanctum bearer tokens; `EnsureUserHasPermission`; `User::hasPermission()` |
| Orders and payment state | `orders`, `order_items`, `order_status_histories`; `Order` model; `OrderService`; order create, status and payment confirmation endpoints |
| Gateway accounts | `payment_gateways`; `PaymentGateway` model; `AdminPaymentGatewayController`; secret key is encrypted and omitted from safe gateway responses |
| Paystack, Hubtel, Kora | `GatewayPaymentService`; Paystack and Kora signature verification; Hubtel callback reconciles through authenticated transaction-status API; amount/currency checks happen before paid status |
| Payment callbacks | `/api/v1/payments/verify/{reference}`, `/paystack/webhook`, and `/{provider}/webhook`; payment references are unique on orders |
| Cash confirmation | `OrderService::confirmPayment`; admin order and delivery confirmation endpoints; order status history and admin audit log |
| RBAC | `roles`, `permissions`, `role_user`, `permission_role`; `RoleAndPermissionSeeder`; role management controller and page |
| User profiles | `AuthController::updateProfile`; profile photo stored on public disk; supports name, email, phone and authenticated password change |
| Branding | `AdminWebsiteContentController`; restaurant settings store logo/favicon paths; frontend applies dynamic favicon in `assetUrl.ts` and settings page |
| Audit | `AdminAuditLog`, `AuditService`, admin audit endpoint/page; sensitive key filtering |
| Reverb | `backend/routes/channels.php`; `BaseOrderEvent`; frontend `lib/echo.ts`; private operational channels |
| Admin UI | React Router `App.tsx`; `AdminLayout`; `ProtectedRoute`; permission-based sidebar construction; existing theme CSS variables |

## Inspection findings

- Paystack, Hubtel and Kora (`korapay` provider slug) were present and are retained. Hubtel uses API credentials and a collection account number. No other provider integration was found.
- The live role seeder contained Super Admin, Waiter/Waitress, Kitchen Staff and Delivery Staff. It did **not** contain the separate Admin role described by the Phase 10A brief. The Super Admin role is intrinsic full authority in `User::hasPermission()` and is protected in role management.
- Payment state existed only on `orders` (`pending` / `paid`) and had no durable transaction ledger or payment timestamp. Gateway callbacks were verified, but duplicate notifications were prevented only indirectly by the order's paid status.
- The Phase 9 dashboard exposed sales and cash-awaiting totals to users authorized for operational dashboard access. Financial summary data is now hidden unless the user has `financials.view`.
- Existing administrative order data was returned to any `orders.view` user with amounts and payment summaries. Amounts and references are now redacted unless `financials.transactions.view` is assigned. Operational payment method/status remain available.
- No restaurant timezone setting existed. Phase 10A seeds `Africa/Accra`, the application default, and the financial snapshot reports that timezone.

## Phase 10A paths

| Capability | Files and endpoint |
| --- | --- |
| Ledger and historical backfill | `database/migrations/2026_11_24_000003_create_payment_transactions_table.php`; `PaymentTransaction` |
| Permissions and Admin role | `database/migrations/2026_11_24_000004_add_financial_permissions_and_admin_role.php`; `RoleAndPermissionSeeder`; `AdminRolePermissionController` |
| Payment record updates | `PaymentTransactionService`; integrated into `GatewayPaymentService` and `OrderService` |
| Reporting and transaction register | `FinancialController`; `/api/v1/admin/financials/snapshot`, `/transactions`, `/transactions/{id}`, `/reconciliation`, `/reports`, `/export.csv` |
| Realtime updates | `FinancialPaymentUpdated`; permission-checked private `financials` channel; financial page updates its in-memory daily aggregate |
| Frontend | `/admin/financials`; `AdminFinancialsPage`; permission-aware sidebar link; date ranges are calculated locally against loaded daily aggregates |
| Privacy | `AdminDashboardController` financial response gating and `AdminOrderController` field redaction |

## Data and calculation notes

`payment_transactions` stores one payment record per order payment reference, with a unique transaction reference, amount, currency, provider, payment method, lifecycle status, verification/receipt timestamps and cash confirmer. New cash orders receive a pending ledger row at creation; gateway attempts receive one before provider initialization. Failed initialization, provider failure/cancellation, order cancellation, verified gateway receipts and explicit cash confirmation update the transaction status idempotently. The migration backfills the order-level records without resetting or modifying the application database. A reporting date is indexed so dashboard requests group at daily granularity instead of sending historical orders to the browser.

Collected revenue includes only `succeeded` transactions. Pending, failed, cancelled and refunded states are never added to collected totals. The existing application has no refund workflow, so this phase reports the refunded state only if a future supported path records it; it does not implement refunds. Reports remain in GHS. Date controls recalculate from the one loaded daily snapshot; refresh is explicit. The register loads a bounded latest-100 transaction window, with an API pagination endpoint for scalable follow-up work.

## Limitations requiring future work

- Provider settlement data is not available through the existing payment integration, so this module reports verified customer payments and does not claim bank settlement.
- Existing order state cannot reconstruct unsuccessful attempts or the original exact receipt time for historical paid orders. Backfilled successful transactions use the order's last update timestamp as the best available legacy timestamp.
- Transaction lifecycle values currently produced by the application are pending, succeeded, failed and cancelled. Refund status is not generated by the current application.
