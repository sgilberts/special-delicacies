# Stage 4B — Deposits and Customer Credit: Acceptance Report

**Status:** Completed
**Branch:** `audit-deposits-credit`
**Report date:** 2026-11-27

## 1. Scope delivered

Stage 4B establishes the deposit and customer-credit foundation on top of the existing
financial ledger (`payment_transactions`) and the existing payment lifecycle
(`pending`, `succeeded`, `failed`, `cancelled`). Two deposit workflows are supported:

- **Customer-initiated online deposits** — the customer chooses a deposit or advance-payment
  intent, starts a gateway charge, and the deposit is confirmed by the provider callback or by
  an explicit verify call.
  Routes (`/api/v1/customer`): `GET /deposits`, `GET /deposits/options`, `POST /deposits`,
  `GET /deposits/{reference}`, `POST /deposits/{reference}/verify`.
- **Staff-recorded deposits** — staff record a deposit received outside the online gateway
  (cash, bank transfer, mobile money) with an external receipt reference and supporting
  evidence, then an independent staff member verifies or rejects it.
  Routes (`/api/v1/admin/deposits`): index, `payers`, store, show, `verify`, `reject`.

Customer credit management (`/api/v1/admin/customer-credit`): receipt classification,
invoice listing, balances, ledger, credit designations and credit applications.
Customers read their own credit through `GET /api/v1/customer/credit`.

Identity security delivered with the stage: email and phone verification challenges for
customers and staff, and password recovery over email or phone for both account types.

## 2. Data model

| Table | Purpose |
|---|---|
| `payment_transactions` | Source-of-truth receipt ledger; deposits and advance payments are recorded here with their existing lifecycle states. |
| `payment_receipt_intents` | How a received payment is classified (deposit vs. advance) and which payer it belongs to. |
| `customer_credit_ledger_entries` | Credit designations and applications per payer and currency. Entries are append-only. |
| `staff_deposit_records` | Staff-recorded deposit evidence, external receipt reference, recording and verification actors. |
| `account_security_challenges` | Channel/purpose-bound verification and recovery challenges (hashed secret, expiry, attempt count, cooldown). |
| `account_security_events` | Append-only security audit of challenge and recovery activity. |

Migrations: `2026_11_26_000003_create_stage4b_credit_and_deposit_tables.php`,
`2026_11_26_000004_add_customer_verification_and_security_challenges.php`,
`2026_11_27_000001_create_staff_deposit_records_table.php`.

## 3. Financial and security invariants

- A customer's credit balance is derived, never stored: **designations − applications**, per
  payer and per currency (`CustomerCreditService`).
- Customer credit ledger entries are immutable — the model rejects updates and deletes
  (`CustomerCreditLedgerEntry` model events).
- A staff-recorded deposit cannot reuse an external payment reference; a duplicate is rejected
  with a `409` domain error (`DepositService`).
- Customer deposit requests are idempotent per request key; replaying a key returns the existing
  deposit, and reusing it for a different amount is rejected.
- Verification challenges are bound to one account, one channel and one purpose; a code issued
  for one purpose cannot be redeemed for another.
- All deposit and credit endpoints are permission-gated (`financials.deposits.*`,
  `financials.customer_credit.*`) and rate-limited.

## 4. User interface

- `/account/deposits` — customer deposit page and deposit history (`CustomerDepositsPage`,
  `CustomerDepositPanel`), including loading and empty states.
- `/admin/customer-credit` — staff credit and deposit workspace (`AdminCustomerCreditPage`,
  `StaffDepositPanel`) for recording, verifying and rejecting deposits and managing credit.

## 5. Verification performed

Run inside the development stack (`docker compose`), on 2026-11-27:

- **Full backend suite:** `php artisan test` → **416 passed (2,098 assertions), 0 failures**.
- **Stage 4B suites:** `php artisan test --filter="Stage4b"` → **51 passed (359 assertions)**,
  covering `Stage4bCustomerOnlineDepositTest`, `Stage4bStaffRecordedDepositTest`,
  `Stage4bRemediationTest` and `Stage4bCreditAndSecurityTest`.
- **Frontend types:** `npx tsc -b` → 0 errors.
- **Frontend lint:** `npm run lint` → 0 errors (55 pre-existing warnings).
- **Preview:** the public home and menu routes render without console errors or failed socket
  attempts; `/account/deposits` redirects to customer sign-in when signed out, as designed.
  The signed-in deposit and credit screens were not exercised in this verification pass.

## 6. Test environment note

`phpunit.xml` now pins the test environment with forced `$_SERVER` values
(`APP_ENV`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `MAIL_MAILER=array`,
`CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, `BROADCAST_CONNECTION`, `FRONTEND_URL`,
`CORS_ALLOWED_ORIGINS`). Laravel reads `$_SERVER` before `getenv()`, so without this a shell or
container environment (for example a CI job or the development stack that exports
`DB_CONNECTION=mysql`) would override the suite's settings. That previously pointed the suite at
the development MySQL database, where `RefreshDatabase` truncated it, and made the
mail-dependent security tests fail because `MAIL_MAILER=log` refuses to deliver. The suite now
always runs against an in-memory database and the array mailer, and leaves development data
untouched.

## 7. Deferred

- **Credit reversals and refunds** are out of scope for Stage 4B and are deferred to Stage 4C.
  The ledger is append-only in preparation for compensating entries.
- **Real email and SMS transports** are not configured. Email delivery refuses to run with the
  `log` mailer, and SMS falls back to `UnavailableSmsOtpDelivery`; both surface as an
  "unavailable" response rather than a silent success. Production requires an SMTP provider and
  an SMS provider implementation.
- Realtime broadcasting is optional for this stage. Deployments without a broadcast server set
  `VITE_BROADCAST_DRIVER=none`, which disables the realtime client and lets the polling
  fallbacks keep pages current.
