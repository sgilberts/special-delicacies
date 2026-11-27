# Stage 4B — Independent Security Review

**Document type:** Independent security review
**Date:** 2026-11-27
**Scope:** Stage 4B identity verification, account gating, deposit/credit authorisation and financial boundaries
**Branch reviewed:** `stage-4b-remediation` (base: PR #1 head `6d29dd7`)

---

## 1. Method

The implementation was reviewed directly from source, not from prior reports, and exercised
through the automated suites and an authenticated browser. Areas covered:

- Customer identity verification and the both-contact gate.
- OTP generation, hashing, expiry, attempt limits, resend throttling, single use.
- Email and phone account recovery, ambiguous phone handling, session invalidation.
- Authentication and authorization boundaries; customer self-scope and organisation membership.
- Deposit recording, two-person verification and lifecycle.
- Payment allocation, credit designation/application and over-application prevention.
- Webhook signature verification, duplicate handling and provider response verification.
- Existing password-policy differences.

Files read: `AccountSecurityService`, `GhanaPhoneNumber`, `AccountVerificationController`,
`PasswordRecoveryController`, `AuthController`, `CustomerController`,
`EnsureCustomerAccount`, `EnsureCustomerFullyVerified`, `AppServiceProvider` (rate limiter),
`routes/api.php`, `DepositService`, `DepositReceiptService`, `GatewayPaymentService`,
`PaymentAllocationService`, `Admin\DepositController`, `CustomerDepositController`,
`Admin\CustomerCreditController`, `Admin\PaymentAllocationController`, `phpunit.xml` and
`tests/TestCase.php`.

---

## 2. Findings

| ID | Severity | Area | Finding | Status |
| --- | --- | --- | --- | --- |
| SEC-01 | Low | Password policy | Staff self-service profile update allows `new_password` `min:8` (`AuthController::updateProfile`), while customer registration and every recovery flow require `min:10`. A staff member can therefore set an 8-character password outside the stricter policy. Not an auth bypass. | Documented; align to `min:10` (product decision) |
| SEC-02 | Low / Informational | Enumeration | Registration returns field-level uniqueness errors and a 409 "An account with those contact details already exists." An unauthenticated party can test whether an email/phone is registered. Rate-limited (`throttle:5,1`). | Accepted trade-off |
| SEC-03 | Low | Data integrity | Registration uniqueness is on the raw `phone` string; `GhanaPhoneNumber::normalize` collapses formats, so two accounts can share one real number. Phone recovery queries normalized variants and **refuses when the match is not exactly one** (`count() !== 1`), so it fails closed. | Documented; recommend normalized-phone uniqueness |
| SEC-04 | Low | Race | `DepositService::initiateOnline` initializes the provider before persisting `checkout_url`. Two concurrent replays of the same idempotency key can both initialize the same deterministic reference. Credit is only created on verified completion, so no duplicate credit; the provider sees the same reference. | Documented; recommend serializing initiation |

No **Critical** or **High** findings were identified. No confirmed exploitable defect in
identity or account gating was found.

### 2.1 Affected files by finding

- **SEC-01:** `backend/app/Http/Controllers/Api/V1/AuthController.php` (`updateProfile`).
- **SEC-02:** `backend/app/Http/Controllers/Api/V1/CustomerController.php` (`register`).
- **SEC-03:** `backend/app/Http/Controllers/Api/V1/CustomerController.php`
  (`register`, `updateProfile`), `backend/app/Services/GhanaPhoneNumber.php`.
- **SEC-04:** `backend/app/Services/DepositService.php` (`initiateOnline`).

### 2.2 Attack-class checklist

| Attack class | Result |
| --- | --- |
| Both-contact gate bypass | **No** — enforced server-side on the financial route group |
| Privilege escalation | **No** — staff/customer middleware plus granular permissions; verified with 403 responses |
| Information disclosure | **Low** — registration enumeration (SEC-02); recovery responses are generic |
| Account enumeration | **Low** — SEC-02; password recovery does not disclose account existence |
| Replay | **No** — single-use challenges, idempotent deposit completion, operation-reference idempotency |
| Race conditions | **No confirmed** — challenge consumption is row-locked; theoretical deposit-initiation race recorded as SEC-04 |

---

## 3. Controls verified as sound

### 3.1 OTP and challenge lifecycle

- Codes are `random_int(100000, 999999)` and stored as `Hash::make(...)`; the raw code is
  never persisted.
- `expires_at` is 10 minutes; `failed_attempts >= 5` consumes the challenge; a 60-second
  resend cooldown is enforced by a `created_at` window.
- `consumed_at` enforces single use; `recipient_hash` (SHA-256 of the normalized contact)
  binds the challenge to the destination.
- `lockedChallenge()` uses `lockForUpdate()` inside a `DB::transaction(..., 3)` so
  concurrent submissions cannot both consume a challenge.
- `assertChallenge()` checks account binding, purpose, channel, consumption, send time,
  expiry, attempt count and recipient hash, and fails with a generic message.

### 3.2 Challenge isolation and recovery

- A verification challenge cannot verify another account, and cannot authorise a password
  reset (purpose is checked). Asserted by `Stage4bRemediationTest`.
- Password reset hashes the token/code, and on success deletes all API tokens
  (`$account->tokens()->delete()`), invalidating existing sessions.
- Recovery requests return generic responses and refuse ambiguous phone numbers and
  cross-account-type resets.
- Email delivery fails closed: `deliverEmail()` throws when the mailer is `log`, or `array`
  outside testing, so an unconfigured environment cannot silently "verify" accounts.

### 3.3 Authentication and authorization boundaries

- Customer routes run behind `auth:sanctum` + `EnsureCustomerAccount`; staff routes behind
  `auth:sanctum` + `EnsureStaffAccount`; financial routes behind granular `permission:`
  middleware.
- `EnsureCustomerFullyVerified` requires **both** `email_verified_at` and `phone_verified_at`
  and reads the account on every request, so completing the second verification unlocks
  features immediately. It is applied server-side to quotations, invoices, credit and
  deposits, so it cannot be bypassed from the UI.
- Customer financial data is scoped by `customer_id`, or by organisation membership with an
  explicit `financials.view` permission (`financialsOrganisations`).
- Verified live: waiter `403` on balances, allocations and deposit creation; customer `403`
  on staff endpoints; customer own credit `200` with the correct balance.

### 3.4 Deposit lifecycle and two-person rule

- `DepositReceiptService::complete()` accepts only `pending → succeeded`, requires an
  invoice-purpose, order-less receipt, writes the receipt intent exactly once, and is
  idempotent on replay.
- `fail()` refuses to overwrite a `succeeded` transaction.
- `DepositService::decideStaffDeposit()` refuses self-verification
  (`recorded_by === actor->id`) and locks the record and transaction.
- Verified live in the browser: the recorder's self-verification was refused with
  "A deposit must be verified by a different staff member from the one who recorded it.",
  and an independent verifier approved the same deposit.

### 3.5 Allocation, credit and over-application

- `PaymentAllocationService::assertInvoiceReceipt()` requires invoice purpose, no order,
  `succeeded`, `verified_at` and `received_at`, and exactly one payer.
- `allocate()` locks the payment and the invoices, enforces the receipt's unallocated
  amount and each invoice's outstanding balance, and is idempotent by `operation_reference`.
- `assertEligibleInvoice()` enforces invoice type, issued status, matching currency and the
  same payer (customer or organisation).
- Credit is derived (designations − applications) and never stored; the ledger model blocks
  update and delete (append-only).
- Verified live: payer mismatch `409`, credit over-application `409`, payment
  over-application rejected in the UI, and a fully designated receipt excluded from eligible
  payments (no double counting).

### 3.6 Webhooks and provider verification

- Paystack: legacy HMAC-SHA512 over the payload with the secret key, or a timestamped header
  verified with `webhook_secret` and a 300-second tolerance; fails closed when the secret is
  missing.
- Korapay: HMAC-SHA256 over the canonical data object, compared with `hash_equals`.
- Hubtel: the callback is reconciled against the authenticated transaction-status API rather
  than trusting the body.
- Deposit completion requires an exact reference, `GHS` currency and exact amount match;
  completion is idempotent, so duplicate webhooks cannot double-credit.

---

## 4. Conclusion

The Stage 4B identity, gating and financial implementation is sound. No Critical or High
severity defect was found, and no application code change was required by this review. Four
low-severity hardening items are recorded for follow-up. The merge-blocking database-safety
defect is separate and is remediated in
`STAGE-4B-DATABASE-GUARD-REMEDIATION.md`.
