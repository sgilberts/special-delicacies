# Stage 4B — Final Closure Report

**Document type:** Final closure and readiness report
**Date:** 2026-11-27
**Repository:** `sgilberts/special-delicacies`

---

## 1. Scope of this pass

Authorised work: isolated Stage 4B closure remediation and independent verification. Not
authorised and not performed: merging PR #1 or PR #2, deploying, enabling integrations in
production, starting Stage 4D, or modifying protected databases.

---

## 2. Branch and commits

| Item | Value |
| --- | --- |
| Working branch at start | `stage-4b-remediation` @ `95d6517` (= `main` tip) |
| Move to PR #1 head | fast-forward (additive; `main` is the merge base, 0 commits behind) to `6d29dd7` |
| PR #1 head | `audit-deposits-credit` @ `6d29dd7` (6 commits ahead of `main`) |
| PR #2 head | `refunds-credit-notes` @ `ceb533b` |
| `main` | unchanged at `95d6517` |
| Remediation changes | on top of `6d29dd7`; committed by the platform on turn completion (no manual commit/push) |

No reset, clean, stash, force-push or overwrite was performed. No branch was switched away
from the remediation branch. Both pull requests retain their original head, base and state.

---

## 3. Files changed in this pass

Code / tests (no application behaviour changed):

- `backend/tests/TestCase.php` — corrected fail-closed guard.
- `backend/tests/Support/TestDatabaseGuard.php` — new guard utility.
- `backend/tests/Unit/TestDatabaseGuardTest.php` — new unit regression tests.
- `backend/tests/Feature/Stage4bDatabaseGuardWiringTest.php` — new wiring regression test.

Documentation:

- `docs/STAGE-4B-OUTSTANDING-SCOPE-RECONCILIATION.md`
- `docs/STAGE-4B-INDEPENDENT-SECURITY-REVIEW.md`
- `docs/STAGE-4B-DATABASE-GUARD-REMEDIATION.md`
- `docs/STAGE-4B-MYSQL-8.4.11-VERIFICATION.md`
- `docs/STAGE-4B-BROWSER-ACCEPTANCE-REPORT.md`
- `docs/STAGE-4B-PR-OVERLAP-AND-INTEGRATION-REPORT.md`
- `docs/STAGE-4B-FINAL-CLOSURE-REPORT.md` (this file)
- `docs/README.md` — index update.

---

## 4. Corrective change

The merge-blocking database-safety defect is remediated: the guard now refuses every
protected database, uses a denylist **and** an allowlist pattern, fails closed on an
unresolved identity, and is proven by regression tests and a live refusal test. Details:
`STAGE-4B-DATABASE-GUARD-REMEDIATION.md`.

No application code change was required by the security review.

---

## 5. Independent security findings

| ID | Severity | Summary |
| --- | --- | --- |
| SEC-01 | Low | Staff profile update allows an 8-character password vs a 10-character policy elsewhere |
| SEC-02 | Low / Informational | Registration reveals whether an email/phone is registered (rate-limited) |
| SEC-03 | Low | Raw-string phone uniqueness allows normalized duplicates; recovery fails closed on ambiguity |
| SEC-04 | Low | Theoretical deposit-initiation replay race before `checkout_url` is stored |

No Critical or High findings; no confirmed exploitable defect in identity or account gating.
Full detail: `STAGE-4B-INDEPENDENT-SECURITY-REVIEW.md`.

---

## 6. Database guard evidence

- Unit + wiring regression tests pass (18 unit assertions; wiring asserts every Feature test
  inherits the guard).
- On the isolated MySQL 8.4.11 instance, all four required protected names were refused with
  `Refusing MySQL test run against non-disposable database [...]`, and `SHOW DATABASES`
  confirmed no protected schema was created.
- Positive path: the suites ran against the disposable
  `special_delicacies_guard_probe_test` (140 tests, 823 assertions OK).

---

## 7. MySQL 8.4.11 verification

- Server version: **8.4.11** (dedicated disposable instance; the sandbox dev image is 8.4.13).
- **140 tests, 823 assertions — OK**, 49 tables migrated, including `staff_deposit_records`,
  `payment_transactions`, `payment_allocations`, `customer_credit_ledger_entries`,
  `account_security_challenges` and `payment_receipt_intents`.
- Covers migrations, deposit lifecycle, credit calculations, verification/identity gating,
  Stage 4A regression, guard behaviour, and FK/migration-rollback tests.
- True multi-connection concurrency was not stress-tested; locking code executed under InnoDB
  and idempotency is covered by tests.

---

## 8. Gateway and SMS limitations

### 8.1 Email and SMS

| Aspect | Status |
| --- | --- |
| A. Challenge creation and security logic | **Implemented and verified** (hashing, expiry, attempt limits, cooldown, single use, binding, session invalidation) |
| B. Delivery adapter behaviour | Email `deliverEmail()` **fails closed** when the mailer is `log` (or `array` outside testing); SMS is bound to `UnavailableSmsOtpDelivery`, which throws `OtpDeliveryUnavailable` and yields `503` |
| C. Actual provider delivery evidence | **None.** `MAIL_MAILER=log`; no SMS provider is configured |
| D. Required external configuration | A real mail transport and `MAIL_MAILER`; an SMS provider implementing `SmsOtpDelivery` bound in `AppServiceProvider`; credentials supplied by the owner |

The security lifecycle is verified independently of delivery. Real email/SMS delivery is not
claimed.

### 8.2 Payment gateway

- Zero `payment_gateways` rows; no live merchant integration was exercised and none was
  fabricated.
- **Foundation verified:** initiation validation (intent, amount > 0, payer authorisation,
  enabled gateway with credentials), server-side amount/currency checks (GHS, exact minor-unit
  match), deterministic transaction references, server-side return verification, webhook
  signature verification (Paystack legacy HMAC and timestamped header, Korapay HMAC, Hubtel
  status-API reconciliation), duplicate-webhook idempotency, lifecycle transitions
  (`pending → succeeded` only on verified success), and credit creation only after verified
  success. Provider logic is exercised with `Http::fake` in
  `Stage4bCustomerOnlineDepositTest`.
- **Live merchant integration is not complete:** it requires real merchant credentials,
  webhook endpoint registration with the provider, and an end-to-end provider test in a
  merchant sandbox. This is an external production dependency, not a Stage 4B foundation
  deliverable.

---

## 9. PR #1 / PR #2 overlap

Nine files overlap. The mandatory `TestCase` guard is reconciled. `GatewayPaymentService` and
`routes/api.php` changes are additive and compatible; a merge-then-rebase strategy is
documented. The `CustomerCreditLedgerEntry` `booted()` duplication is a merge hazard to
resolve. No pull request was merged and no Stage 4C code was imported into Stage 4B. Details:
`STAGE-4B-PR-OVERLAP-AND-INTEGRATION-REPORT.md`.

---

## 10. Test totals

| Suite | Result |
| --- | --- |
| Full backend suite (in-memory SQLite) | **444 tests, 2208 assertions, 0 failures, 7 errors, 0 skipped** |
| — the 7 errors | All `GD extension is not installed` image-upload tests (pre-existing environment limitation, unrelated to Stage 4B) |
| Stage 4B subset | **79 tests, 510 assertions — OK** |
| Stage 4A regression subset | **26 tests, 154 assertions — OK** |
| MySQL 8.4.11 targeted suite | **140 tests, 823 assertions — OK** |
| Backend lint (Pint) | New/changed files pass; repo-wide 143 pre-existing style findings |
| Frontend lint (oxlint) | 55 warnings, 0 errors (pre-existing) |
| Frontend type-check (tsc) | Exit 0 |
| Production build | Exit 0 |

No test was skipped, weakened or hidden. The 7 GD errors are identified separately as a
pre-existing dev-image limitation.

---

## 11. Git working-tree state

- Branch: `stage-4b-remediation`, based on `6d29dd7`.
- Modified: `backend/tests/TestCase.php`.
- New: `backend/tests/Support/TestDatabaseGuard.php`,
  `backend/tests/Unit/TestDatabaseGuardTest.php`,
  `backend/tests/Feature/Stage4bDatabaseGuardWiringTest.php`.
- New documentation: the seven Stage 4B closure reports; `docs/README.md` updated.
- No destructive Git operation; `main` and both PR branches untouched.

---

## 12. Remaining blockers and conditions

No unresolved **security defect**, **database-safety defect**, **broken financial invariant**
or **required browser-acceptance failure** remains on the remediation branch.

The classification is conditional on:

1. **Owner formal classification** of the SMS transport and the live merchant gateway as
   external production dependencies (the foundation is complete; live delivery is not).
2. **Merging the guard remediation into the Stage 4B head.** PR #1 as it stands still carries
   the weak guard; the corrected guard exists on this remediation branch and must reach the
   Stage 4B head before formal acceptance.
3. **PR #2 integration** at merge time per the documented strategy (no merge performed here).
4. **Tracking the four low-severity hardening items** (SEC-01–SEC-04) for a follow-up.
5. **The 7 GD test errors** remain an environment-only limitation of the dev image; they are
   not a Stage 4B blocker.

---

## 13. Classification

**CONDITIONALLY READY FOR FORMAL OWNER ACCEPTANCE.**

The Stage 4B foundation is implemented, independently reviewed, and verified on MySQL 8.4.11,
in the browser, and under HMR, with the merge-blocking database guard remediated. The
remaining conditions are external production dependencies, a governance step to carry the
guard into the Stage 4B head, and cross-PR merge sequencing.

---

## 14. Recommended next action

Owner reviews this report and the security review, formally accepts the external
dependencies, then carries the guard remediation into PR #1 and executes the documented PR #2
integration. Do not merge PR #2, deploy, or begin Stage 4C/4D formal acceptance until that
review is complete.
