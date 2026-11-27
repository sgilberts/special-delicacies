# Stage 4C — Refunds, Credit Notes and Customer Credit Reversals: Implementation and Independent Acceptance Report

**Status:** Final Stage 4C closure exercise complete — remaining technical risks closed or documented, owner decisions prepared.
**Classification:** **READY FOR OWNER ACCEPTANCE** — every preventable financial risk in scope is closed or bounded by a documented, disabled-by-default control; what remains is business-policy approval (D1–D13, including the D10 linkage choice) and external provider merchant-capability verification.
**Report date:** 2026-11-27 (supersedes the earlier CONDITIONALLY READY revision)
**Design reference:** `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md`
**Initial discovery reference:** `docs/STAGE-4C-INITIAL-REPORT.md`
**Owner decision register:** `docs/STAGE-4C-OWNER-DECISION-REGISTER.md`

---

## 1. Repository, branch and pull request

| Item | Verified value |
|---|---|
| Repository | `https://github.com/sgilberts/special-delicacies.git` |
| Branch | `refunds-credit-notes` |
| Closure-exercise base commit | `9017b9a6a49d119526927f645e2709f7113b6cdc` — *Fix credit reversal logic and resolve multiple payment processing defects* |
| Base | `origin/main` at `95d651721aa17cbfaa52cc27209c4858d0970518` |
| Implementation commit (HEAD at audit start) | `ceed566875e9db5271908c932502e32b9dea6be9` — *Implement Stage 4C refund, credit note, and reversal workflows* |
| Working tree at audit start | Clean |
| PR #2 | **OPEN**, head `refunds-credit-notes`, base `main`, updated by this exercise (43 files, +5932/−35 against the base); not merged |
| PR #1 | **OPEN** and untouched; not merged |
| Deployment | None performed |
| Sandbox database | MySQL **8.4.13**, `special_delicacies_db` |

**Branch reconciliation.** The design named `stage-4c-refunds-credit-notes`; the platform session branch is
`refunds-credit-notes`, based on `main` at `95d6517`. `main` already contains the Stage 4A allocation
foundation and the Stage 4B credit/deposit foundation, so no Stage 4C dependency was omitted. The design and
initial discovery documents are preserved unmodified in `docs/`. PR #1's head is not an ancestor of this
branch, but the Stage 4A/4B schema and services that Stage 4C depends on are present on `main` and were
re-verified by the Stage 4A/4B regression suites below.

**This closure exercise** changes 16 files (14 modified, 2 new) on top of `9017b9a` and is pushed to the same
branch by the platform at the end of the turn; it updates PR #2 rather than opening a new PR. No reset, clean,
stash, discard, force-push, merge or deploy was performed, and PR #1 was not touched.

---

## 2. Preservation checks

| Check | Result |
|---|---|
| Stage 4A allocation tables/services (`payment_allocation_*`, `PaymentAllocationService`) | Present; regression suite green |
| Stage 4B credit/deposit tables/services (`customer_credit_ledger_entries`, `payment_receipt_intents`, `staff_deposit_records`) | Present; regression suite green |
| Stage 4C design document | Present, unmodified (570 lines) |
| Stage 4C initial discovery report | Present, unmodified (306 lines) |
| Existing user commits | Preserved; only additive changes made |
| PR #1 / PR #2 | Open; neither merged |

---

## 3. Database safety audit

### 3.1 Mechanisms inspected

- `backend/tests/TestCase.php`
- `backend/phpunit.xml`
- Compose database environment (`DB_CONNECTION`, `DB_DATABASE`, `DB_HOST`, `DB_URL`)
- `backend/config/database.php` (default connection and URL handling)
- All Stage 4C migrations and the `RoleAndPermissionSeeder`
- Every test helper that writes financial state (services are invoked through the app container; no helper
  connects to a second database)

### 3.2 Controls verified

1. **Environment forcing before boot.** `createApplication()` writes `APP_ENV=testing`,
   `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=''` (plus mail/queue/cache/session/broadcast
   overrides) into `$_ENV`, `$_SERVER` and `putenv()` **before** the framework boots, because PHPUnit does not
   override variables already exported by the container.
2. **Post-boot identity assertion.** `assertSafeTestDatabase()` reads the *resolved* `config('database.default')`
   and its database. It accepts only `sqlite` + `:memory:`, or `mysql` + a database matching
   `^special_delicacies_[a-z0-9_]+_test$`. Anything else throws `RuntimeException` before any test body or
   migration runs.
3. **Protected names.** `special_delicacies`, `special_delicacies_db`, `special_delicacies_stage4a_test`,
   `special_delicacies_stage4a_acceptance_test` and `special_delicacies_stage4b_test` are refused even though
   some match the disposable pattern.
4. **MySQL is opt-in.** `BASE44_MYSQL_TEST_DATABASE` must be set to a disposable name; an invalid name throws
   during application creation, before `RefreshDatabase` can call `migrate:fresh`.
5. **Other connections.** In SQLite runs the container's `mysql` connection is configured with the forced
   `DB_DATABASE=:memory:` value, so a stray `DB::connection('mysql')` cannot reach a development database. In
   MySQL runs both connections point at the disposable test database.

### 3.3 Independent refusal evidence

```
$ BASE44_MYSQL_TEST_DATABASE=special_delicacies_db php vendor/bin/phpunit --filter=test_stage4c_permissions_are_seeded
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_db].
ERRORS!

$ BASE44_MYSQL_TEST_DATABASE=special_delicacies_prod php vendor/bin/phpunit --filter=test_stage4c_permissions_are_seeded
RuntimeException: Refusing MySQL test run against non-disposable database [special_delicacies_prod].
ERRORS!
```

### 3.4 Development database integrity

The disposable MySQL database `special_delicacies_stage4c_test` was dropped and recreated from scratch for
this audit. After the complete SQLite suite, both MySQL suites and the browser exercise:

| Development database fact | Value |
|---|---|
| `users` / `roles` | 4 / 5 (unchanged) |
| `payment_transactions` | 0 |
| `customer_credit_ledger_entries` | 0 |
| `refund_requests` | 0 |
| `credit_reversal_requests` | 0 |
| `commercial_documents` | 0 |
| `admin_audit_logs` with `stage4c.*` | 0 |
| Applied migrations | 48, including all five Stage 4C migrations |
| MySQL CHECK constraints | `credit_ledger_single_payer` retained; `credit_ledger_entry_shape` replaced with the extended shape |

No test or verification step wrote to the development database except the disposable fixtures created for
browser acceptance, which were removed at the end of the run (all counts above returned to baseline).

**Verdict:** the guard is effective and fail-fast. The earlier baseline-reset incident cannot recur through
`phpunit` with the current configuration.

---

## 4. Financial correctness findings

### 4.1 Defect found and fixed: reversed credit never returned to the receipt

`PaymentAllocationService::creditDesignatedMinorForPayment()` summed every `designation` entry and ignored
`reversal` entries. After an authorised reversal of designated credit, the receipt still appeared fully
designated: the funds were neither customer credit nor refundable/allocatable, and the receipt was permanently
stranded.

- **Reproduction:** new regression test `test_reversing_designated_credit_releases_the_receipt_funds` failed
  with `availableMinorForPayment` = `0.00` and `refundableMinor` = `0.00` after a full reversal (expected
  `100.00`).
- **Fix:** the helper now subtracts reversals of that receipt's designations; the matching eligibility query in
  `CustomerCreditService::eligibleReceipts()` nets the same reversals so the staff list agrees with the
  authoritative check.
- **Re-verification:** the regression test passes; the full suite and both MySQL suites pass.

### 4.2 Dual-write residual risk — resolved in this exercise

The previous revision held the provider call inside the refund-row transaction. That prevented two *concurrent*
submissions, but it could not survive the dual-write failure: if the provider accepted the refund and the
database commit then failed, the row rolled back to `approved`, and a retry would instruct the provider a second
time. Paystack's refund endpoint also exposes no generic idempotency header, so disabling transaction retries was
never a complete answer.

**What changed:**

1. **Provider idempotency key.** Paystack's `POST /refund` accepts an optional `reference` (“Unique reference for
   the refund… Paystack generates one if you leave it out”). The application now derives a deterministic key from
   the refund reference (`sdr` + SHA-256 prefix, `RefundService::providerIdempotencyKey()`) and sends it as that
   `reference`. The same key is sent on any retry, so the provider cannot create a second refund, and the same key
   can be used to look the refund up again (`GET /refund?reference=…`).
2. **Two-phase submission.** `submit()` now commits a durable `submitting` intent — with the idempotency key —
   **before** the provider call, performs the provider call outside any database transaction, and then persists
   the result. A failure to persist the result leaves the refund in `submitting`, not `approved`.
3. **No automatic resubmission.** `submit()` refuses a refund in `submitting` with “Reconcile it before
   submitting again.” The unresolved funds stay reserved (`refundableMinor()` counts `submitting`).
4. **Outcome classification.** A definitive provider refusal (4xx business error) returns the refund to
   `approved`, because no money moved. A timeout, connection failure, 5xx, duplicate-reference conflict (409), or
   a mismatched returned reference is an **unknown** outcome and keeps the refund in `submitting`.
5. **Reconciliation.** `POST /admin/financials/refunds/{id}/reconcile` looks the refund up by the stable
   reference. If found with matching amount and currency it records the provider reference and moves the refund
   to `submitted` (confirmation remains a separate, independent step). If the provider is reachable and has no
   such refund, the operator must explicitly pass `confirm_absent` before it returns to `approved`. If the lookup
   itself fails, the refund stays unresolved.
6. **Confirmation guard.** A provider-mode refund without a recorded provider reference cannot be confirmed.

Every step writes a `refund_events` row (`submitting`, `submitted`, `reconciled`, `reconciled_absent`,
`reconciliation_mismatch`, `provider_error`) plus an `admin_audit_logs` entry, so the whole history is auditable.

**Residual, unavoidable dependency:** the provider lookup must be reachable and must honour the refund
`reference`. If Paystack changes that behaviour, the refund stays unresolved and requires manual reconciliation —
it is never auto-confirmed and never auto-resubmitted. Provider submission remains disabled by default (D12).

### 4.3 Fix: exact provider minor units

`GatewayPaymentService::initiateRefund()` used a float conversion (`(float) $amount * 100`); it now uses
`PaymentAllocationService::toMinorUnits()` like the rest of the financial code.

### 4.4 Refunds — verified behaviours

| Requirement | Evidence |
|---|---|
| Refund ≤ genuinely refundable funds | `refundableMinor` = receipt − active allocations − **net** designations − live refunds; over-refund test rejects |
| Allocated/designated funds not refundable | Allocation and designation tests reject with a refundable-balance conflict |
| Duplicate references | Unique `refund_reference` + identical-replay return + conflicting-payload rejection |
| Provider verification authoritative | `confirm()` requires provider `processed` + exact amount + exact currency; mismatch test rejects |
| Failed/pending/unverified cannot confirm | Provider outage stays `approved`; pending/mismatch not confirmed; browser return is never accepted |
| Approval/initiation separation | Initiator cannot approve or confirm (service + HTTP tests) |
| Receipt/allocation untouched | `payment_transactions.status` remains `succeeded` (D12a); no allocation mutation |
| Provider execution disabled by default | `stage4c.refunds.provider_submission_enabled=false`; env var absent in the container; runtime config verified |

### 4.5 Credit notes — verified behaviours

| Requirement | Evidence |
|---|---|
| Invoice immutable | No invoice total/status/line write; histories are append-only rows |
| Own document identity | `document_type=credit_note`, own number `CN-YYYY-########`, own lines and history |
| Issue/approval permissions | `credit_notes.create` vs `credit_notes.approve` enforced by middleware; creator ≠ issuer in service |
| Duplicate issuance | Second `issue()` returns the issued note; unique `cn:<number>:credit` ledger reference |
| Amount cap | Remaining creditable re-checked under payer + invoice + note locks at issue |
| Void after consumption | Blocked when remaining reversible < credit amount |
| No double-count | One `credit_note_credit` entry plus at most one `application` entry; invoice outstanding reduced once |

### 4.6 Credit reversals — verified behaviours

| Requirement | Evidence |
|---|---|
| Append-only | Model throws on update/delete; original entry unchanged in tests |
| No re-use of reversed credit | Reversals reduce each entry's available amount before FIFO application attribution |
| Consumed credit not reversible | Unit tests plus a live browser exercise: approval returned 409 “no longer reversible” after the credit note consumed the designation |
| FIFO attribution | Deterministic chronological attribution across credit entries; over-reversal rejected |
| Concurrency | Approval locks payer + entry and recomputes remaining; second pending request fails once the first is approved |
| Duplicate references | Unique `operation_reference` + identical-replay handling |
| Independent authorisation | Initiator cannot approve; authorizer snapshot recorded |

### 4.7 Open financial findings (documented, safely restricted)

1. **Credit-note credit reversal is not linked back to the credit note.** Reversing the unapplied portion of a
   credit note leaves the note `issued` at its full amount while the credit balance is reduced, and the note can
   no longer be voided (void requires the full credit to be unused). No over-credit or double-spend is possible
   (the invoice's remaining creditable amount stays conservatively reduced), but reporting is inconsistent.
   The design's D10 required the note to be voided or a linked adjustment to be recorded; that sub-decision
   needs the owner. Until then the operation remains restricted to super-admin with independent approval.
2. **Pending reversal requests do not reserve credit.** A request can become unapprovable if the credit is
   consumed meanwhile; the approver receives a clear 409 and no ledger entry is posted. This is safe but leaves
   stale `requested` rows (observed live). Cancellation/expiry is a product decision, not a financial risk.
3. **The extended CHECK constraint does not enforce same-payer/currency for reversals.** The service does
   (the entry is read from the original and copied); the constraint only requires a positive amount and
   `reverses_entry_id`. This is weaker than the design text but not exploitable through the API.

---

### 4.8 Dual-write failure-scenario matrix

The table below is the behaviour of the shipped code, exercised by `Stage4cRefundTest` where a scenario can be
simulated (provider refusal, timeout, unknown outcome, reconciliation found/absent/mismatch, duplicate submit).

| Scenario | Provider state | Database state | Another submission permitted? | Recovery procedure | Audit record |
|---|---|---|---|---|---|
| **Provider rejects the request** (definitive 4xx / business error) | No refund created | `approved`, `metadata.provider_outcome = rejected` | **Yes**, after correcting the cause | Operator fixes the request and submits again; the same idempotency key is reused | `submitting` → `provider_error` (to `approved`) |
| **Provider accepts the request** | Refund pending/processing with provider id | `submitted` + `provider_refund_reference` | **No** — `submit()` returns the existing record | Independent confirm after provider verification (`processed`, exact amount and currency) | `submitting` → `submitted` (+ `confirmed`) |
| **Provider times out** | Unknown — may or may not have accepted | `submitting`, `provider_outcome = unknown`; funds reserved | **No** — refused until reconciled | Reconcile by reference: found ⇒ `submitted`; absent (operator-confirmed) ⇒ `approved` | `submitting` → `provider_error` → `reconciled` / `reconciled_absent` |
| **Application loses the connection** | Unknown (same class as timeout) | `submitting` | **No** | Same as timeout | Same as timeout |
| **Provider accepts, database write fails** (result write or commit) | Refund exists at provider | `submitting` — the intent was committed first, so the outcome is durably unknown | **No** | Reconcile: the lookup finds the refund and records it; if the database is still failing it stays `submitting` and needs operator investigation | `submitting` (+ `provider_error` when the write failure is caught) |
| **Duplicate submission** (double click, two operators, retry) | At most one refund — the row lock serialises the first transition and the provider `reference` is idempotent | One row in `submitting`/`submitted` | **No** — second caller sees `submitting`/`submitted` | None needed | One `submitting` event (verified: a double-clicked submit produced exactly one) |
| **Verification response unavailable** | Refund exists, status unknown | `submitted` (provider accepted at submission) | **No** | Retry `confirm()` later; the refund is never auto-confirmed | `submitted`; failed confirmation raises a conflict, no state change |
| **Provider reports an unknown reference** (verify/list 404) | No refund for that reference | `submitting` (if from reconcile) or `submitted` (if from confirm) | **No** | Manual investigation with the provider; if the operator confirms no refund exists, `reconcile` with `confirm_absent` returns it to `approved` | `provider_error` / `reconciled_absent` |
| **Provider returns a different refund reference** | Ambiguous | `submitting` | **No** | Reconcile by the original reference | `provider_error` (unknown) |
| **Manual payout path** (provider disabled, cash/bank) | Not applicable | `submitted` (`mode = manual`) | **No** | Independent confirmation with payment reference and recorded evidence | `submitted` → `confirmed` |

**Guarantees that follow from the matrix:**

- A refund is never marked `failed`, `confirmed` or `rejected` while its provider outcome is unknown; `fail()`
  only accepts `approved`/`submitted`, and `submitting` is excluded.
- Funds are never released while the outcome is unknown: `submitting` counts against the refundable balance.
- The provider is never instructed twice for the same refund reference: the row lock serialises local
  submissions and the provider-side `reference` is idempotent across retries.
- Recovery is always an explicit, audited operator action — never an automatic retry loop.

### 4.9 Credit-reversal correction — independent verification

The earlier remediation made `creditDesignatedMinorForPayment()` subtract reversals of a receipt's
designations. That correction was re-verified independently, and six additional regression tests were added to
`Stage4cCreditReversalTest`.

| Invariant | Evidence |
|---|---|
| Original credit entry unchanged | `test_valid_reversal_preserves_original_entry_and_reduces_derived_balance` asserts the designation row keeps its type and amount after a full reversal |
| Reversal appended, never a mutation | Model events reject update/delete; the reversal is a new row with `reverses_entry_id` |
| Receipt funds recalculated | `test_partial_reversal_releases_only_the_reversed_portion_of_receipt_funds`: 100 designated, 40 reversed ⇒ credit 60, receipt available 40, refundable 40 |
| No double availability | `test_reversed_funds_cannot_remain_available_as_both_credit_and_receipt_funds`: after a full reversal credit is 0 and receipt funds are 100; allocating them makes refundable 0 |
| Existing allocations respected | `test_reversal_respects_existing_allocations`: 40 allocated + 60 designated; reversing the 60 releases 60, the 40 allocation stays |
| Existing designations respected | Net-designation calculation subtracts only reversals of *that receipt's* designations |
| Partial reversals | Same partial test above |
| Multiple reversals | `test_multiple_reversals_accumulate_and_cannot_over_reverse`: 30 + 30 ⇒ 60 released, a third request for 50 is rejected |
| Concurrent reversals cannot over-reverse | `test_concurrent_reversal_requests_cannot_exceed_remaining_credit` (payer + entry locks, remaining re-checked at approval) |
| Duplicate references cannot double-restore | `test_duplicate_reversal_reference_cannot_restore_funds_twice`: identical replay returns the same request, a second approval creates no second ledger entry |
| Correct after later applications | `test_receipt_funds_stay_correct_after_application_and_a_later_reversal`: 60 applied, 40 reversed ⇒ credit 0, receipt 40, invoice outstanding 0 |

All eleven checks pass on SQLite and the reversal suite passes on MySQL 8.4.13.

## 5. Defects found and remediated

| # | Severity | Finding | Remediation | Status |
|---|---|---|---|---|
| 1 | **High** | Reversing designated credit did not release the receipt's funds; `availableMinorForPayment`/`refundableMinor` stayed at zero | Net reversals in `creditDesignatedMinorForPayment()` and the eligible-receipts query; regression test added | Fixed, re-verified on SQLite + MySQL |
| 2 | **Medium** | Concurrent provider submissions could double-instruct the provider | Provider call moved inside the refund row lock; dedicated retryable failure path; existing outage test still green | Fixed, re-verified |
| 3 | **Low** | Float conversion for provider refund minor units | Use `toMinorUnits()` | Fixed |
| 4 | **Low** | Two tests hard-coded `https://special-delicacies.local`, failing whenever the container sets the preview URL | Tests now pin `config(['cors.allowed_origins'])` / `config(['app.frontend_url'])` | Fixed; suite is green with no environment overrides |
| 5 | **Low (UI)** | Permission-denied page's “Return to dashboard” CTA rendered copper text on a copper background (computed colour == background), so its label was invisible | Replaced the ad-hoc classes with the shared `btn-accent` style (dark primary + off-white text) | Fixed; computed colour `rgb(250,247,242)` on `rgb(48,32,25)`, confirmed visually |
| 6 | **High** | Dual-write residual risk: a provider-accepted refund whose database write failed could be resubmitted | Two-phase submission with a durable `submitting` intent, Paystack refund `reference` as a stable idempotency key, reconciliation endpoint, no automatic resubmission, funds reserved | Fixed; `Stage4cRefundTest` covers unknown outcome, intent ordering, definitive rejection and reconciliation (found/absent/mismatch) |
| 7 | **Medium (test gap)** | Approval confirmations used browser-native dialogs, so the confirmation step could not be verified automatically | Switched the three Stage 4C pages to the app's `ConfirmProvider`, added a `usePrompt` variant, added the Reconcile action | Fixed; dialogs driven end to end in the running app (§9.1) |

No other defect was found in the audited scope.

---

## 6. D1–D13 decision matrix

“Enforcement” names the code path that actually blocks or permits the operation, not the UI.

**Technical implementation is not business-policy approval.** The full register — original decision, exact
recommended default, implemented behaviour, configuration key, consequences, approval status and current
restriction — is in `docs/STAGE-4C-OWNER-DECISION-REGISTER.md`. The table below is the summary; the register is
authoritative.

| # | Recommended policy | Implemented policy | Config key | Enforcement location | Owner approval still required | Operation safely restricted |
|---|---|---|---|---|---|---|
| D1 | Refund initiation by `financials.refunds.initiate`, super-admin until assigned | Same | `financials.refunds.initiate` (permission row) | `routes/api.php` middleware; migration `2026_11_27_000005` | **Yes** (assign roles) | Yes — super-admin only by default |
| D2 | Refund approval by `financials.refunds.approve`, super-admin | Same | `financials.refunds.approve` | Route middleware; `RefundService::approve/submit/confirm` | **Yes** | Yes |
| D3 | Initiator ≠ approver | Enforced | `stage4c.separation_of_duties` | `Stage4cPolicy::separationOfDutiesRequired`; `RefundService::approve/confirm` | **Yes** (confirm) | Yes — default `true` |
| D4 | Partial refunds allowed up to uncommitted funds | Enforced | `stage4c.refunds.partial_allowed` | `Stage4cPolicy::partialRefundsAllowed`; `RefundService::create` | **Yes** | Yes — amount bounded by refundable |
| D5 | Allocated funds must be released before refund | Enforced | — (no toggle) | `RefundService::refundableMinor` subtracts active allocations and net designations | **Yes** | Yes |
| D6 | No refund to a different method/payer | Structurally enforced | `stage4c.refunds.allow_different_method` | No destination method/payer input exists; refund copies the payment payer/currency; provider call uses the original transaction reference | **Yes** | Yes — the toggle is informational because there is nothing to allow |
| D7 | Only issued, non-voided invoices | Enforced | — | `CreditNoteService::assertEligibleInvoice` | **Yes** | Yes |
| D8 | Creator ≠ issuer; super-admin | Enforced | `stage4c.credit_notes.require_independent_approval` | `Stage4cPolicy::creditNoteSeparationRequired`; `CreditNoteService::issue/void` | **Yes** | Yes — default `true` |
| D9 | Partial credit notes up to remaining creditable | Enforced | `stage4c.credit_notes.partial_allowed` | `Stage4cPolicy::partialCreditNotesAllowed`; `CreditNoteService::create` | **Yes** | Yes |
| D10 | Reversal needs an independent authorizer | Independent authorizer enforced; credit-note linkage sub-decision **open** (register §3, report §4.7.1) | `stage4c.credit_reversals.require_independent_approval` | `Stage4cPolicy::reversalSeparationRequired`; `CustomerCreditReversalService::approve` | **Yes** — two parts: authorisation + linkage choice | Partially — super-admin only; no double-spend, but reporting linkage needs the owner's choice |
| D11 | Refund the receipt amount; credit note mirrors invoice line totals | Enforced | — | `RefundService::create` (receipt amount); `CreditNoteService::totalsFromLines` (line snapshots) | **Yes** | Yes |
| D12 | Provider unavailable: stay pending, record error, retry idempotently, never auto-confirm | Enforced and extended: unknown outcomes are held in `submitting` with a provider idempotency reference and resolved only by reconciliation | `stage4c.refunds.provider_submission_enabled` | `Stage4cPolicy::providerRefundsEnabled`; `RefundService::submit/reconcile`; `ProviderRefundUnavailable` / `ProviderRefundRejected` | **Yes** (enable provider submission) | Yes — default `false`, env var absent |
| D12a | Keep the receipt `succeeded`; report refunds separately | Enforced | `stage4c.refunds.mark_transaction_refunded` | `Stage4cPolicy::markTransactionRefunded`; `RefundService::confirm` | **Yes** | Yes — default `false` |
| D13 | Reverse only unconsumed credit (FIFO) | Enforced | `stage4c.credit_reversals.consumption_method` = `fifo` | `CustomerCreditReversalService::remainingReversibleMinor` | **Yes** | Yes — consumed credit rejected |

**Runtime configuration evidence (api container):**

```json
{"provider_refunds":false,"mark_refunded":false,"separation":true}
```

`STAGE4C_PROVIDER_REFUNDS_ENABLED` is not set, so the default `false` applies. No risky financial operation is
active without an explicit owner configuration change.

---

## 7. Permissions and security

Verified against the backend, not the UI:

| Scenario | Result |
|---|---|
| Super-admin reaches all six read endpoints | **Passing** (HTTP 200) |
| Finance user with explicit `*.view` only | **Passing** — reads 200; refund/credit-note/reversal mutations 403 |
| Finance user with `*.initiate` / `*.create` only | **Passing** — cannot approve/issue/void/confirm/reject (403) |
| Ordinary `admin` role | **Passing** — 403 on every Stage 4C read and write route |
| Customer token | **Passing** — 403 on every Stage 4C admin route (staff middleware) |
| Initiator vs approver | **Passing** — service-level separation with distinct messages; HTTP approval by the initiator is also blocked by permission separation in the tests |
| Hiding a button as the only control | Not relied on: every mutation is middleware-protected and service-validated |
| Cross-customer / cross-organisation | Staff Stage 4C endpoints are deliberately global (finance-wide). Customer-facing credit views remain scoped in Stage 4B and are not reachable through Stage 4C routes. Customer tokens cannot read another payer's data because they cannot reach these routes at all. |

**Permission-denied UI:** signing in as the seeded waiter (no `financials.*`) hides the Finance navigation and
renders “403 — Access denied” on `/admin/refunds`; the CTA is now legible (§5 defect 5).

**Audit trail:** every transition writes `refund_events`, `commercial_document_histories` or an append-only
ledger entry, plus `admin_audit_logs` via `AuditService`. Actor snapshots survive user deletion. Provider
credentials and raw provider responses are not exposed in API responses.

---

## 8. Automated test evidence

### 8.1 SQLite (in-memory) — complete suite

```
docker compose ... exec -T api php vendor/bin/phpunit
OK (422 tests, 2057 assertions)
```

0 failures, 0 errors, 0 skipped. `phpunit.xml` declares in-memory SQLite; no environment overrides were used.
The two previously failing URL tests now pass as part of the normal run.

### 8.2 MySQL 8.4.13 — disposable database

`special_delicacies_stage4c_closure_test` was dropped and recreated before the run, and dropped afterwards.

```
BASE44_MYSQL_TEST_DATABASE=special_delicacies_stage4c_closure_test php vendor/bin/phpunit --filter="Stage4c"
OK (46 tests, 214 assertions)

BASE44_MYSQL_TEST_DATABASE=special_delicacies_stage4c_test php vendor/bin/phpunit \
  --filter="PaymentAllocationTest|Stage4bCreditAndSecurityTest|CommercialDocumentsTest|FinancialManagementTest"
OK (37 tests, 231 assertions)
```

The MySQL run exercises the replaced `credit_ledger_entry_shape` CHECK and the additive migrations against the
target engine.

### 8.3 Database guard

- `special_delicacies_db` → refused before execution.
- `special_delicacies_prod` → refused before execution.
- Default SQLite run → `:memory:` only.

### 8.4 Frontend

| Check | Result |
|---|---|
| `npm run lint` | **0 errors**, 56 warnings (React Compiler advisories consistent with existing pages; the one new warning is the `only-export-components` advisory for the added `usePrompt` hook) |
| `npm run build` (`tsc -b` + Vite + postbuild) | **Success** |
| `npm run test:rbac` | **7 tests, 7 pass, 0 fail** |

### 8.5 Stage 4C test inventory

`Stage4cRefundTest`, `Stage4cCreditNoteTest`, `Stage4cCreditReversalTest`, `Stage4cPermissionTest` — 46 tests
on MySQL, including the dual-write scenarios (unknown outcome, intent ordering, definitive rejection,
reconciliation found/absent/mismatch), the six added reversal regressions and the permission/security tests.

---

## 9. Responsive browser acceptance

Disposable fixtures (customer, two receipts, one invoice, a designation, a refund request, a draft credit note
and a reversal request — all prefixed `STAGE4C-AUDIT`) were created, exercised and then deleted. Final counts
returned to baseline.

| Check | Desktop 1440 | Tablet 768 | Mobile 375 (platform preset) |
|---|---|---|---|
| Refunds page renders, data row, actions | **Passing** — requested → approved → submitted → confirmed, action set shrinks to “View” | — | **Passing** — no document overflow |
| Credit notes page renders, draft → issued | **Passing** — `CN-2026-00000035`, “issued”, “Void” action | — | **Passing** — no document overflow |
| Credit reversals page renders, pending request | **Passing** — `STAGE4C-AUDIT-REV-1`, Approve/Reject | **Passing** — row present, no document overflow | **Passing** — row present, no document overflow |
| Navigation | Finance links present for super-admin | — | Sidebar collapses to the menu button |
| Tables | Readable, full columns | Horizontal scroll inside the card | Horizontal scroll inside the card; page itself does not overflow |
| Client-side validation | “Select an eligible payment first.” shown | — | — |
| Loading state | “Verifying administrative access…” then content | — | — |
| Error state | 409 messages surfaced for rejected transitions (observed live: “The credit is no longer reversible…”) | — | — |
| Permission denied | “403 — Access denied”, Finance nav hidden (waiter), CTA legible after fix | — | — |
| Themes | Light verified; Dark verified (`data-resolved-theme=dark`, body `rgb(25,21,18)`); System verified (`data-theme=system`, resolves light) | — | — |
| Horizontal overflow | None (`scrollWidth <= clientWidth + 2` on every page) | None | None |

### 9.1 Approval confirmation — gap closed and verified

The previous revision could not verify the confirmation step because the three financial pages used
browser-native `window.confirm`/`window.prompt`, which the automation browser blocks. The pages now use the
application's own `ConfirmProvider` dialog (the component already used elsewhere in the admin), extended with a
`usePrompt` variant for reasons, references and evidence. The dialog is part of the app, so the whole flow —
including cancellation, duplicate clicks and the busy state — is drivable and was driven end to end.

Each confirmation names the action, the amount and the reference:

| Flow | Confirmation text shown to the operator | Result |
|---|---|---|
| Request a refund | “Request a refund of GHS 20.00 for payment STAGE4C-CLOSURE-PAY-1? No money moves until it is approved, submitted and confirmed.” | **Cancel** closed the dialog and created no row (row count unchanged) |
| Refund approval | “Approve GHS 10.00 · stage4c-closure-refund-1? A different staff member must have requested it.” | **Confirm** recorded the approval; status `requested → approved` |
| Refund submission | “Submit GHS 10.00 · stage4c-closure-refund-1? A provider refund is sent to the payment provider; a manual payout needs independent confirmation with evidence.” | **Confirm** moved the refund to `submitted`; the action buttons were observed **disabled** while the request was in flight; a second click on the already-closed dialog's control was a no-op and produced **exactly one** `submitted` event |
| Credit-note issuance | “Issue credit note CN-2026-00000037 for GHS 50.00 to STAGE4C-CLOSURE Customer? This creates customer credit and may apply it to invoice STAGE4C-CLOSURE-INV-1.” | Confirmed ⇒ status `issued` |
| Credit-reversal approval | “Approve the reversal of GHS 40.00 for STAGE4C-CLOSURE Customer (stage4c-closure-reversal-1)? A new compensating ledger entry will be posted and the credit will no longer be spendable.” | Confirmed ⇒ status `approved`, ledger entry posted |
| Refund reconciliation | “Ask the provider whether refund stage4c-closure-reconcile-1 (GHS 5.00) was accepted? No new refund is issued.” | Confirmed ⇒ the unresolved refund stayed `submitting` and the UI showed “The provider could not be checked. The refund remains unresolved; do not resubmit it.” |

Evidence: the dialog text was read from the live DOM for every action, each state was captured, the
database was read afterwards, and a double click on the confirm control produced exactly one `submitted`
event (no duplicate posting). Duplicate protection is also enforced server-side (row lock plus idempotent
status transitions), so a stale click cannot double-post even if it reaches the API.

**Mobile width note:** the platform's mobile preset renders at 375 px, not the requested 390 px. Tablet is
exactly 768 px and desktop 1440 px.

**Observed, pre-existing:** the preview tool reported repeated `GET /api/v1/content` requests on the admin
pages (about 11 in 4 seconds). This is unrelated to the Stage 4C pages and was not introduced or changed by this
exercise; it is recorded here so it is not mistaken for a Stage 4C defect.

---

## 10. Provider capability status

| Layer | Status |
|---|---|
| Code implementation | Paystack `POST /refund` and `GET /refund/{id}` implemented in `GatewayPaymentService`; Hubtel and Korapay have **no** refund endpoint and use the manual evidence path |
| Simulated provider tests | **Passing** — `Http::fake` covers pending → processed confirmation and amount/currency mismatch rejection |
| Sandbox provider tests | **Not performed** — no sandbox merchant refund was executed |
| Live merchant verification | **Not performed** — no live credentials used, no real refund executed |
| Runtime state | Provider submission **disabled** (`stage4c.refunds.provider_submission_enabled=false`, env var absent) |

No claim is made that Paystack, Hubtel or Korapay refunds are operational. Manual refunds require independent
evidence and a different staff member; the receipt remains `succeeded`.

---

## 11. Remaining dependencies and owner decisions

1. **Approve D1–D13** using `docs/STAGE-4C-OWNER-DECISION-REGISTER.md`, in particular:
   - **D10** — the authorisation rule (recommended: independent authorizer) **and** the credit-note linkage
     choice (void-first / linked adjustment / no linkage). Until the choice is made, credit-note-derived
     reversals stay super-admin only and the reporting inconsistency in §4.7.1 remains.
   - **D1/D2/D8** — assignment of mutation permissions to finance roles (currently super-admin only).
2. **Confirm merchant refund capability** for Paystack (and whether Hubtel/Korapay support refunds at all),
   then decide whether to enable `STAGE4C_PROVIDER_REFUNDS_ENABLED`. The dual-write risk is now controlled by a
   provider idempotency reference plus reconciliation (§4.2/§4.8); the remaining dependency is the provider's
   own behaviour, which can only be confirmed with merchant credentials.
3. **Decide on stale pending reversal requests** (cancel/expire) — queue hygiene, no financial risk.
4. **Production engine validation** of the `credit_ledger_entry_shape` replacement (verified on MySQL 8.4.13
   only).

---

## 12. Classification

**READY FOR OWNER ACCEPTANCE**

Evidence for the classification:

- **Safe:** the database guard refuses protected and non-disposable targets before any migration; the
  development database returned to its exact baseline (4 users, 5 roles, 0 financial records); provider refunds
  are disabled; mutation permissions are super-admin only; separation of duties defaults on.
- **Correct:** the reversal/receipt defect was reproduced, fixed, re-verified and extended with six regression
  tests; refundable funds, credit-note caps, FIFO reversal, duplicate references and separation are covered by
  passing tests on SQLite (422 tests) and MySQL 8.4.13 (46 + 37 tests); no over-refund, over-credit or
  over-reversal path was found.
- **Dual-write risk controlled:** the uncertain-outcome path is now a durable `submitting` state with a
  provider idempotency reference, explicit reconciliation and a documented failure matrix (§4.8). A refund is
  never auto-resubmitted, auto-confirmed or falsely marked failed.
- **Verifiable UI:** all three financial confirmation dialogs — and the new reconciliation action — were driven
  in the running app, including cancel, confirm, duplicate click and the busy state (§9.1).
- **What remains is not implementation work:** owner approval of D1–D13 (including D10's linkage choice),
  finance-role permission assignment, and external provider merchant-capability verification. Each is an
  owner/external dependency, documented rather than hidden.

**Recommended next action:** the owner works through the decision register, then authorises (or declines)
provider submission once merchant refund capability is confirmed. No merge, deployment or Stage 4D work is part
of this exercise.
