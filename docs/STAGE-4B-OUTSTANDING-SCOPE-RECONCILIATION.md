# Stage 4B — Outstanding Scope Reconciliation (PR #1)

**Document type:** Read-only reconciliation and closure recommendation
**Exercise date:** 2026-11-27
**Repository:** `sgilberts/special-delicacies`
**Scope:** Stage 4B — Deposits and Customer Credit, and pull request #1
**Nature of work:** Investigation only. No application code, migration, database record, branch, commit or pull request was created, modified, merged, closed, retargeted, rebased or reverted during this exercise. The only file written is this report.

---

## 1. Executive summary

Stage 4B was delivered in **two tranches**, which is the single most important fact for reconciling its outstanding scope:

- **Tranche A — already merged to `main`.** The customer-credit foundation and the account-security/verification foundation: `CustomerCreditService`, `CustomerCreditLedgerEntry`, `AccountSecurityService`, `AccountVerificationController`, the credit panels, and migrations `2026_11_26_000003_create_stage4b_credit_and_deposit_tables` and `2026_11_26_000004_add_customer_verification_and_security_challenges`. Landed directly on `main` in commits `611fb7b` and `f9c0400`.
- **Tranche B — still unmerged in PR #1.** The deposit layer and the both-contact verification gate: `DepositService`, `DepositReceiptService`, `StaffDepositRecord`, `Admin\DepositController`, `CustomerDepositController`, `EnsureCustomerFullyVerified`, migration `2026_11_27_000001_create_staff_deposit_records_table`, four Stage 4B feature-test files and the two Stage 4B reports.

PR #1 has **advanced since the last Stage 4B report**: its head moved from `4e25bbc` (30 files, +2916/−77) to `6d29dd7` (40 files, +4118/−94). The email-or-phone login and both-contact verification gate — previously only in an uncommitted working tree — are now committed (`e22d881`). No Stage 4B commit was removed or superseded, and PR #1 is level with `main`.

The functional foundation is **complete and well covered**: 58 Stage 4B test methods with no skips, real service code exercised with a faked provider transport, and the two-person deposit rule plus ledger immutability enforced in the service/model layer rather than the UI.

None of the seven outstanding items is a functional defect in the Stage 4B foundation. They resolve into: **two external dependencies** (SMS transport, live payment-gateway credentials), **three verification gaps that are drivable today** (super-admin console, invoice application, Vite HMR), **one verification-environment substitution** (MySQL 8.4.11 versus 8.4.13), and **one governance gap** (no independent review recorded on PR #1). One genuine defect was found — the database-safety guard in PR #1 is **weaker than the required protection list** — and it is already corrected on the Stage 4C branch, so it must be reconciled at merge rather than fixed on `main`.

**Final classification: CONDITIONALLY READY FOR FORMAL ACCEPTANCE.** See §10.

---

## 2. Verified repository and PR state

All values below were read live during this exercise, not carried over from earlier reports.

### 2.1 Repository

| Item | Value |
| --- | --- |
| Working branch | `refunds-credit-notes` |
| HEAD | `9cf42f9d0a12295b013700f88ae215cc0a6fcee8` — "Add Stage 4D discovery and design documentation", 2026-11-27 13:35:39 +0000 |
| `main` HEAD | `95d651721aa17cbfaa52cc27209c4858d0970518` — "Add Reverb broadcasting, local CORS and Vite build updates, and payment/delivery refinements", 2026-11-26 14:38:28 +0000 |
| Working tree | **clean** — no modified, staged or untracked files; branch in sync with `origin/refunds-credit-notes` |
| Remote branches | `main`, `refunds-credit-notes`, `audit-deposits-credit`, `stage-4c-refunds-credit-notes`, `feature/stage-4b-completion`, `persist-mail-config`, `repo-summary`, `sync-website-enhancements`, `website-feature-enhancements` |
| Stage 4B head branch | `audit-deposits-credit` at `6d29dd7`, 2026-11-27 12:25:37 |
| `feature/stage-4b-completion` | Points at `95d6517` — identical to `main`, i.e. it contains **no** Stage 4B work and is an empty/stale branch |

### 2.2 Pull request #1

| Field | Value |
| --- | --- |
| Title | Implement Stage 4B: Deposits and Credit Acceptance |
| State | **OPEN**, not a draft |
| Source → target | `audit-deposits-credit` → `main` |
| Mergeability | `MERGEABLE` |
| Merge base | `95d6517` (= `main` HEAD); `main` has **no** commits absent from PR #1, so the PR is up to date |
| Size | **40 files, +4118 / −94** |
| Created / updated | 2026-11-27 11:13:36Z / 2026-11-27 12:25:40Z |
| Reviews | **none** (`reviews: []`, `reviewDecision: null`) |
| Review comments | **none** (`comments: []`) |
| CI checks | **none configured** (`statusCheckRollup: []`) |
| Merged / closed | No / No |

### 2.3 Has PR #1 changed since the last Stage 4B report?

**Yes.** The prior browser-acceptance report recorded head `4e25bbc`, 30 files, +2916/−77, with commits `2086be5`, `d475002`, `4e25bbc`. Current head is `6d29dd7` with **three additional commits**:

| Commit | Subject | Content |
| --- | --- | --- |
| `e22d881` | Implement customer email-or-phone login and verification gating | 16 files, +572/−29 — `EnsureCustomerFullyVerified`, email-or-phone login in `CustomerController`, route gating, `Stage4bCustomerIdentityGatingTest` (7 tests), `verificationGate.ts`, login-page field, `TestCase` guard, acceptance report |
| `feb0446` | Add environment configuration section to acceptance report | 1 file, +72 — §14 environment/Mailpit section |
| `6d29dd7` | Save changes before switching branches | 1 file, +570 — `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md` |

No Stage 4B commit was **removed** or **superseded**. The one behavioural change is that the identity/gating work moved from an uncommitted working tree into the reviewed head — which closes the prior report's own outstanding item H.7.

### 2.4 Pull request #2 (context only, not reviewed)

OPEN, `refunds-credit-notes` → `main`, `MERGEABLE`, 53 files, +7935/−35, head `9cf42f9`. Stage 4C acceptance was **not** performed and is out of scope here.

---

## 3. Original Stage 4B scope

Derived from `docs/STAGE-4B-DEPOSITS-AND-CREDIT-ACCEPTANCE-REPORT.md` (§1, §2, §3), `docs/STAGE-4B-AUTHENTICATED-BROWSER-ACCEPTANCE-REPORT.md` (§B–§E) and the PR #1 diff.

**In scope for Stage 4B**

1. Customer-initiated online deposits through an existing payment gateway, with server-side verification of the provider outcome.
2. Staff-recorded deposits (cash, bank transfer, mobile money) with external receipt reference and supporting evidence, completed by an **independent** second staff member.
3. Receipt classification (deposit vs. advance) and payer designation, consolidating onto the canonical `payment_transactions` ledger.
4. Customer credit creation, derived balance (designations − applications), credit designation and credit application, with over-designation and over-application prevention.
5. Append-only credit ledger and append-only staff deposit records.
6. Customer email and phone verification, OTP generation/expiry/attempt limits/resend throttling/single-use, and email-or-phone account recovery.
7. Both-contact (email **and** phone) verification gating of customer financial features, enforced by the backend.
8. Granular finance permissions, customer/staff access restrictions, organisation financial membership.
9. Auditability and actor attribution.
10. Preservation of Stage 4A payment-allocation behaviour.

**Explicitly deferred by Stage 4B** (acceptance report §7): credit reversals and refunds → Stage 4C; real email/SMS transports → production integration; realtime broadcasting → optional per deployment.

---

## 4. Item-by-item outstanding-scope reconciliation

### 4.1 Item 1 — SMS provider integration for phone verification

- **Original requirement.** Phone verification and phone-based account recovery must deliver a one-time code to the customer's or staff member's telephone (Stage 4B scope item 6; acceptance report §7 records the transport as deferred).
- **Actual implementation status.** The delivery **contract** exists and is honoured end to end. `App\Contracts\SmsOtpDelivery` is bound in `AppServiceProvider` to `App\Services\UnavailableSmsOtpDelivery`, whose `send()` unconditionally throws `RuntimeException('SMS delivery is not configured.')`. `AccountSecurityService::deliverOtp()` routes `channel === 'phone'` to that contract; the failure is caught, the challenge is consumed, an `account_security_events` row records `verification_delivery_unavailable`, and `OtpDeliveryUnavailable` is raised so the API answers "unavailable" rather than a false success.
- **Evidence.** `backend/app/Contracts/SmsOtpDelivery.php`; `backend/app/Services/UnavailableSmsOtpDelivery.php`; `backend/app/Providers/AppServiceProvider.php:19`; `backend/app/Services/AccountSecurityService.php` (`deliverOtp`, `requestVerification`, `requestPhonePasswordReset`). Tests `Stage4bCreditAndSecurityTest`: `test_phone_otp_is_hashed_one_time_rate_limited_and_not_returned_by_api`, `test_phone_recovery_uses_sms_reference_and_invalidates_customer_sessions`, `test_phone_otp_attempt_limit_persists_and_expiry_prevents_replay`, `test_otp_resend_cooldown_and_expiry_are_enforced`.
- **Classification.** **EXTERNAL DEPENDENCY.** The functional foundation (code generation, hashing, expiry, attempt limit, cooldown, single use, audit) is complete; only the transport is absent.
- **Blocks acceptance?** No for the foundation. Yes for production sign-off of the **phone channel specifically** — the phone-verified state cannot be reached through the UI in any environment without an SMS provider.
- **Recommended action.** Leave Stage 4B functional scope as delivered. Open a production-integration item to implement `SmsOtpDelivery` against a chosen Ghanaian provider, then re-run the phone-channel browser pass. Do not claim phone delivery works.

### 4.2 Item 2 — Real gateway credentials and genuine online deposit initiation / return / webhook verification

- **Original requirement.** A customer must be able to start a real gateway charge, be returned to the application, and have the deposit completed only on a verified provider outcome (Stage 4B scope item 1).
- **Actual implementation status.** The code is complete across three providers. `DepositService::initiateOnline()` resolves the gateway through `GatewayPaymentService::depositGateway()`, which refuses any gateway that is not `is_enabled`, not a supported provider, or lacks credentials (`hasCredentials()` requires both public and secret keys). `initializeDepositCharge()` builds the provider charge (Paystack, Hubtel, Korapa); `verifyDepositCharge()` re-queries the provider and requires paid status **and** matching reference, `GHS` currency and exact minor amount; `consumeWebhook()` verifies signatures (`hash_hmac('sha512', …)` with `hash_equals`, 300-second timestamp skew for Paystack's `x-paystack-signature`, Kora HMAC, Hubtel authenticated reconciliation). A browser return alone cannot complete a deposit.
- **Evidence.** `backend/app/Services/GatewayPaymentService.php` (`depositGateway`, `initializeDepositCharge`, `verifyDepositCharge`, `consumeWebhook`, `validPaystackWebhookSignature`, `hasCredentials`); `backend/app/Services/DepositService.php` (`initiateOnline`); `backend/app/Services/DepositReceiptService.php` (`complete`, `fail`). 17 tests in `Stage4bCustomerOnlineDepositTest`, including `test_invalid_webhook_signature_is_rejected`, `test_gateway_amount_currency_and_reference_mismatches_are_rejected`, `test_browser_return_alone_cannot_mark_a_deposit_successful`, `test_valid_webhook_completes_the_receipt_once_even_when_replayed`, `test_failed_payments_cannot_become_credit`, `test_cancelled_webhook_cannot_become_credit`.
- **Environment evidence (live, this exercise).** The development database contains **0 rows** in `payment_gateways`. With no enabled, credentialed gateway, `GET /customer/deposits/options` returns an empty gateway list and the UI shows "Online deposits are not available right now". No live charge, return screen or webhook delivery is therefore possible in this environment.
- **Classification.** **EXTERNAL DEPENDENCY.** All provider HTTP is faked with `Http::fake` in tests, which is appropriate and is not a weakened test — but it is not provider evidence.
- **Blocks acceptance?** No for the functional foundation. Yes for the claim "online deposits work end to end".
- **Recommended action.** The owner configures one merchant account (Paystack is the natural first choice) in a non-production environment, performs a single low-value `GHS` charge plus a real webhook delivery, and records the result. Until then the online deposit path stays classified as foundation-complete, integration-unproven.

### 4.3 Item 3 — Independent review of identity and account-gating changes

- **Original requirement.** Changes that alter authentication and account gating require independent review before merge (the prior report's §H.7 raised this as a precondition).
- **Actual implementation status.** The changes are now **committed and pushed** (`e22d881`), so they are part of the PR #1 head and can be reviewed. They have **not** been independently reviewed: GitHub reports `reviews: []`, `comments: []`, `reviewDecision: null`, and no CI checks exist to act as a substitute gate.
- **Evidence.** `gh pr view 1` (reviews/comments/statusCheckRollup all empty); `git show --stat e22d881`. The changes to review are `EnsureCustomerFullyVerified.php`, the `EnsureCustomerFullyVerified` route group in `routes/api.php`, the email-or-phone lookup in `CustomerController::login`, `frontend/src/lib/verificationGate.ts` and `Stage4bCustomerIdentityGatingTest`.
- **Classification.** **INCOMPLETE** — a governance/assurance gap, not a code defect. The code itself reads correctly: the gate re-reads the account on every request (no session caching), returns `403 customer_verification_required` with a `missing` array, and is applied at the route layer so direct API calls are gated identically to the UI. The deliberately ungated set (profile, own orders, verification endpoints, logout, organisations) is coherent.
- **Blocks acceptance?** **Yes** — it is an explicit merge precondition, and no review record exists.
- **Recommended action.** Assign a reviewer to PR #1 with a focused brief on the four files above. This is the cheapest outstanding item and the one that most directly gates merge.

### 4.4 Item 4 — MySQL 8.4.11 target-version verification

- **Original requirement.** Verify the suite against MySQL **8.4.11**.
- **Actual implementation status.** All recorded MySQL evidence is from **8.4.13**. Verified live in this exercise: the `db` service runs image `mysql:8.4` (a floating minor tag) and `SELECT VERSION()` returns **8.4.13**. The requirement was never met; the substitution was disclosed honestly in the prior report.
- **Evidence.** `docker compose … exec db sh -c 'mysql --version'` → `mysql Ver 8.4.13 for Linux on x86_64`; `SELECT VERSION()` → `8.4.13`. `docker manifest inspect mysql:8.4.11` → **resolves successfully**, so the exact target image is obtainable; this is a configuration choice, not an unavailable artifact. Compose pins `image: mysql:8.4`.
- **Classification.** **ENVIRONMENTAL** — a verification gap caused by a floating image tag, not a code defect.
- **Blocks acceptance?** No. 8.4.11 and 8.4.13 are patch releases of the same 8.4 line, and the Stage 4B schema uses no version-sensitive feature. It remains, however, an unmet verification target.
- **Recommended action.** Either pin `mysql:8.4.11` in a disposable environment and run `--filter Stage4b`, or have the owner formally accept 8.4.13 as the target. Do not present 8.4.13 evidence as 8.4.11 evidence in the meantime.

### 4.5 Item 5 — Super-admin browser acceptance

- **Original requirement.** Drive the super-administrator console in a browser for the Stage 4B surfaces (deposits queue, credit workspace).
- **Actual implementation status.** **Not performed.** The prior report's stated reason was that the seeded super-admin password was unknown and was not guessed or reset. That blocker has since been removed by the environment itself: `SETUP-NOTES.md` in the environment configuration documents the account `super-admin@special-delicacies.local` with a known development password, and `SEED_ADMIN_PASSWORD` is present in the `api` container. The seeded user exists (id 42, active) and holds `super-admin`, which the seeder grants every staff permission.
- **Evidence.** `backend/database/seeders/AdminUserSeeder.php` (`Hash::make(env('SEED_ADMIN_PASSWORD', Str::random(32)))`); `RoleAndPermissionSeeder` (`super-admin` → `array_keys($permissionModels)`); read-only query of `special_delicacies_db.users` → ids 42–45 present; environment `SETUP-NOTES.md` credentials table; `SEED_ADMIN_PASSWORD` presence confirmed in the `api` container (value never read). Automated coverage exists: `Stage4bStaffRecordedDepositTest::test_ordinary_admin_does_not_automatically_receive_finance_permissions`, `Stage4bCreditAndSecurityTest::test_stage4b_permissions_are_granular_and_not_granted_to_ordinary_admin`.
- **Classification.** **INCOMPLETE** — verification not performed, but now fully drivable. Not a defect.
- **Blocks acceptance?** **Yes**, as an open acceptance criterion, though the technical risk is low because super-admin authorisation follows the existing permission model and is covered by automated RBAC tests.
- **Recommended action.** One browser pass signed in as super-admin over `/admin/customer-credit` (deposit queue, verify/reject controls, credit designation) and close the item.

### 4.6 Item 6 — Invoice-application browser acceptance

- **Original requirement.** Demonstrate in the browser that customer credit can be applied to an **issued invoice** and that the resulting balance is correct.
- **Actual implementation status.** Verified by **automated suite only**. The browser chain that was driven ended at customer-visible credit balance; no invoice fixture was created in the browser. The automated coverage is strong: `Stage4bCreditAndSecurityTest::test_credit_designation_and_application_are_immutable_and_feed_invoice_balance` (40.00 allocated + 30.00 credit = 70.00 settled, 130.00 outstanding, and the spent receipt cannot be designated again) and `test_credit_application_rejects_other_payer_currency_and_overapplication`.
- **Evidence.** The two tests above; prior report §D and §H.6. No new browser evidence exists.
- **Classification.** **INCOMPLETE** — verification gap; drivable with a seeded invoice fixture.
- **Blocks acceptance?** **Yes**, as an open acceptance criterion; low technical risk given the automated proof.
- **Recommended action.** Create one invoice fixture through the admin UI, apply customer credit in the browser, assert the invoice balance, then remove the fixture.

### 4.7 Item 7 — Vite HMR through the local proxy

- **Original requirement.** Hot-module reload must work through the preview proxy, not just page load.
- **Actual implementation status.** **Configured but not exercised.** The environment's `nginx.conf` proxies `/` to `web:5173` with an `Upgrade`/`Connection` map, `proxy_read_timeout 86400s`, `Host localhost` (which satisfies Vite's host check), and the `web` service receives `__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS`. `vite.config.ts` in the source is unmodified (no sandbox-specific override is needed). No HMR edit-and-observe test has been recorded.
- **Evidence.** Environment `nginx.conf` (`map $http_upgrade`, `location /` block); `docker-compose.base44.yml` `web` service environment; `frontend/vite.config.ts` (unchanged, plugin-only). Prior report §14 "Remaining limitations" item 1 states HMR was not separately exercised.
- **Classification.** **ENVIRONMENTAL** — a verification gap in a shared environment asset.
- **Blocks acceptance?** No. It affects developer experience, not Stage 4B functionality.
- **Recommended action.** Edit one component while the preview is open and confirm the update lands without a manual reload. One check closes it.

### 4.8 Reconciliation table

| Item | Original requirement | Current status | Evidence | Classification | Blocks acceptance? | Recommended action |
| --- | --- | --- | --- | --- | --- | --- |
| 1. SMS provider for phone verification | Deliver phone OTP by SMS | Contract bound to `UnavailableSmsOtpDelivery`, which throws; OTP mechanics complete | `SmsOtpDelivery`, `UnavailableSmsOtpDelivery`, `AppServiceProvider:19`, `AccountSecurityService::deliverOtp`; 4 OTP tests | EXTERNAL DEPENDENCY | No for foundation; yes for phone-channel sign-off | Implement transport in a production-integration stage |
| 2. Real gateway credentials + genuine initiation/return/webhook | Real online deposit end to end | Code complete for 3 providers with signature/amount/currency checks; provider HTTP faked in tests; **0** gateways configured live | `GatewayPaymentService` (all methods), `DepositService::initiateOnline`, 17 online-deposit tests, live `payment_gateways` count = 0 | EXTERNAL DEPENDENCY | No for foundation; yes for "works end to end" | Owner configures one merchant account; one real charge + webhook |
| 3. Independent review of identity/gating | Independent review before merge | Changes pushed in `e22d881`; **no** review, comment or CI recorded | `gh pr view 1` → `reviews: []`, `comments: []`, `statusCheckRollup: []`; `git show --stat e22d881` | INCOMPLETE (governance) | **Yes** | Assign a focused reviewer to PR #1 |
| 4. MySQL 8.4.11 verification | Verify on 8.4.11 | Evidence is 8.4.13; live server is 8.4.13; 8.4.11 image resolves | `SELECT VERSION()` = 8.4.13; `docker manifest inspect mysql:8.4.11` succeeds; compose pins `mysql:8.4` | ENVIRONMENTAL | No | Pin 8.4.11 in a disposable env, or formally accept 8.4.13 |
| 5. Super-admin browser acceptance | Drive super-admin console | Not performed; blocker (unknown password) no longer applies | `AdminUserSeeder`, `RoleAndPermissionSeeder`, live users 42–45, environment `SETUP-NOTES.md` credentials | INCOMPLETE (verification) | **Yes** (open criterion) | One super-admin browser pass over deposits/credit |
| 6. Invoice-application browser acceptance | Apply credit to an issued invoice in browser | Automated only | `test_credit_designation_and_application_are_immutable_and_feed_invoice_balance`, `test_credit_application_rejects_other_payer_currency_and_overapplication` | INCOMPLETE (verification) | **Yes** (open criterion) | Seed an invoice, apply credit in browser, remove fixture |
| 7. Vite HMR through proxy | HMR works through preview proxy | Proxy/WS configured; not exercised | Environment `nginx.conf`, compose `web` env, unmodified `vite.config.ts` | ENVIRONMENTAL | No | One edit-and-observe check |

### 4.9 Additional findings discovered in this exercise

| Item | Finding | Evidence | Classification | Blocks acceptance? | Recommended action |
| --- | --- | --- | --- | --- | --- |
| A. Database guard weaker than the protection list | PR #1's `TestCase` guard protects only `special_delicacies` and `special_delicacies_db`. A MySQL database named `special_delicacies_stage4a_test`, `special_delicacies_stage4a_acceptance_test` or `special_delicacies_stage4b_test` **passes** the guard (it ends in `_test` and is not on the denylist), so `RefreshDatabase` would truncate it. Stage 4C's version already lists all five names and additionally forces the connection in `createApplication()`. | `origin/audit-deposits-credit:backend/tests/TestCase.php` (`$protected`, `assertSafeTestDatabase`) versus `HEAD:backend/tests/TestCase.php` (`PROTECTED_DATABASES`, `isDisposableMysqlDatabase`) | **DEFECT** (in PR #1 as it would land on `main`) | **Yes** — it is a data-safety defect against the stated protection list | Reconcile at merge in favour of Stage 4C's `PROTECTED_DATABASES` + `createApplication()` implementation; do not merge PR #1's `setUpTraits()` guard as-is |
| B. Neither verification channel is deliverable in the current environment | `MAIL_MAILER=log` in the `api` service. `AccountSecurityService::deliverEmail()` explicitly **refuses** the `log` driver and raises `OtpDeliveryUnavailable`; SMS is the throwing stub. Combined with the both-contact gate, no customer can unlock deposits/credit in this sandbox. The Mailpit capture service documented in the prior report (§14, revision `6ce847e`) is **absent** from the current environment revision (`nginx.conf`, no `mailpit` service, no `MAIL_*` beyond `MAIL_MAILER`). | compose `MAIL_MAILER: "log"`; `AccountSecurityService::deliverEmail`; absence of mailpit in compose/services; environment `SETUP-NOTES.md` "Mail: `MAIL_MAILER=log`" | ENVIRONMENTAL | No for Stage 4B correctness (the code fails safe and loudly); yes for browser verification of any verification-gated screen | Restore a capture transport (Mailpit or equivalent) in the environment, or accept that verification screens are fixture-only in the sandbox |
| C. Stage 4C document inside the Stage 4B PR | Commit `6d29dd7` "Save changes before switching branches" adds the 570-line `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md` to the Stage 4B branch. It is byte-identical to the copy on the Stage 4C branch, so merging both is conflict-free, but it is cross-stage scope in a Stage 4B PR under a WIP commit message. | `git show --stat 6d29dd7`; `git diff --quiet origin/audit-deposits-credit:<doc> HEAD:<doc>` → identical | DEFERRED (scope hygiene) | No | Leave in place (harmless, identical) or move to PR #2 for a clean Stage 4B diff |
| D. Stale environment documentation in the acceptance report | §14 documents a Mailpit service, `preview-proxy.conf` and a published environment revision that no longer describe the running environment. | Report §14 versus live `nginx.conf`/compose | DEFERRED (documentation accuracy) | No | Annotate §14 as historical, or supersede it with the current environment description |
| E. Obsolete gitignore entry | `frontend/.gitignore` ignores `/vite.preview.config.ts`, a wrapper the environment no longer generates. | PR #1 diff on `frontend/.gitignore`; file absent from `/app/frontend` | DEFERRED (cosmetic) | No | Drop the entry or keep it harmlessly |
| F. Merge conflicts with Stage 4C | Three files conflict textually: `GatewayPaymentService.php`, `routes/api.php`, `tests/TestCase.php`. All are additive-but-adjacent (PR #1 adds deposit methods/routes; Stage 4C adds refund methods/routes). | `git merge-tree --write-tree origin/audit-deposits-credit HEAD` → 3 `CONFLICT (content)` | ENVIRONMENTAL (merge mechanics) | No | Resolve mechanically keeping both sides, and take Stage 4C's `TestCase` guard |
| G. Migration rollback risk | `down()` re-narrows four `payment_receipt_intents` actor columns to `NOT NULL`; it fails if any customer-initiated receipt (null actor) exists. Forward migration is additive and safe. | `2026_11_27_000001_create_staff_deposit_records_table.php` `up()`/`down()` | DEFERRED (rollback-time only) | No | Accept; document that rollback requires clearing/backfilling customer-initiated intents |

---

## 5. Functional and regression test assessment

### 5.1 Coverage inventory (PR #1 head, `6d29dd7`)

58 Stage 4B test methods across five files, **no skips and no weakened assertions**:

| File | Tests | Focus |
| --- | --- | --- |
| `Stage4bCustomerOnlineDepositTest` | 17 | initiation, auth/scoping, amount/gateway validation, idempotency, server verification, webhook signature, amount/currency/reference mismatch, browser-return insufficiency, replay, failed/cancelled cannot become credit, designation once |
| `Stage4bStaffRecordedDepositTest` | 15 | recording, permission scoping, no auto-grant to ordinary admin, recorder-cannot-self-verify, independent verification, unauthorised verifier, pending/rejected cannot be credited, evidence validation, actor/timestamp/audit, idempotent verification, duplicate external reference, no duplicate transaction, organisation payer, queue filters |
| `Stage4bRemediationTest` | 8 | phone reformat preserves verification, challenge binding, intent reference reuse, unverified/unclassified cannot become credit, ledger immutability, customer/staff endpoint separation, credit view scoping, granular manage permission |
| `Stage4bCreditAndSecurityTest` | 11 | credit designation/application + invoice balance, over-application, organisation credit isolation, granular permissions, phone OTP hashed/one-time/rate-limited, email OTP + reset link binding, phone recovery session invalidation, ambiguous phone refusal, attempt limit + expiry, cooldown + expiry, cross-account-type reset refusal |
| `Stage4bCustomerIdentityGatingTest` | 7 | email-or-phone sign-in, no account enumeration, the three restricted combinations, unlock on existing session, guest refusal |

### 5.2 Assessment against the requested checklist

| Requirement | Status | Note |
| --- | --- | --- |
| Customer email and phone verification | Covered | Both channels' mechanics; delivery transport absent (external) |
| OTP generation, expiry, attempt limits, resend throttling, single use | **Covered and verified in code** | `random_int(100000, 999999)`, `Hash::make` storage, `expires_at` (10 min), `failed_attempts >= 5` lockout, 60-second resend cooldown, `consumed_at` single use, `recipient_hash` binding, `lockForUpdate` inside a 3-attempt transaction |
| Email and phone account recovery | Covered | Reset link for email, OTP for phone, session token revocation on reset, ambiguous-phone refusal |
| Deposit recording and verification | Covered | Both workflows, two-person rule in the service layer |
| Deposit lifecycle pending/succeeded/failed/cancelled | Covered | `DepositReceiptService::complete` refuses non-`pending`; `fail` accepts only `failed`/`cancelled` and refuses a `succeeded` transaction; no new states introduced |
| Customer credit creation and balance calculations | Covered | Derived balance, never stored |
| Credit designation and over-designation prevention | Covered | `409` on excess; spent receipt cannot be re-designated |
| Customer and staff access restrictions | Covered | 401/403/404 evidence in both automated and prior browser runs |
| Organisation membership and financial permissions | Covered | `financials.view` / `financials.deposits.*` / `financials.customer_credit.*` |
| Auditability and actor attribution | Covered | `staff_deposit_records` recorder/verifier + snapshots, `account_security_events` append-only, `AdminAuditLog` |
| Stage 4A allocation regression | Covered | `PaymentAllocationTest` plus the combined 40.00 + 30.00 = 70.00 settled scenario |
| Stage 4B database-safety protections | **Partially covered — see finding A** | Guard exists but is narrower than the required protection list in PR #1's version |

### 5.3 Missing, weakened or environment-dependent tests

- **No weakened or skipped tests found.** `markTestSkipped` does not appear in any Stage 4B file; the only transport substitution is a single `Http::fake` in the online-deposit test, which is the correct way to test provider logic without an account.
- **Environment-dependent gap:** every SMS assertion runs against a fake transport, so the *delivery* step is untested by construction (external dependency, item 1).
- **Not covered by any test:** the both-contact gate's interaction with organisation-level financial access for a partially verified organisation member (the gate is customer-level only, which is consistent with its stated design, but the combination is unasserted).
- **Not re-executed in this exercise:** the suite itself. See §6.3.

### 5.4 Does Stage 4C rely on Stage 4B?

**At code level: no.** Stage 4C (`refunds-credit-notes`) contains **zero** references to PR #1-only symbols — `StaffDepositRecord`, `DepositService`, `DepositReceiptService`, `EnsureCustomerFullyVerified`, `CustomerDepositController` all return no matches across `backend/` and `frontend/`. Stage 4C depends only on the Stage 4B tranche already on `main` (`CustomerCreditService`, `CustomerCreditLedgerEntry`, `AccountSecurityService`). `GatewayPaymentService::refundGatewayFor()` resolves its own gateway and does not use any PR #1 helper (`hasCredentials` already exists on `main`).

**At functional level: yes, in one direction.** `RefundService::eligiblePayments()` selects any `payment_transactions` row with `status = 'succeeded'` and non-null `verified_at`/`received_at`. Once PR #1 lands and creates deposit transactions, those deposits become refund-eligible automatically, with no deposit-specific handling in Stage 4C. This is not a Stage 4B blocker, but it is a cross-stage integration point that Stage 4C acceptance must exercise deliberately.

---

## 6. Database and environment safety assessment

### 6.1 Protected databases — untouched

No migration, test, seed, truncate or write of any kind was executed against `special_delicacies_db`, `special_delicacies_stage4a_test`, `special_delicacies_stage4a_acceptance_test` or `special_delicacies_stage4b_test`. All database interaction in this exercise was read-only `SELECT`/`information_schema` inspection.

### 6.2 Observed database state (read-only)

| Observation | Value |
| --- | --- |
| Schemas matching `special_delicacies%` | `special_delicacies_db`, `special_delicacies_stage4c_test` |
| `special_delicacies_stage4a_test` | **does not exist** in this instance |
| `special_delicacies_stage4a_acceptance_test` | **does not exist** in this instance |
| `special_delicacies_stage4b_test` | **does not exist** in this instance |
| `payment_gateways` | 0 rows |
| `payment_transactions` | 0 rows |
| `customer_credit_ledger_entries` | 0 rows |
| `account_security_challenges` | 0 rows |
| `staff_deposit_records` | table absent (PR #1 migration not applied here — expected, the branch is unmerged) |
| Seeded users | ids 42–45 (super-admin, waiter, kitchen, delivery), all active |

Consequence: three of the four strictly protected databases do not exist in this instance, so they could not have been harmed; but their **absence is not protection** — see finding A, where the guard would still admit them by name if they were recreated.

### 6.3 Migration compatibility

- PR #1 adds **one** migration. `up()` is purely additive: a new `staff_deposit_records` table (unique FK to `payment_transactions`, unique `operation_reference`, unique `(payment_method, external_reference)`, two queue indexes, recorder/verifier actor snapshots) plus four `payment_receipt_intents` actor columns widened to nullable. No data rewrite, no destructive change, no foreign-key redefinition of existing tables.
- `down()` re-narrows those four columns to `NOT NULL` and would fail if any customer-initiated receipt (null actor) exists. This is a **rollback-time** concern only (finding G).
- Stage 4C adds five further additive migrations; they are independent of PR #1's migration, and the two sets do not touch the same tables except through `payment_receipt_intents` reads.

### 6.4 Test-database safeguards

- `phpunit.xml` on PR #1 adds eleven `<server … force="true"/>` entries so a container-exported `DB_CONNECTION=mysql` cannot redirect the suite at the development database. Sound.
- `Tests\TestCase` on PR #1 refuses to run outside `testing`, refuses SQLite unless `:memory:`/`*_test.sqlite`, and for MySQL refuses any database not ending in `_test` — with a denylist of only `special_delicacies` and `special_delicacies_db`.
- **Gap:** the three protected `*_test` databases satisfy `str_ends_with($database, '_test')` and are absent from the denylist, so they are treated as disposable. Stage 4C's version fixes this with an explicit five-name `PROTECTED_DATABASES` constant checked by `isDisposableMysqlDatabase()`, plus a stricter regex and pre-boot env forcing in `createApplication()`.

### 6.5 Environment observations relevant to Stage 4B verification

| Item | Observed |
| --- | --- |
| MySQL | image `mysql:8.4`, server **8.4.13**; `mysql:8.4.11` manifest resolves |
| Mail | `MAIL_MAILER=log`; **no** Mailpit service; `MAIL_HOST` unset. `AccountSecurityService::deliverEmail()` refuses the `log` driver → email OTP undeliverable |
| SMS | throwing stub → phone OTP undeliverable |
| Proxy | nginx on host port 3000: `/` → `web:5173` (WS upgrade, 86400s), `/api/` `/sanctum/` `/storage/` `/up` → `api:8000` |
| Realtime | compose sets `VITE_BROADCAST_DRIVER=reverb`; the `reverb` service is defined but stopped in this task, so the browser will attempt a socket to a non-running server. PR #1's `echo.ts` adds a `none` mode that would disable it, but the environment does not set it |
| Secrets | `SEED_ADMIN_PASSWORD` present; `/run/base44/app.env` not mounted (environment declares no secrets) |
| Database name | `special_delicacies_db` — a **protected** name, and the guard correctly refuses it |

---

## 7. PR #1 diff assessment

Complete diff reviewed: 40 files, +4118 / −94, base `95d6517`, head `6d29dd7`.

### 7.1 Composition

- **Backend (17):** `Admin/DepositController`, `CustomerDepositController`, `EnsureCustomerFullyVerified`, `DepositService`, `DepositReceiptService`, `StaffDepositRecord`, `PaymentTransaction`, `CustomerCreditLedgerEntry`, `CustomerCreditService`, `GatewayPaymentService`, `GhanaPhoneNumber`, `AuthController`, `CustomerController`, one migration, `phpunit.xml`, `routes/api.php`, `TestCase`.
- **Tests (7):** four new Stage 4B files, plus fixture-only edits to `CommercialDocumentsTest`, `PaymentAllocationTest`, `Stage4bCreditAndSecurityTest`.
- **Frontend (12):** `App.tsx`, `CustomerDepositPanel`, `StaffDepositPanel`, `CustomerCreditPanel`, `AdminCustomerCreditPage`, `CustomerAccountPage`, `CustomerDepositsPage`, `CustomerLoginPage`, `PasswordRecoveryPage`, `echo.ts`, `verificationGate.ts`, `.gitignore`.
- **Docs (4):** `README.md`, the two Stage 4B reports, and the Stage 4C design document.

### 7.2 Findings

| Question | Finding |
| --- | --- |
| Unrelated changes? | **One.** The 570-line Stage 4C design document (finding C). Everything else is Stage 4B or a necessary fixture adjustment. The single-line `PasswordRecoveryPage` change is in scope (recovery is a Stage 4B deliverable). |
| Duplicate or obsolete implementation? | **Mild.** PR #1 hardens `phpunit.xml` with `<server force="true">` while Stage 4C hardens the same concern in `TestCase::createApplication()`; after both merge, one mechanism is redundant (harmless). The `frontend/.gitignore` entry for `/vite.preview.config.ts` is obsolete (finding E). §14 of the acceptance report describes an environment that no longer exists (finding D). |
| Uncommitted or undocumented work? | **None.** Working tree clean; head is pushed; the identity/gating work that was previously uncommitted is now commit `e22d881`. |
| Migration or database risks? | **Additive and safe forward**; rollback narrowing risk only (finding G). No destructive operation, no existing-table rewrite. |
| Conflicts with Stage 4C? | **Three textual conflicts** in `GatewayPaymentService.php`, `routes/api.php`, `tests/TestCase.php` (finding F). All semantically independent; resolution is mechanical except `TestCase`, where Stage 4C's version must win (finding A). |
| Missing documentation or acceptance evidence? | The two Stage 4B reports exist and are indexed in `docs/README.md`. Missing: an independent review record, super-admin browser evidence, invoice-application browser evidence, HMR evidence, and 8.4.11 evidence. `docs/IMPLEMENTATION-LOG.md` has no Stage 4B entry. |
| Reason the PR should not be merged? | **Yes, three:** (1) no independent review of the identity/gating changes; (2) the `TestCase` guard is weaker than the required protection list and must be reconciled with Stage 4C's version before it reaches `main`; (3) the acceptance criteria for items 5 and 6 remain unmet. Items 1, 2 and 4 are disclosed external/environmental dependencies and are not merge blockers in themselves. |

### 7.3 Hygiene

No `.env`, database file, `dist/`, build artifact, log or `node_modules` content is present. The only secret-shaped strings in the diff are test-fixture gateway keys inside feature tests (used to construct a `PaymentGateway` row and to compute an HMAC in a test), not production credentials.

---

## 8. Outstanding blockers

Ordered by the gate they block.

**Merge blockers**

1. **No independent review** of the identity/account-gating changes on PR #1 (item 3).
2. **Database-safety guard defect** — PR #1's `TestCase` guard would permit `RefreshDatabase` against `special_delicacies_stage4a_test`, `special_delicacies_stage4a_acceptance_test` and `special_delicacies_stage4b_test` (finding A). Must be resolved in favour of Stage 4C's implementation.
3. **No CI checks** configured on the repository, so nothing substitutes for the missing review.

**Acceptance-criterion blockers**

4. **Super-admin browser acceptance** not performed, though now drivable with documented credentials (item 5).
5. **Invoice-application browser acceptance** not performed (item 6).

**Disclosed dependencies — do not block merge, do block the corresponding production claim**

6. **No SMS provider** (item 1).
7. **No gateway credentials** — live `payment_gateways` = 0 (item 2).

**Environment / verification gaps — no functional impact**

8. **MySQL 8.4.11** never verified; 8.4.13 used (item 4). Target image is obtainable.
9. **Vite HMR through the proxy** not exercised (item 7).
10. **Neither verification channel deliverable in the current sandbox** (`MAIL_MAILER=log` + SMS stub), so any verification-gated screen is fixture-only here (finding B).

**Hygiene / deferred**

11. Stage 4C design doc inside PR #1 (finding C); stale §14 environment section (finding D); obsolete gitignore entry (finding E); migration rollback narrowing (finding G).

---

## 9. Recommended closure actions

1. **Assign an independent reviewer to PR #1**, briefed on `EnsureCustomerFullyVerified`, the gated route group, `CustomerController::login`, `verificationGate.ts` and `Stage4bCustomerIdentityGatingTest`. Record the review on GitHub.
2. **Fix the guard at merge, not on `main`.** When PR #1 is rebased/merged against Stage 4C, adopt Stage 4C's `PROTECTED_DATABASES` + `createApplication()` implementation of `tests/TestCase.php`. If PR #1 is to merge *before* Stage 4C, amend `TestCase.php` in PR #1 first so `main` never carries the weaker guard.
3. **Close the two browser criteria** in a single sitting: (a) super-admin pass over `/admin/customer-credit`; (b) create an invoice fixture, apply customer credit, assert the balance, remove the fixture. Both are now drivable.
4. **Decide the MySQL target.** Either pin `mysql:8.4.11` in a disposable environment and run `--filter Stage4b`, or record a formal owner acceptance of 8.4.13.
5. **Exercise HMR once** with an edit-and-observe check through the proxy.
6. **Restore a mail-capture transport** (Mailpit or equivalent) in the environment if browser verification of the verification-gated screens is required; otherwise record that those screens are fixture-only in the sandbox and annotate report §14 as historical.
7. **Keep the two external dependencies open and explicit.** Do not enable provider refunds, SMS or gateway integrations as part of closing Stage 4B. Track them as production-integration items with their own acceptance evidence.
8. **Resolve the three merge conflicts mechanically**, keeping both sides, taking Stage 4C's `TestCase`.
9. **Optional hygiene:** move the Stage 4C design document out of PR #1, drop the obsolete `/vite.preview.config.ts` ignore, and add a Stage 4B entry to `docs/IMPLEMENTATION-LOG.md`.

---

## 10. Final classification

### CONDITIONALLY READY FOR FORMAL ACCEPTANCE

**Why not `NOT READY`.** No functional defect was found in the Stage 4B foundation. All ten scope items in §3 have real implementations, and 58 Stage 4B tests with no skips cover them, including the security-critical paths (recorder-cannot-self-verify, ledger immutability, over-designation refusal, webhook signature rejection, browser-return insufficiency, OTP attempt limits and single use). The deposit lifecycle is preserved exactly; no new payment states were invented. The prior report's own outstanding item about the identity work not being in the reviewed head is now closed by `e22d881`.

**Why not `READY`.** Four things stand between this state and unconditional acceptance:

1. **No independent review exists** on PR #1, and there is no CI to substitute for it (item 3).
2. **The database-safety guard in PR #1 is narrower than the required protection list** and would admit the three protected Stage 4A/4B test databases (finding A). This is the one item that is a genuine defect rather than a gap, and it must not reach `main` in its current form.
3. **Two acceptance criteria remain unperformed** — super-admin console and invoice application — even though both are now drivable (items 5, 6).
4. **Two external dependencies are unproven end to end** — SMS delivery and live gateway credentials — so neither the phone channel nor a real online deposit can be claimed as working (items 1, 2).

The remaining items (MySQL 8.4.11, HMR, mail capture) are environmental verification gaps with no functional impact and no bearing on whether the Stage 4B code is correct.

**Condition.** Formal acceptance can be granted once: an independent review is recorded on PR #1; the `TestCase` guard is reconciled to Stage 4C's protected list; and the super-admin and invoice-application browser passes are performed. The SMS and gateway items should then be reclassified as production-integration requirements outside Stage 4B acceptance, with their own evidence trail — not as Stage 4B defects.

---

## Appendix A — Files inspected

**Repository state and PR metadata**
- `git` refs and logs for `main`, `refunds-credit-notes`, `origin/audit-deposits-credit`, `origin/feature/stage-4b-completion`
- `gh pr list`, `gh pr view 1`

**Stage 4B documentation (PR #1 head)**
- `docs/STAGE-4B-DEPOSITS-AND-CREDIT-ACCEPTANCE-REPORT.md`
- `docs/STAGE-4B-AUTHENTICATED-BROWSER-ACCEPTANCE-REPORT.md` (all sections, §A–§14)
- `docs/README.md` (PR #1 diff)
- `docs/IMPLEMENTATION-LOG.md` (Stage 4B search)
- `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md` (identity check against Stage 4C copy)

**Stage 4B backend (PR #1 head)**
- `backend/app/Services/AccountSecurityService.php` (full)
- `backend/app/Services/UnavailableSmsOtpDelivery.php`
- `backend/app/Contracts/SmsOtpDelivery.php` (via grep)
- `backend/app/Providers/AppServiceProvider.php` (binding)
- `backend/app/Http/Middleware/EnsureCustomerFullyVerified.php`
- `backend/app/Services/DepositReceiptService.php` (lifecycle)
- `backend/app/Services/DepositService.php` (surface)
- `backend/app/Services/GatewayPaymentService.php` (surface + signature methods)
- `backend/routes/api.php` (gated groups and route additions)
- `backend/tests/TestCase.php` (both versions)
- `backend/phpunit.xml` (both versions)
- `backend/database/migrations/2026_11_27_000001_create_staff_deposit_records_table.php`
- `backend/database/seeders/AdminUserSeeder.php`, `RoleAndPermissionSeeder.php`
- `backend/tests/Feature/Stage4bCustomerOnlineDepositTest.php`, `Stage4bStaffRecordedDepositTest.php`, `Stage4bRemediationTest.php`, `Stage4bCreditAndSecurityTest.php`, `Stage4bCustomerIdentityGatingTest.php` (method inventories and skip/fake scan)

**Stage 4C (current branch, dependency and conflict analysis only)**
- `backend/tests/TestCase.php`
- `backend/app/Services/GatewayPaymentService.php`, `RefundService.php`, `Stage4cPolicy.php`
- `backend/routes/api.php` (route additions)

**Frontend**
- `frontend/vite.config.ts`
- `frontend/src/lib/echo.ts` (both versions)
- `frontend/.gitignore` (PR #1 diff)

**Environment configuration (read-only)**
- `docker-compose.base44.yml`, `nginx.conf`, `SETUP-NOTES.md`, `backend-setup.sh`, `.base44/environment.json`

## Appendix B — Commands and checks performed

```
git rev-parse --abbrev-ref HEAD ; git rev-parse HEAD ; git log -1
git status --porcelain=v1 -b ; git branch -a --format=…
gh pr list --state all --json … ; gh pr view 1 --json …
git merge-base origin/main origin/audit-deposits-credit
git log --format=… origin/main..origin/audit-deposits-credit ; … origin/audit-deposits-credit..origin/main
git diff --stat origin/main...origin/audit-deposits-credit ; --diff-filter=A
git show origin/audit-deposits-credit:<each file above>
git show --stat e22d881 / feb0446 / 6d29dd7
git diff origin/main...origin/audit-deposits-credit -- <each changed file of interest>
git diff origin/main..HEAD -- <each changed file of interest>
git ls-tree -r --name-only origin/audit-deposits-credit -- docs/ backend/tests backend/app
git grep -n -i -E "sms|otp" origin/audit-deposits-credit -- backend/app backend/config backend/routes
git grep -n "<PR#1-only symbol>" -- backend frontend          # zero matches
git diff --name-only origin/main...origin/audit-deposits-credit | sort > /tmp/pr1.txt
git diff --name-only origin/main...HEAD | sort > /tmp/pr2.txt
comm -12 /tmp/pr1.txt /tmp/pr2.txt
git merge-tree --write-tree origin/audit-deposits-credit HEAD   # 3 conflicts
git diff … | grep -E "sk_live|sk_test|BEGIN … PRIVATE|password=…"   # no real credentials
docker compose … ps
docker compose … exec -T db sh -c 'mysql --version; SELECT VERSION();'
docker compose … exec -T db … "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'special_delicacies%'"
docker compose … exec -T db … "SELECT table_name, table_rows … WHERE table_schema='special_delicacies_db'"
docker compose … exec -T db … "SELECT id, email, is_active FROM special_delicacies_db.users"
docker compose … exec -T api sh -c 'printenv <KEY> >/dev/null && echo present'   # presence only, values never printed
docker manifest inspect mysql:8.4.11
```

## Appendix C — Limitations of this exercise

1. **The test suite was not re-executed.** Per the authorization to avoid any run that could write to a protected database, and because the existing evidence is sufficient for reconciliation, no `php artisan test`, PHPUnit or migration command was run. All test claims in this report are read from the test sources and from the two Stage 4B reports, and are labelled as such; they are **not** a re-verification.
2. **No browser acceptance was performed.** Items 5, 6 and 7 are reported as not performed; the report establishes that they are drivable, not that they pass.
3. **No live provider or SMS evidence exists** and none was sought.
4. **The protected Stage 4A/4B test databases do not exist in this MySQL instance**, so their protection could only be assessed by reading the guard's logic, not by attempting a run against them (which was not authorized).
5. **This report is written to the current working branch** (`refunds-credit-notes`), because that is the only branch this session can write to. It is a documentation file only and contains no code, migration or data change; the owner may relocate it to the Stage 4B branch or `main` if that is preferred.
6. **Environment observations reflect the current sandbox revision**, which has changed since the prior report (Mailpit removed, `nginx.conf` replacing `preview-proxy.conf`). They are not a statement about any other environment.

---

*Read-only reconciliation. PR #1 was not merged, closed, retargeted, rebased or modified. Stage 4C acceptance and Stage 4D were not begun. No payment-provider, SMS or mail integration was enabled. Awaiting owner review and further authorization.*
