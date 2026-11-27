# Stage 4B — Outstanding Scope Reconciliation (Closure Pass)

**Document type:** Closure reconciliation
**Date:** 2026-11-27
**Repository:** `sgilberts/special-delicacies`
**Remediation branch:** `stage-4b-remediation`
**Base:** PR #1 head `6d29dd7` (branch `audit-deposits-credit`), itself a clean fast-forward from `main` `95d6517`.

---

## 1. Purpose

Reconcile the outstanding Stage 4B closure items identified in the earlier read-only
reconciliation, record what this remediation pass changed, and state the evidence for each
item. This pass was authorised for isolated remediation and verification only: no pull
request was merged, retargeted or closed, and no Stage 4C or Stage 4D work was started.

---

## 2. Delivery is still two tranches

Stage 4B arrived in two tranches, which remains the key reconciliation fact:

- **Tranche A — already on `main`.** Customer-credit foundation and account-security /
  verification foundation: `CustomerCreditService`, `CustomerCreditLedgerEntry`,
  `AccountSecurityService`, `AccountVerificationController`, credit panels, and the
  Stage 4B credit/verification migrations.
- **Tranche B — PR #1 (`audit-deposits-credit`).** The deposit layer and the both-contact
  verification gate: `DepositService`, `DepositReceiptService`, `StaffDepositRecord`,
  `Admin\DepositController`, `CustomerDepositController`, `EnsureCustomerFullyVerified`,
  the `staff_deposit_records` migration, the Stage 4B feature tests and reports.

PR #1 head is `6d29dd7` (40 files, +4118 / −94). `main` is the merge base and has no commits
absent from PR #1, so the PR is level with `main`.

---

## 3. Outstanding items and their closure status

| # | Item | Prior status | This pass | Evidence |
| --- | --- | --- | --- | --- |
| 1 | SMS transport for phone verification | External dependency | **Classified external production dependency** (see §4) | `UnavailableSmsOtpDelivery`, `SmsOtpDelivery` contract; tests use a fake transport |
| 2 | Live payment-gateway credentials | External dependency | **Classified external production dependency**; integration foundation verified with fakes | `GatewayPaymentService`, `Stage4bCustomerOnlineDepositTest` (`Http::fake`) |
| 3 | Super-admin browser acceptance | Not performed | **Performed and passed** | `STAGE-4B-BROWSER-ACCEPTANCE-REPORT.md` |
| 4 | Invoice-application browser acceptance | Not performed | **Performed and passed** | `STAGE-4B-BROWSER-ACCEPTANCE-REPORT.md` |
| 5 | MySQL 8.4.11 verification (prior evidence was 8.4.13) | Gap | **Verified on an isolated MySQL 8.4.11 instance** | `STAGE-4B-MYSQL-8.4.11-VERIFICATION.md` |
| 6 | Vite HMR verification | Configuration only | **HMR WebSocket verified live, direct and through the public proxy** | `STAGE-4B-BROWSER-ACCEPTANCE-REPORT.md` §7 |
| 7 | Independent security review on PR #1 | Missing | **Performed** | `STAGE-4B-INDEPENDENT-SECURITY-REVIEW.md` |
| 8 | Database-safety guard narrower than the required protection list | **Open defect** | **Remediated** | `STAGE-4B-DATABASE-GUARD-REMEDIATION.md` |
| 9 | PR #1 / PR #2 three-file overlap | Open | **Analysed; safe integration strategy documented; no risky merge performed** | `STAGE-4B-PR-OVERLAP-AND-INTEGRATION-REPORT.md` |

---

## 4. External production dependencies

The SMS transport and the live merchant gateway are **production integration
dependencies**, not Stage 4B foundation deliverables:

- The Stage 4B foundation is the challenge lifecycle (creation, hashing, expiry, attempt
  limits, resend throttling, single use, recipient binding, session invalidation) and the
  deposit/credit/ledger logic. Those are implemented and verified.
- Actual delivery to a telephone network and actual capture of merchant funds require
  provider accounts and credentials that belong to the owner and are not part of this
  environment (`MAIL_MAILER=log`, `SmsOtpDelivery` bound to `UnavailableSmsOtpDelivery`,
  zero `payment_gateways` rows).

They are therefore classified as external production dependencies and are not treated as
Stage 4B blockers, provided the owner formally accepts that classification.

---

## 5. What this pass changed

Code changes are confined to the test harness; no application behaviour was altered:

- `backend/tests/TestCase.php` — replaced the narrow guard with the corrected fail-closed
  guard.
- `backend/tests/Support/TestDatabaseGuard.php` — new, dependency-free guard with a
  denylist **and** an allowlist pattern, failing closed on an unresolved identity.
- `backend/tests/Unit/TestDatabaseGuardTest.php` — pure unit regression tests (no database
  connection).
- `backend/tests/Feature/Stage4bDatabaseGuardWiringTest.php` — proves the guard is wired
  into the shared base class and inherited by every Feature test.

Documentation added: the seven Stage 4B closure reports and the index update.

---

## 6. Verification summary

- Full backend suite (in-memory SQLite): **444 tests, 2208 assertions, 0 failures, 7 errors
  — all 7 are the pre-existing "GD extension is not installed" image-upload tests**, 0
  skipped.
- Stage 4B subset: **79 tests, 510 assertions — OK.**
- Stage 4A regression subset: **26 tests, 154 assertions — OK.**
- Isolated MySQL 8.4.11: **140 tests, 823 assertions — OK**, 49 tables migrated.
- Database guard: all four required protected databases refused on the live 8.4.11
  instance without being touched.
- Browser acceptance: super-admin, independent verifier and low-privilege waiter scenarios
  passed; invoice allocation, over-application rejection and audit attribution passed.
- HMR: `vite-hmr` WebSocket connected and received `{"type":"connected"}` both directly and
  through the public proxy.

---

## 7. Classification

**CONDITIONALLY READY FOR FORMAL OWNER ACCEPTANCE.** See
`STAGE-4B-FINAL-CLOSURE-REPORT.md` §12 for the conditions.
