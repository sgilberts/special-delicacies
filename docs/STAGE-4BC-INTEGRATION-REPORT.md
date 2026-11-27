# Stage 4B + Stage 4C Integration Validation Report

**Branch:** `stage-4bc-integration`
**Starting HEAD (verified `main`):** `95d651721aa17cbfaa52cc27209c4858d0970518`
**Status:** integration complete; awaiting owner review. **Nothing merged into `main`; neither original PR was closed or modified; no Stage 4D implementation started.**

---

## 1. Branch and integration record

| Ref | Commit | State |
|---|---|---|
| `main` | `95d6517` | Untouched. Integration branch was created from exactly this commit. |
| PR #1 `audit-deposits-credit` (Stage 4B) | `6d29dd7` | Untouched, not rewritten, still open. |
| PR #2 `refunds-credit-notes` (Stage 4C) | `ceb533b` | Untouched, not rewritten, still open. |
| `stage-4bc-integration` | see below | Contains both stages plus the corrections in §4. |

Integration commits (oldest first):

1. `e2e9ad0` — `merge: integrate Stage 4B deposits and credit acceptance (PR #1)`
2. `bdbf610` — `merge: integrate Stage 4C refunds, credit notes and reversals (PR #2)`
3. Corrections, cross-stage tests and this report (commit created after this file was written).

Order was Stage 4B first, then Stage 4C, both with `--no-ff` merge commits so each original history is preserved inside the branch.

---

## 2. Conflicts and their resolution

| File | Type | Resolution |
|---|---|---|
| `backend/app/Services/GatewayPaymentService.php` | Textual (imports only) | Union of both import sets (`Customer`, refund exceptions, `ConnectionException`). Both sides' method bodies auto-merged; no method appears twice. Stage 4B's deposit-charge methods and Stage 4C's refund methods both remain. |
| `backend/routes/api.php` | Textual (adjacent route groups) | Both `admin/deposits` (4B) and the three `admin/financials/{refunds,credit-notes,credit-reversals}` groups (4C) are registered, each with its own closing brace. Route names/paths unchanged from either branch. |
| `backend/tests/TestCase.php` | Semantic (two different database guards) | One combined guard: Stage 4C's `createApplication()` (forces the test environment, opt-in disposable MySQL via `BASE44_MYSQL_TEST_DATABASE`) plus Stage 4B's `setUpTraits()` second check. A single `assertSafeTestDatabase()` serves both; no duplicate method. The protected-database list is Stage 4C's full list (`special_delicacies`, `special_delicacies_db`, `special_delicacies_stage4a_test`, `special_delicacies_stage4a_acceptance_test`, `special_delicacies_stage4b_test`) and the `_test.sqlite` allowance from 4B was kept. |
| `backend/app/Models/CustomerCreditLedgerEntry.php` | **Semantic, auto-merged wrongly** | Git kept **both** `booted()` methods (a fatal duplicate-method error). Resolved to a single append-only hook; fillable, constants (`CREDIT_TYPES`/`DEBIT_TYPES`), relations (`reversesEntry`, `authorizedBy`) and casts are the union of both branches. |
| `backend/app/Services/CustomerCreditService.php` | Auto-merged | 4B's cross-receipt operation-reference uniqueness guard and 4C's reversal-aware balance/eligibility queries are both present. |
| `backend/app/Services/PaymentAllocationService.php`, `backend/app/Models/CommercialDocument.php`, `backend/database/seeders/RoleAndPermissionSeeder.php` | Auto-merged | 4C-only changes; no 4B counterpart. |
| `frontend/src/App.tsx` | Auto-merged | 4B's customer deposit route and 4C's three admin finance routes are all registered; each page is imported once. |
| `frontend/src/components/account/CustomerCreditPanel.tsx` | Auto-merged | 4B's verification-gate handling plus 4C's `credit_note_credit` / `reversal` entry labels. |
| `docs/README.md` | Auto-merged | Both branches' documentation entries kept; the integration report and the Stage 4B reconciliation report were added in the corrections commit. |

No file was resolved by discarding one side wholesale.

---

## 3. Corrections made during integration

The repository contains no list labelled F1–F8, so the identifiers below are assigned here to the findings raised by this integration exercise; the original Stage 4B, 4C and 4D discovery/design documents are left intact and unedited.

| ID | Finding | Correction |
|---|---|---|
| **F1** | Auto-merge produced two `booted()` methods in `CustomerCreditLedgerEntry` (fatal). | Single append-only hook; append-only behaviour from both branches preserved. |
| **F2** | Two competing database guards in `TestCase` (4B `setUpTraits`, 4C `createApplication`). | Combined into one guard invoked from both entry points (§2). Stage 4C's stricter rule is authoritative; Stage 4B's `_test.sqlite` allowance retained. |
| **F3** | 4C's immutability message (`append-only and cannot be updated`) no longer contained `immutable`, failing `Stage4bRemediationTest`. | Message is now `Customer credit ledger entries are immutable: they are append-only and cannot be updated.` |
| **F4** | **Permission seeder/migration inconsistency.** `2026_11_27_000005_add_stage4c_financial_permissions` copied the three Stage 4C *view* permissions onto every role holding the related read permission, but `RoleAndPermissionSeeder` defines `admin` as excluding all `financials.*` and calls `permissions()->sync()`, which wipes those grants on the next seed. Migration state and seeded state therefore disagreed (and the grant widened `admin` access between migrate and seed). | The migration now creates the permissions and grants them to `super-admin` only — exactly the state the seeder produces. This is the conservative resolution: it never widens access, matches `Stage4cPermissionTest`, and leaves wider grants as an explicit owner action. Deviation from the Stage 4C design §12 table is recorded here; the design document itself is unchanged. |
| **F5** | **Credit-note void / FIFO attribution.** Investigated end to end. FIFO attribution is deterministic and internally consistent: applications consume the oldest un-reversed credit first, so `Σ remaining_reversible` equals the derived ledger balance (asserted by a new test). No double-spend, over-credit or balance drift was found. The open issue is the *document* linkage (D10): a credit note whose credit has been consumed cannot be voided, and a partially reversed note stays `issued` at full value while the ledger is reduced. | **No behaviour change.** D10 (void-first vs linked adjustment vs no linkage) remains an owner decision; the current "no linkage" behaviour is what both branches shipped. Recorded as an acceptance caveat, not a defect in the integration. |
| **F6** | **Rollback hazard:** `staff_deposit_records` `down()` re-narrowed `payment_receipt_intents.actor_*` to `NOT NULL` and only then dropped the table. With a customer-initiated receipt present it failed *mid-DDL* (SQLSTATE 22004), leaving a partially reverted schema. | `down()` now refuses **before any DDL** with a clear message when a null-actor receipt exists, so a blocked rollback leaves the schema untouched (§6). |
| **F7** | **Duplicate `chat_settings` rows.** `ChatSetting::current()` used `firstOrCreate(['id' => 1], …)` while `id` is guarded, so the insert auto-incremented. Once the counter passed 1 (e.g. a rolled-back test transaction), every call created another default row and the configured capacity was ignored. Exposed by the MySQL-mode suite (2 chat tests) on Stage 4C as well as on integration; pre-existing, not caused by either stage. | `current()` now resolves the single settings row by existence (`orderBy('id')->first() ?? create(…)`). Chat suite green on MySQL. |
| **F8** | **Full-history rollback blocked by a pre-existing `main` migration.** `2026_11_25_000010_create_payment_allocation_tables::down()` drops index `payment_transactions_customer_purpose`, which MySQL refuses because a foreign key needs it (SQLSTATE 1553). | **Not changed** — outside the Stage 4B/4C scope and a pre-existing `main` defect. Recorded as a rollback caveat in §8. |

Observation (no action): the two `2026_11_27_000001_*` migrations (refund tables, staff deposit records) share a timestamp. Laravel orders by full filename, so `create_refund_*` runs before `create_staff_deposit_*`; there is no dependency between them and both apply and revert cleanly. Renaming either would break databases that already recorded it.

---

## 4. Migration inventory

49 migration files; the integration adds six (all additive, no destructive statement, no rewrite of an existing table beyond adding columns/indexes):

| Migration | Stage | Object |
|---|---|---|
| `2026_11_27_000001_create_staff_deposit_records_table` | 4B | `staff_deposit_records`; makes `payment_receipt_intents.actor_*` nullable for customer-initiated receipts |
| `2026_11_27_000001_create_refund_requests_and_events_tables` | 4C | `refund_requests`, `refund_events` |
| `2026_11_27_000002_add_credit_note_link_to_commercial_documents` | 4C | `commercial_documents.related_document_id` + index |
| `2026_11_27_000003_create_credit_reversal_requests_table` | 4C | `credit_reversal_requests` |
| `2026_11_27_000004_extend_customer_credit_ledger_for_reversals` | 4C | `reverses_entry_id`, `authorized_by_*`; replaces the ledger CHECK with the four-branch shape |
| `2026_11_27_000005_add_stage4c_financial_permissions` | 4C | nine `financials.{refunds,credit_notes,credit_reversals}.*` permissions |

---

## 5. Test results

Environment: PHP 8.4.28 CLI, `gd`, `pdo_mysql`, `intl`, `zip`, `bcmath`; MySQL **8.4.13** (isolated container); in-memory SQLite for the default suite. All disposable databases (`special_delicacies_integration_test`, `special_delicacies_migration_test`, `special_delicacies_chatcheck_test`, `special_delicacies_4ccheck_test`). No protected database was opened.

| Suite | Result |
|---|---|
| Stage 4B feature tests (5 files) | **58 tests, 467 assertions — pass** |
| Stage 4C feature tests (4 files) | **46 tests, 214 assertions — pass** |
| New cross-stage invariants | **9 tests, 87 assertions — pass** |
| Combined backend, SQLite | **478 tests, 2534 assertions — pass** |
| Combined backend, MySQL 8.4.13 | **478 tests, 2534 assertions — pass** |
| Frontend `tsc -b` + Vite build | **pass** |
| Frontend RBAC route/permission tests | **7/7 pass** |
| Frontend lint | 0 errors, 59 pre-existing warnings |

### Cross-stage invariants (`tests/Feature/Stage4bcCrossStageInvariantTest.php`)

| Invariant | Success path | Rejection path |
|---|---|---|
| Deposit → verification → designation → invoice application | staff-recorded deposit verified by a second staff member becomes `succeeded` with one receipt intent, is designated, then applied to an invoice | recorder cannot verify own deposit; a fully designated receipt rejects further designation |
| Rejected deposit | — | rejected deposit stays `cancelled`, writes no ledger entry, cannot be designated |
| Credit reversal → allocatable/refundable recalculation | 100 designated, 40 reversed ⇒ balance 60, allocatable 40, refundable 40 | requester cannot approve own reversal; over-reversal rejected |
| Credit note issue → application → void eligibility | issued note credits the ledger and auto-applies; an unapplied note can be voided and the invoice becomes creditable again | consumed credit blocks voiding; creator cannot void |
| Refund reservation vs allocation/credit | 100 receipt − 30 allocated − 20 designated = 50 refundable; reserved refund drives it to 0; confirmation leaves the receipt `succeeded` | second refund rejected |
| Duplicate/retry handling | receipt, designation and refund replays return the same record | reused receipt reference on another receipt, conflicting designation amount, duplicate external deposit reference rejected |
| Customer verification gate | both contacts verified ⇒ deposits and credit return 200 | either contact missing ⇒ 403 `customer_verification_required`; staff account ⇒ 403 |
| Permissions after seeding | migration creates the nine permissions; seeder grants them to `super-admin`; re-seeding is idempotent | `admin` holds none of them and no `financials.*` permission |
| Ledger identity | `Σ credit − Σ debit = derived balance = Σ remaining_reversible`; refundable formula holds | — |

---

## 6. MySQL 8.4 migration and rollback evidence

Executed on a disposable database (`special_delicacies_migration_test`), never on a protected name.

**Up:** all 49 migrations applied; 52 tables; `credit_ledger_entry_shape` present with the extended four-branch clause; `payment_receipt_intents.actor_*` = `YES` (nullable).

**Rollback of the six integration migrations** (`migrate:rollback --step=6`): all six reported DONE; integration tables count `0`; `credit_ledger_entry_shape` back to the original two-branch clause; `reverses_entry_id`/`authorized_by_*` count `0`; `actor_*` back to `NO` (NOT NULL). **Re-migrate** re-applied all six and returned to 52 tables.

**Rollback with customer-initiated data present:** with one null-actor receipt row, the staff-deposit rollback now aborts before any DDL:

```
RuntimeException
Cannot roll back staff_deposit_records: payment_receipt_intents contains customer-initiated
receipts with no staff actor, so actor_user_id cannot be made NOT NULL again.
No schema change was applied.
```

**Full-history rollback** (`migrate:rollback` to zero) stops at the pre-existing `2026_11_25_000010_create_payment_allocation_tables` (`SQLSTATE 1553`, index needed by a foreign key). Every Stage 4B/4C migration has already reverted by that point, so the integration is reversible; the full-history rollback defect belongs to `main` and is reported, not fixed here.

---

## 7. Preserved controls

- **Customer verification gate:** `EnsureCustomerFullyVerified` still wraps quotations, invoices, credit and *all* deposit endpoints; the gate reads the account on every request (asserted for email-only, phone-only and fully verified states).
- **Refund provider idempotency and reconciliation:** two-phase submit (durable `submitting` intent committed before the provider call), stable provider idempotency key, `reconcile` by reference with amount/currency matching, `confirm_absent` only on explicit operator confirmation, confirmation verifies the provider amount/currency, and any unknown outcome stays unresolved instead of returning to `approved`. All Stage 4C refund tests pass unchanged.
- **Audit and separation of duties:** append-only ledger, refund events, credit-note and reversal histories, `AdminAuditLog` entries, and every SoD check (deposit verification, refund approve/confirm, credit-note issue/void, reversal approve) are exercised by the merged suites.

---

## 8. Remaining defects and acceptance blockers

1. **D10 credit-note linkage (owner decision).** Void-after-consumption is blocked and a partially reversed note remains `issued` at full value; the ledger and the document disagree by design. No double-spend is possible. Requires an owner choice (void-first / linked adjustment / no linkage) before Stage 4D reporting depends on it.
2. **Full-history rollback** is blocked by `2026_11_25_000010` on `main` (SQLSTATE 1553). Stage 4B/4C migrations are unaffected.
3. **Rollback with live customer-initiated receipts** requires resolving null actors first; it now fails cleanly and leaves the schema untouched.
4. **Existing databases may already contain duplicate `chat_settings` rows** created by the old `current()`. The fixed method consistently uses the oldest row; a one-off cleanup is recommended but is a data decision for the owner.
5. **Frontend lint** reports 59 warnings (0 errors), all pre-existing.
6. No real payment-provider or refund-provider call was made; provider refund submission remains disabled by default (`STAGE4C_PROVIDER_REFUNDS_ENABLED=false`).

---

## 9. Protected branches

`main` remains at `95d6517`; `audit-deposits-credit` at `6d29dd7`; `refunds-credit-notes` at `ceb533b`. No force-push, rewrite, deletion, merge, PR close or deployment was performed.

---

## 10. Recommendation

The integration is technically sound: both stages' behaviour is preserved, the combined suite is green on SQLite and MySQL 8.4.13, the ledger invariants hold across stages, and the six migrations apply and revert cleanly. Recommend owner review of this branch and of the D10 decision; the only substantive blocker to full acceptance is D10, which is an owner decision rather than an integration defect.
