# Stage 4C — Refunds, Credit Notes and Customer Credit Reversals: Implementation and Independent Acceptance Report

**Status:** Independent verification and remediation complete.
**Classification:** **CONDITIONALLY READY** — the implemented workflows are financially safe within the tested scope, one material integration defect was found and fixed, and the remaining conditions are owner decisions plus external provider verification.
**Report date:** 2026-11-27
**Design reference:** `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md`
**Initial discovery reference:** `docs/STAGE-4C-INITIAL-REPORT.md`

---

## 1. Repository, branch and pull request

| Item | Verified value |
|---|---|
| Repository | `https://github.com/sgilberts/special-delicacies.git` |
| Branch | `refunds-credit-notes` |
| Base | `origin/main` at `95d651721aa17cbfaa52cc27209c4858d0970518` |
| Implementation commit (HEAD at audit start) | `ceed566875e9db5271908c932502e32b9dea6be9` — *Implement Stage 4C refund, credit note, and reversal workflows* |
| Working tree at audit start | Clean |
| PR #2 | **OPEN**, `MERGEABLE`, head `refunds-credit-notes`, base `main`, 37 files, +4769/−15, no review decision |
| PR #1 | **OPEN** and untouched; not merged |
| Deployment | None performed |
| Sandbox database | MySQL **8.4.13**, `special_delicacies_db` |

**Branch reconciliation.** The design named `stage-4c-refunds-credit-notes`; the platform session branch is
`refunds-credit-notes`, based on `main` at `95d6517`. `main` already contains the Stage 4A allocation
foundation and the Stage 4B credit/deposit foundation, so no Stage 4C dependency was omitted. The design and
initial discovery documents are preserved unmodified in `docs/`. PR #1's head is not an ancestor of this
branch, but the Stage 4A/4B schema and services that Stage 4C depends on are present on `main` and were
re-verified by the Stage 4A/4B regression suites below.

**This audit's changes** are uncommitted in the sandbox and are pushed to the same branch by the platform at
the end of the turn; they update PR #2 rather than opening a new PR. No reset, clean, stash, discard,
force-push, merge or deploy was performed.

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

### 4.2 Defect fixed: duplicate provider submission under concurrency

`RefundService::submit()` previously read the refund status, called the provider, and only then locked the row.
Two concurrent submissions could both instruct the provider to refund the same payment.

- **Fix:** the provider call now happens inside the transaction while the refund row is locked, so a second
  submission blocks and then observes `submitted`. That transaction is deliberately **not retried** (a
  deadlock retry would re-invoke the provider). Provider failures raise a dedicated
  `ProviderRefundUnavailable` exception; the transaction rolls back, the refund stays `approved`, a
  `provider_error` event is recorded after the rollback, and the caller receives a retryable conflict.
- **Residual risk (documented):** if the provider accepts the refund but the database commit fails, a retry can
  duplicate the provider instruction. Paystack's refund endpoint exposes no idempotency key. This path is
  disabled by default and must be reviewed before enabling provider refunds.

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

## 5. Defects found and remediated

| # | Severity | Finding | Remediation | Status |
|---|---|---|---|---|
| 1 | **High** | Reversing designated credit did not release the receipt's funds; `availableMinorForPayment`/`refundableMinor` stayed at zero | Net reversals in `creditDesignatedMinorForPayment()` and the eligible-receipts query; regression test added | Fixed, re-verified on SQLite + MySQL |
| 2 | **Medium** | Concurrent provider submissions could double-instruct the provider | Provider call moved inside the refund row lock; dedicated retryable failure path; existing outage test still green | Fixed, re-verified |
| 3 | **Low** | Float conversion for provider refund minor units | Use `toMinorUnits()` | Fixed |
| 4 | **Low** | Two tests hard-coded `https://special-delicacies.local`, failing whenever the container sets the preview URL | Tests now pin `config(['cors.allowed_origins'])` / `config(['app.frontend_url'])` | Fixed; suite is green with no environment overrides |
| 5 | **Low (UI)** | Permission-denied page's “Return to dashboard” CTA rendered copper text on a copper background (computed colour == background), so its label was invisible | Replaced the ad-hoc classes with the shared `btn-accent` style (dark primary + off-white text) | Fixed; computed colour `rgb(250,247,242)` on `rgb(48,32,25)`, confirmed visually |

No other defect was found in the audited scope.

---

## 6. D1–D13 decision matrix

“Enforcement” names the code path that actually blocks or permits the operation, not the UI.

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
| D10 | Reversal needs an independent authorizer | Enforced for the authorizer; credit-note linkage remains open (§4.7.1) | `stage4c.credit_reversals.require_independent_approval` | `Stage4cPolicy::reversalSeparationRequired`; `CustomerCreditReversalService::approve` | **Yes** — decide the credit-note linkage sub-point | Partially — no double-spend, but reporting linkage needs a decision |
| D11 | Refund the receipt amount; credit note mirrors invoice line totals | Enforced | — | `RefundService::create` (receipt amount); `CreditNoteService::totalsFromLines` (line snapshots) | **Yes** | Yes |
| D12 | Provider unavailable: stay pending, record error, retry idempotently, never auto-confirm | Enforced | `stage4c.refunds.provider_submission_enabled` | `Stage4cPolicy::providerRefundsEnabled`; `RefundService::submit` + `ProviderRefundUnavailable` | **Yes** (enable provider submission) | Yes — default `false`, env var absent |
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
OK (410 tests, 2002 assertions)
```

0 failures, 0 errors, 0 skipped. `phpunit.xml` declares in-memory SQLite; no environment overrides were used.
The two previously failing URL tests now pass as part of the normal run.

### 8.2 MySQL 8.4.13 — disposable database

`special_delicacies_stage4c_test` was dropped and recreated before the run.

```
BASE44_MYSQL_TEST_DATABASE=special_delicacies_stage4c_test php vendor/bin/phpunit --filter="Stage4c"
OK (34 tests, 159 assertions)

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
| `npm run lint` | **0 errors**, 55 warnings (React Compiler `set-state-in-effect` advisories consistent with existing pages) |
| `npm run build` (`tsc -b` + Vite + postbuild) | **Success** |
| `npm run test:rbac` | **7 tests, 7 pass, 0 fail** |

### 8.5 Stage 4C test inventory

`Stage4cRefundTest`, `Stage4cCreditNoteTest`, `Stage4cCreditReversalTest`, `Stage4cPermissionTest` — 34 tests
on MySQL, including the new reversal-release regression and the four new permission/security tests.

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

**Approval confirmation and duplicate submission.** The UI confirmation steps use browser-native
`window.confirm`/`window.prompt`; the preview browser blocks on the native dialog, so the confirmation prompt
itself could not be driven automatically. Approval, submission, confirmation, credit-note issuance and the
credit-reversal rejection were therefore driven through the app's own authenticated API in the same browser
session, and the UI was then verified to render each persisted state. Repeated approve/issue calls returned the
same state without creating duplicates (idempotent replay). The native dialog remains **not automatically
verified**; it should be tried by hand.

**Mobile width note:** the platform's mobile preset renders at 375 px, not the requested 390 px. Tablet is
exactly 768 px and desktop 1440 px.

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

1. **Sign off D1–D13**, in particular D10's credit-note linkage sub-decision (§4.7.1) and the assignment of
   mutation permissions to finance roles (currently super-admin only).
2. **Confirm merchant refund capability** for each provider, then decide whether to enable
   `STAGE4C_PROVIDER_REFUNDS_ENABLED`; the dual-write residual risk (§4.2) must be accepted first.
3. **Decide on stale pending reversal requests** (cancel/expire) — cosmetic/queue hygiene, no financial risk.
4. **Try the native confirmation dialogs by hand** (approval, submission, confirmation, void, issue).
5. **Production engine validation** of the `credit_ledger_entry_shape` replacement (verified on MySQL 8.4.13
   only).

---

## 12. Classification

**CONDITIONALLY READY**

Evidence for the classification:

- **Safe:** the database guard refuses protected and non-disposable targets before any migration; the
  development database returned to its exact baseline; provider refunds are disabled; mutation permissions are
  super-admin only; separation of duties defaults on.
- **Correct:** the material reversal/receipt defect was reproduced, fixed and re-verified; refundable funds,
  credit-note caps, FIFO reversal, duplicate references and separation are covered by passing tests on SQLite
  and MySQL 8.4.13; no over-refund, over-credit or over-reversal path was found.
- **Complete within scope:** all three workflows, APIs, RBAC, audit trails and admin pages are implemented and
  exercised at desktop, tablet and mobile widths, in light, dark and system themes, with no page-level
  overflow.
- **Not unconditional:** owner approval of D1–D13 (including the D10 credit-note linkage decision), provider
  merchant-capability verification, the documented dual-write residual risk, and manual confirmation-dialog
  testing remain outstanding. These are owner/external dependencies, not undisclosed implementation gaps.

**Recommended next action:** the owner reviews §4.7 and §11, decides D10's linkage rule and the permission
assignments, and — once merchant refund capability is confirmed — authorises provider submission. No merge or
deployment is part of this work.
