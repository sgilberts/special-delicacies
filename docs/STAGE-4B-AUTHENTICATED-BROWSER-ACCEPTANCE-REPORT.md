# Stage 4B — Authenticated Browser Acceptance, Customer Verification Gating, and PR #1 Review

**Exercise date:** 2026-11-27
**Scope:** Stage 4B — Deposits and Customer Credit Foundation only. Stage 4C (refunds, credit notes, credit reversals) remains deferred and was not implemented, exercised, or reviewed.
**Environment:** Sandbox development stack — Laravel 13 (PHP 8.4.28), React + TypeScript + Tailwind, MySQL 8.4.13, local mail-capture SMTP server. The protected ServBay databases (`special_delicacies_db`) were never touched.

**Outcome classification: CONDITIONALLY READY** — see §13.

---

## A. Environment and repository preservation

| Item | Value |
| --- | --- |
| Branch | `audit-deposits-credit` |
| Head at start of exercise | `4e25bbc26fd7cff77bf136328d9903af34b68339` — "Disable unused WebSocket client and fix test environment configuration" |
| Base branch | `main` at `95d6517` |
| Working tree at start | clean (no modified, staged, or untracked files) |
| Other branches | `main` and `audit-deposits-credit` only. No `website-feature-enhancements` branch exists locally or on the remote. |
| Pull request #1 | OPEN, `MERGEABLE`, base `main`, head `audit-deposits-credit`, 30 files, +2916 / −77, commits `2086be5`, `d475002`, `4e25bbc`. Not merged, not closed, not modified. |
| Public website work | The public site enhancements are already merged into `main` (content, menu, gallery, delivery/payment refinements). PR #1 does not touch public pages except a single-line fix in the customer password-recovery page. Nothing was reset, stashed, discarded, rebased, or force-pushed. |

**Changes made during this exercise** (working tree, uncommitted at the time of writing; the platform pushes them to the same branch at the end of the turn):

| File | Change | Reason |
| --- | --- | --- |
| `backend/app/Http/Controllers/Api/V1/CustomerController.php` | `login` accepts `email` **or** `phone` | Requirement §3: the same customer must be able to sign in with either registered identifier (PR #1 only accepted a phone number). |
| `backend/app/Http/Middleware/EnsureCustomerFullyVerified.php` (new) | 403 `customer_verification_required` gate | Requirement §3: full customer features require **both** contacts verified, enforced by the backend. |
| `backend/routes/api.php` | Applies the gate to `/customer/deposits*`, `/customer/credit`, `/customer/invoices*`, `/customer/quotations*` | Restriction must hold for direct API requests, not just the UI. |
| `backend/tests/TestCase.php` | Fail-fast database safety guard | Requirement §9: refuses to run destructive tests outside the `testing` environment or against `special_delicacies` / `special_delicacies_db`. |
| `backend/tests/Feature/Stage4bCustomerIdentityGatingTest.php` (new) | 7 tests / 108 assertions | Email-or-phone login, credential failures, the four verification combinations, unlock on the existing session, guest refusal. |
| Five existing feature tests | Fixtures now mark both contacts verified where the test exercises a gated endpoint | The gate is real; the fixtures describe verified customers. No assertions were weakened or removed. |
| `frontend/src/pages/customer/CustomerLoginPage.tsx` | Single "Email or telephone number" field | Sends `email` when the value contains `@`, otherwise `phone`. |
| `frontend/src/components/account/CustomerDepositPanel.tsx`, `CustomerCreditPanel.tsx`, `frontend/src/lib/verificationGate.ts` (new) | Explain the 403 gate instead of a generic error | A partially verified customer is not dead-ended; the panel links to the verification controls. |

**Database safety**

- The automated suites run on in-memory SQLite forced by `backend/phpunit.xml` (`<server … force="true">`), so a container/CI environment variable cannot point them at a development database.
- A second, independent guard in `Tests\TestCase::setUpTraits()` now aborts before `RefreshDatabase`/migrations if the connection is not SQLite `:memory:`/`*_test.sqlite` or a MySQL database whose name ends in `_test`. The protected names are explicitly refused.
- MySQL evidence was produced against a **disposable** database `special_delicacies_test` created for this exercise (granted to the sandbox `app` user, migrated by the test run itself). It is not the development database.
- The development database `special_delicacies` was **not** truncated, reset, reseeded, or migrated. Acceptance accounts and their deposit/credit records were created through the application (or as explicit fixtures), then deleted at the end of the exercise: customers, staff users, the disposable finance role, the staff deposit record, its payment transaction, receipt intent and credit ledger entry were all removed; final state is 0 customers, the 4 originally seeded staff users, 0 staff deposit records and 0 credit ledger entries.

---

## B. Customer identity and verification

### B1. Sign-in

| Scenario | Result | Evidence |
| --- | --- | --- |
| Valid **email** login | **Pass** — `/account` rendered for the right customer | Browser: email `acceptance.a@example.test` → "Your account · Customer ID: 60"; screenshot `c1b83156…png` |
| Valid **phone** login | **Pass** — same form, phone identifier accepted | Browser: `+233 20 555 0104` → account page rendered; screenshot `7bf483dd…png`. Backend also exercised directly: `POST /api/v1/customers/login {"email":…}` → `200` with `data.customer.id = 60`. |
| Incorrect password | **Pass** — `422`, stays on the sign-in page, message "Invalid credentials…" | Browser: wrong password attempt on `/account/login` showed the inline error and did not navigate. |
| Missing identifier | **Pass** — `422` with an `email` validation error | Feature test `incorrect_or_missing_credentials_are_rejected_without_leaking_accounts` |
| Unknown email / unknown phone | **Pass** — identical `422` response, no account enumeration | Same test |

Email and phone both resolve to the same customer record: the lookup is by whichever identifier was supplied, and a successful email login returns the same `customer.id` as the phone login for that account.

### B2. Verification-state combinations (backend enforcement)

`EnsureCustomerFullyVerified` reads the account on every request, so the decision is never cached in a session or token.

| Email | Phone | Expected | Observed |
| --- | --- | --- | --- |
| Unverified | Unverified | Restricted | `403` `code: customer_verification_required`, `missing: ["email","phone"]` |
| Verified | Unverified | Restricted | `403`, `missing: ["phone"]` (browser + API) |
| Unverified | Verified | Restricted | `403`, `missing: ["email"]` |
| Verified | Verified | Unlocked | `200` on deposits, options, credit, invoices, quotations |

Gated endpoints (direct API evidence, identical result with a Bearer token, a browser `fetch`, or the UI):

- `GET /api/v1/customer/deposits`, `GET /customer/deposits/options`, `POST /customer/deposits`, `GET /customer/deposits/{reference}`, `POST /customer/deposits/{reference}/verify`
- `GET /api/v1/customer/credit`
- `GET /api/v1/customer/invoices`, `/customer/invoices/{id}`, `/history`
- `GET /api/v1/customer/quotations`, `/customer/quotations/{id}`, `/history`, `accept`, `decline`

Deliberately **not** gated, so the customer can complete verification and manage their own account: `profile` (read/update), `orders` (their own history), `verification/{channel}/request|verify`, `logout`, organisation invitations/organisations.

Browser evidence of the restriction: the deposits page renders a "Verification required" card and no amount field (`3476e4d0…png`), and the account page shows the credit panel notice "Verify both your email address and telephone number…" (`c1b83156…png`).

### B3. Completing verification, and unlocking

1. The customer requested an email code through the account panel. The code was delivered through the **local capture SMTP server** (development-only, inspectable at `http://mailpit:8025` inside the compose network) — never to a real inbox and never displayed in the application.
2. A wrong code was rejected; the correct single-use code marked the email verified (`36413c0f…png`).
3. Immediately after email verification the account was still restricted: `missing: ["phone"]` (`5c3a9c1a…png`). No account recreation was needed.
4. The phone channel reported **"Verification delivery is currently unavailable"** (`a5e8c14f…png`) because no SMS provider is configured in this environment (`UnavailableSmsOtpDelivery`). This is the documented limitation, not a fake success: the application refuses to pretend a code was sent.
5. To reach the fourth combination the phone verification timestamp was set on the disposable test account by an explicitly controlled fixture (not production delivery). The same session then unlocked every gated endpoint (`200` on deposits/options/credit) and the UI rendered the deposit feature (`2c177c93…png`). The customer's id, tokens and history were unchanged.

Automated coverage of the same path through the real service (fake SMS transport + `Mail::fake`) is in `completing_the_second_verification_unlocks_features_on_the_existing_session`.

**Limitation recorded:** real SMS delivery cannot be exercised here; no SMS provider was added (explicitly out of scope).

---

## C. Browser acceptance results

All interactions were driven in a real authenticated browser against the sandbox stack (MySQL, live API). Screenshots are from those sessions.

### C1. Customer deposit creation

- With no payment gateway configured in this environment, `GET /customer/deposits/options` returns `gateways: []` and the UI correctly shows "Online deposits are not available right now" instead of a form (`d46f8544…png`).
- A direct `POST /api/v1/customer/deposits` from the browser with a forged `payment_gateway_id` was refused `422 "Choose an available online payment method."` — the server does not trust the client's gateway, payer, amount or currency.
- Payer identity: the service resolves the payer from the authenticated customer (or an organisation where the customer holds `financials.view`); a client-supplied `status` is ignored (covered by `test_customer_cannot_force_success_by_posting_client_supplied_status`).
- Duplicate submissions, refreshes and interrupted attempts: idempotency-key replay returns the existing deposit instead of a second receipt (`test_duplicate_initiation_requests_do_not_create_duplicate_receipts`), and the gateway return parameter is stripped from the URL after it is consumed.
- **Not exercised end-to-end in the browser:** a real gateway checkout and return, because no provider credentials exist in this environment (see §H).

### C2. Gateway return and verification

Not driveable in the browser without a provider. The architecture was verified through the automated suite, which uses the real service code with a faked provider HTTP layer:

- `test_browser_return_alone_cannot_mark_a_deposit_successful`
- `test_successful_server_verification_completes_and_classifies_the_receipt`
- `test_gateway_amount_currency_and_reference_mismatches_are_rejected`
- `test_invalid_webhook_signature_is_rejected`
- `test_valid_webhook_completes_the_receipt_once_even_when_replayed`
- `test_failed_payments_cannot_become_credit`, `test_cancelled_webhook_cannot_become_credit`

Code review of `GatewayPaymentService::verifyDepositCharge` / `consumeWebhook` confirms the deposit completes only when provider status is paid **and** the reference, currency (`GHS`) and exact minor amount match the transaction created by this application; Paystack signatures, Kora HMAC and Hubtel's authenticated status reconciliation are unchanged for orders.

### C3. Deposit status and history

- Customer B saw the verified deposit: `GHS 150.00 · Deposit`, reference `DEP24A559B4178F8D49A3`, payer, external reference `ACC-BANK-REF-001`, timestamp, badge **SUCCEEDED**, and "Verified and added to your customer credit" (`612062e5…png`).
- Refreshing and revisiting the transaction did not duplicate it (history contains one row; repeated `GET` is read-only — `test_customer_status_retrieval_is_scoped_and_repeated_reads_do_not_mutate_records`).
- Isolation: customer C requesting `GET /api/v1/customer/deposits/DEP24A559B4178F8D49A3` received `404 {"message":"Deposit not found."}`; customer C's own list was `200 []`.

### C4. Staff deposit recording (browser)

- Finance Recorder signed in at `/admin/login`, opened **Deposits & Credit** (`/admin/customer-credit`), searched the payer, selected **Acceptance Customer B**, recorded `GHS 150.00`, bank transfer, external reference `ACC-BANK-REF-001`, evidence text.
- Result: record created **PENDING** with the recorder captured ("Recorded … by Finance Recorder"), payer, amount, currency, method, reference and evidence visible in the verification queue (`d6b67dc1…png`). Recording alone created no receipt intent and no credit.
- Duplicate external reference is refused (automated: `test_duplicate_external_reference_is_rejected`).

### C5. Independent verification (browser, two people)

- Recorder pressed **Verify receipt** on their own pending deposit → `409` "A deposit must be verified by a different staff member from the one who recorded it." displayed inline (`d6b67dc1…png`).
- Finance Verifier (a second, distinct account with the same finance permission) opened the queue, entered a decision reason and verified → the record became **VERIFIED**, the queue message read "Deposit DEP24A559B4178F8D49A3 verified and eligible for credit designation", and the verified-by identity/timestamp is stored on the record (`5bc2a7bb…png`).
- Unauthorised staff cannot approve (automated: `test_unauthorised_verifier_cannot_approve_a_deposit`); a direct API call cannot bypass the rule because it lives in `DepositService::decideStaffDeposit`, not in the UI.

### C6. Responsive and theme results

| Surface | Desktop | Tablet | Mobile |
| --- | --- | --- | --- |
| Customer account + verification + credit | 1265/1265 no overflow (`6c13bfc2…png`) | 753/753 (`1138532a…png`) | 375/375 (`8a09848b…png`) |
| Customer deposits (gate + history + empty state) | ok | ok | 375/375 (`d46f8544…png`) |
| Admin deposits & credit | 1280/1280 | 768/768 (`b328692d…png`) | 375/375 |

`scrollWidth` never exceeded `clientWidth` by more than 2px on any checked surface; no element crossed the right edge. Forms remained single-column and usable on mobile; the credit ledger table scrolls inside its own container rather than widening the page. Loading, empty ("You have no deposits yet", "No deposits match this view", "No ledger entries") and success states rendered. Theme behaviour was measured, not eyeballed: body background `rgb(250,247,242)` in light, `rgb(25,21,18)` in dark, with the System mode restored afterwards (`1246e933…png`).

**Not driven in the browser:** a real online gateway checkout/return screen (no provider), and the super-administrator console (see §H).

---

## D. Financial integrity

| Control | Result | Evidence |
| --- | --- | --- |
| Deposit lifecycle preserved (`pending`, `succeeded`, `failed`, `cancelled`) | **Pass** | No new states added; `DepositReceiptService::fail` only accepts failed/cancelled; automated failure/cancel tests |
| Browser redirect alone cannot complete a deposit | **Pass** | `test_browser_return_alone_cannot_mark_a_deposit_successful` |
| Provider reference / amount / currency / identity checks | **Pass** | `test_gateway_amount_currency_and_reference_mismatches_are_rejected`; code review of `verifyDepositCharge` |
| Duplicate callbacks / repeated returns idempotent | **Pass** | `test_valid_webhook_completes_the_receipt_once_even_when_replayed`, `test_repeated_verification_requests_are_idempotent` |
| Credit not created prematurely | **Pass** | Pending and rejected deposits cannot be credited (`test_pending_staff_deposit_cannot_be_credited`, `test_rejected_deposit_cannot_be_credited`, `test_unverified_receipts_and_unclassified_receipts_cannot_become_credit`) |
| Append-only ledger | **Pass** | Model events refuse update/delete; `test_credit_ledger_entries_are_immutable_through_the_model` |
| Derived balance = designations − applications | **Pass** | `test_credit_designation_and_application_are_immutable_and_feed_invoice_balance` |
| Staff designation (browser) | **Pass** | GHS 100.00 designated; UI reported "Receipt available balance 150.00 → 50.00; credit balance 0.00 → 100.00" (`2ad935c6…png`) |
| Over-designation refused | **Pass** | Browser/API attempt to designate 200.00 against the 50.00 remainder → `409 "Credit designation exceeds the receipt's unallocated balance."` |
| Customer sees own balance/history | **Pass** | Account page shows `GHS 100.00`, entry "Credit designated · …", receipt reference, actor "Finance Verifier" (`4129aa86…png`) |
| Customer cannot see another customer's credit | **Pass** | Customer C: one account (self), no balances; B's deposit reference `404` |
| Stage 4A allocation compatibility | **Pass (automated)** | `PaymentAllocationTest` (allocation, reversal, invoice balances) and `Stage4bCreditAndSecurityTest::test_credit_designation_and_application_are_immutable_and_feed_invoice_balance` (40.00 allocated + 30.00 credit = 70.00 settled, 130.00 outstanding, then the spent receipt cannot be designated again) |
| Same funds allocated/designated twice | **Pass** | The above test refuses the second designation after allocation consumed the receipt balance |

No credit reversal, refund, credit-note or new ledger entry type was implemented.

---

## E. Authorization

| Account | Action | Observed |
| --- | --- | --- |
| Unauthenticated | `GET /api/v1/admin/customer-credit/balances` | `401 {"message":"Unauthenticated."}` |
| Unauthenticated | `GET/POST /api/v1/customer/deposits` | `401` |
| Customer A (own records) | deposits/credit | `200`, scoped to A |
| Customer C (another customer's deposit) | `GET /customer/deposits/{B reference}` | `404` |
| Customer with only email verified | deposits/credit/invoices/quotations | `403` `customer_verification_required`, `missing:["phone"]` |
| Customer with only phone verified | same | `403`, `missing:["email"]` |
| Fully verified customer | same | `200` |
| Ordinary staff (waiter) | `/admin/customer-credit` UI | "403 — Access denied. Your account does not have permission to open this page." (`03a28678…png`) |
| Ordinary staff (waiter) | `GET /admin/deposits`, `GET /admin/customer-credit/balances` | `403 "Forbidden. You do not have permission to perform this action."` |
| Staff with `financials.deposits.view` only | deposit queue | `200` read; write endpoints require `financials.deposits.manage` (route-level, `test_unauthorised_staff_cannot_record_or_read_deposits`, `test_pending_queue_and_status_filters_are_permission_scoped`) |
| Staff with `financials.deposits.manage` | record/verify | `201` / `200` |
| Staff without credit permissions | balances/ledger/designation/application | `403` (route middleware `financials.customer_credit.view|manage`) |
| Staff with credit view | balances/ledger | `200` read-only (frontend hides manage controls) |
| Staff with credit manage | designation/application | `200` |
| Recorder self-verification | `POST /admin/deposits/{id}/verify` | `409` refused |
| Independent verifier | verify pending deposit | `200` |
| Super administrator | — | Follows the existing role model: `super-admin` holds every permission (`RoleAndPermissionSeeder`), so all of the above endpoints are allowed. **Not separately driven in the browser** — the seeded super-admin password is unknown and was not guessed or reset. |

No permission was granted or widened to make a test pass; the disposable `finance-acceptance` role used for the two staff accounts was created only for this exercise and deleted afterwards.

Denied responses carried no record data (`data` absent; only `success`, `message`, `code`, `missing`).

---

## F. Automated tests

Environment used: PHP 8.4.28, PHPUnit 12.5.45, Laravel 13, MySQL **8.4.13** (sandbox `mysql:8.4`), Node 22 / Vite.

| Command (run inside the compose services) | Result |
| --- | --- |
| `php artisan test` (SQLite `:memory:` forced by `phpunit.xml`) | **423 passed, 0 failed, 2206 assertions** |
| `php artisan test --filter=Stage4b` (SQLite) | **58 passed, 0 failed, 467 assertions** |
| `./vendor/bin/phpunit -c /tmp/phpunit.mysql.xml` (MySQL 8.4.13, disposable `special_delicacies_test`) | **423 tests, 2198 assertions, 2 failures** — both in `ChatFeatureTest` (chat queue/assignment), reproduced on MySQL before this exercise and unrelated to Stage 4B |
| `./vendor/bin/phpunit -c /tmp/phpunit.mysql.xml --filter Stage4b` (MySQL 8.4.13) | **OK — 58 tests, 467 assertions** |
| `npx tsc --noEmit` (frontend) | exit 0, no errors |
| `npm run lint` (oxlint) | 0 errors, 55 warnings (pre-existing, none in the changed files' logic) |
| `npm run build` (tsc -b + vite build + postbuild) | success |
| `npm run test:rbac` | 7 passed, 0 failed |

New tests added in this exercise (all passing on SQLite **and** MySQL): `Stage4bCustomerIdentityGatingTest` — email login, phone login, wrong/missing credentials, the three restricted combinations, unlock on the existing session, guest refusal (7 tests, 108 assertions).

**MySQL version note:** the requested MySQL **8.4.11** was not available in this environment; evidence was produced on **8.4.13** (same 8.4 minor line). SQLite results are reported separately and are not presented as MySQL proof.

---

## G. PR #1 review (actual diff, head `4e25bbc`)

1. **PR:** #1 "Implement Stage 4B: Deposits and Credit Acceptance" — source `audit-deposits-credit` → target `main`, state OPEN, `MERGEABLE`, no review decision recorded.
2. **Commits:** base `95d6517` (main), head `4e25bbc`; PR commits `2086be5`, `d475002`, `4e25bbc`.
3. **Changed files (30):** backend — `Admin/DepositController`, `AuthController`, `CustomerController`, `CustomerDepositController`, `CustomerCreditLedgerEntry`, `PaymentTransaction`, `StaffDepositRecord`, `CustomerCreditService`, `DepositReceiptService`, `DepositService`, `GatewayPaymentService`, `GhanaPhoneNumber`, one migration, `phpunit.xml`, `routes/api.php`, three Stage 4B test files; docs — `README.md`, `STAGE-4B-DEPOSITS-AND-CREDIT-ACCEPTANCE-REPORT.md`; frontend — `.gitignore`, `App.tsx`, `CustomerCreditPanel`, `CustomerDepositPanel`, `StaffDepositPanel`, `echo.ts`, `PasswordRecoveryPage`, `AdminCustomerCreditPage`, `CustomerAccountPage`, `CustomerDepositsPage`.
4. **Additions/deletions:** +2916 / −77. The largest files are new (`DepositService` 503 lines, `GatewayPaymentService` +322/−66, `StaffDepositPanel` 269, `CustomerDepositPanel` 211).
5. **Unexpected content:** none. No `.env`, no SQLite/database file, no `dist/`, no build artifact, no secret, no credential, no unrelated public-website redesign. The only environment-adjacent source change is `frontend/.gitignore` ignoring the generated preview wrapper, and `phpunit.xml` hardening the test connection.
6. **Does it contain all authorized Stage 4B work?** It contains the deposit origination (customer online + staff recorded), the receipt completion service, the two-person verification, the ledger immutability hook, the granular finance permissions usage, the customer/admin APIs, the UI panels, the migration and the tests. It does **not** contain the two identity requirements in this prompt: customer email login and the both-contact verification gate. Those were added during this exercise and are in the working tree, i.e. they are not part of head `4e25bbc`.
7. **Public website enhancement work:** preserved; only a one-line recovery-page condition changed outside finance.
8. **Unrelated functionality/architecture:** no. Deposit lifecycle, password-policy differences, Stage 4A allocation service and gateway architecture are untouched.
9. **Migrations:** additive — one new table (`staff_deposit_records`, unique receipt FK, unique external reference, queue indexes) and four columns on `payment_receipt_intents` widened to nullable so customer-initiated receipts can be completed without a staff actor. `down()` narrows them back and would fail if null rows exist; that is a rollback-time concern, not a forward-migration risk.
10. **Test coverage vs. this prompt:** the PR's 44 Stage 4B tests cover gateway verification, idempotency, failure/cancel paths, staff recording, the two-person rule, evidence validation, credit designation/application, ledger immutability and permission scoping. They did not cover email login or the verification gate (now added).
11. **Reproducibility at the head commit:** the PR's tests are deterministic and run against SQLite in-memory and MySQL 8.4.13; results in §F were produced on the current working tree (`4e25bbc` + this exercise's changes).
12. **Skipped/mocked/bypassed tests:** no test is skipped. Gateway HTTP calls are faked (`Http::fake`) because no provider account exists; OTP transports are faked in tests; the ledger-immutability and permission tests exercise real code paths.
13. **Security / authorization / data-integrity / regression risks:** the two-person rule and the ledger immutability are enforced in the service/model layer, not the UI. Residual risks are listed in §H.

---

## H. Outstanding issues

1. **No SMS provider** — phone verification codes cannot be delivered, so the second verification cannot be completed through the UI in this environment. The gate is fully enforced; the phone-verified state was reached by an explicitly controlled fixture for acceptance. A real SMS transport is required before owner sign-off of the phone channel.
2. **No payment-gateway credentials** — customer online deposit creation, the gateway return screen and webhook delivery could not be exercised end-to-end against a real provider. Server-side validation, gateway verification, signature checks and idempotency are covered by the automated suite with a faked provider.
3. **MySQL 8.4.11 not available** — evidence is from 8.4.13.
4. **Two pre-existing `ChatFeatureTest` failures on MySQL** (chat queue drain / assignment). Unrelated to Stage 4B; reproduced on the baseline branch.
5. **Super-admin browser pass not driven** — the seeded super-admin credential is unknown; authorization for that role follows the existing permission model and is covered by automated tests. Nothing was reset to obtain access.
6. **Stage 4A invoice application in the browser** — the deposit → verify → designate → customer-visible balance chain was driven in the browser; applying credit to an *issued invoice* was verified only by the automated suite (no invoice fixture was created in the browser).
7. **New acceptance fixes are not yet in PR #1's reviewed head** — the email-login and verification-gate changes are on the branch and must be pushed and re-reviewed (CI + a fresh PR read) before merge.
8. **Recorder's own pending record remains visible to them** with a verify button that the service refuses — the UI could disable the control for the recorder, but the security control itself is enforced server-side.

---

## 13. Final acceptance classification

### CONDITIONALLY READY

Core Stage 4B workflows and controls passed: email-or-phone customer sign-in, backend-enforced both-contact verification gating (all four combinations), authenticated deposit history and isolation, staff-recorded deposits with independent two-person verification (recorder refused, second staff member verified, audit trail recorded), append-only credit ledger, credit designation with over-designation refusal, customer credit visibility scoped to the payer, permission denials through both UI and API, and responsive/theme behaviour at 1440/768/390.

The classification is not **READY** because two dependencies still lack end-to-end evidence and the acceptance fixes are not yet part of the reviewed PR head: (a) no SMS provider, so phone verification delivery is unavailable; (b) no gateway credentials, so a real online deposit + provider return/webhook was not exercised in the browser; (c) the new identity/gating changes must be pushed and re-reviewed on PR #1; (d) MySQL evidence is 8.4.13 rather than 8.4.11.

**PR #1 was not merged, not closed, and not modified. Stage 4C remains deferred. Awaiting the owner's explicit decision.**

---

## 14. Environment configuration — local mail capture (Mailpit) and the `/mailpit` preview proxy

_Recorded after the acceptance exercise above. This documents an environment configuration change only: the browser results in §A–§H, the test counts in §F and the financial findings in §D are unchanged, and the classification in §13 is not upgraded._

### Why the local Mailpit configuration was required

`AccountSecurityService::deliverEmail()` refuses to deliver verification or password-recovery email when the default mail driver is `log` (or `array` outside the `testing` environment) and raises `OtpDeliveryUnavailable`. The sandbox stack therefore had no working email channel: the email code described in §B3 could not be requested through the application, and the two-contact verification gate could not be exercised in the preview at all.

The correction is environment-only. A local capture service (Mailpit) receives every message the application sends in the preview, so codes and recovery links can be read without exposing them in the application and without any real message leaving the sandbox.

### Environment configuration repository

The change does not belong in the application repository: it is service, proxy and sandbox configuration, and it is shared by every code branch of the app.

| Item | Value |
| --- | --- |
| Repository | Base44 environment configuration Git (separate from `sgilberts/special-delicacies`) |
| Revision | `6ce847e5dad10c34c4f65cf128c66ae1b71595ba` — "Update environment configuration", Fri 27 Nov 2026 12:07:03 +0000 |
| Previous revision | `f61dbad99a32f881ae303a8d81e27127ed148940` |
| Files changed | `docker-compose.base44.yml`, `preview-proxy.conf` (new), `SETUP.md` |
| Application source files changed | none |

### Mailpit service configuration

- Image `axllent/mailpit:v1.34`, `restart: on-failure`, in-memory store (`MP_MAX_MESSAGES=500`) — no mail database, mail contents or logs are stored in the repository or configuration Git.
- `MP_WEBROOT=/mailpit/` so the UI and its API are served under the preview path; `MP_ALLOWED_HOSTS=mailpit,mailpit:8025` pins the proxied `Host` header (DNS-rebinding mitigation).
- Health probe `["CMD", "/mailpit", "readyz"]`.
- The application's preview mail settings are `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, `MAIL_FROM_ADDRESS=no-reply@specialdelicacies.test`. This is a preview-only sink: no production SMTP host or credential exists in configuration Git, and deployed environments keep their own `MAIL_*` values.

### Preview proxy configuration for `/mailpit/`

A small nginx service (`web`) now owns the single public entry point (host port 3000); the Vite dev server moved to an internal port. `preview-proxy.conf` defines:

- `/` → `http://frontend:5173` with WebSocket upgrade (Vite HMR) and the original `Host` preserved, so the app keeps running its own `vite.config.ts` unchanged.
- `/mailpit` → `301 /mailpit/`, with `absolute_redirect off` so the redirect survives the TLS preview proxy.
- `/mailpit/` → `http://mailpit:8025` with `Host: mailpit:8025` and WebSocket upgrade for live updates.
- Upstreams are resolved per request through Docker's embedded DNS (`127.0.0.11`).

### Internal-only ports and exposure restrictions

- Mailpit publishes **no host port**. `8025` (UI/API) and `1025` (SMTP) are reachable only from other compose services.
- From outside the sandbox, host ports 8025 and 1025 are closed; the capture UI is reachable only at `https://3000-<preview host>/mailpit/`, i.e. through the same preview origin as the application.
- No SMTP credential, API key, token or password was added anywhere; the service accepts mail from the compose network only.

### Local sandbox validation (this exercise)

Performed in the sandbox against the running stack, before the configuration was published:

| Check | Result |
| --- | --- |
| All services healthy (`web`, `frontend`, `backend`, `queue`, `reverb`, `mailpit`, `db`) | pass |
| `GET /` through the proxy | `200`; public site rendered, no console errors, no horizontal overflow |
| `GET /mailpit/` | `200`; assets loaded from `/mailpit/dist/…`, UI rendered |
| Real send through the application (`Mail::raw()` from the backend container) | 1 message captured in Mailpit; the test message was then deleted (inbox back to 0) |
| `GET /api/v1/health` | `{"success":true,…}` |
| Backend environment | `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025` |
| Host ports 8025 / 1025 | closed (no published port) |

### Shared environment publication and validation status

- **Local validation (above)** — the sandbox stack running the new configuration.
- **Shared-environment validation** — the platform validated the revision in a separate sandbox before publishing; the shared environment advanced from `f61dbad` to `6ce847e` with no recorded validation failure, and this app's stack has run healthily from it since. That validation was a boot and health check of the environment configuration, not a re-run of the 423-test suite or of this browser acceptance exercise.

### Remaining limitations

1. **Vite HMR through the new nginx proxy was not separately exercised** — page load, dev assets and the app's API calls were verified; hot-module reload over the proxied WebSocket was not driven.
2. **Mailpit is a preview-only sink** — it captures mail; it does not prove delivery to a real mailbox. Production SMTP remains unverified here (see §H).
3. **SMS remains unavailable** — Mailpit resolves the email channel only; phone verification delivery is still outstanding (§H.1).
4. This section documents environment configuration. It adds no evidence for the payment-gateway or super-admin gaps in §H, and it does not change the classification in §13.
