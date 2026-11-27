# Stage 4D — Testing and Acceptance Strategy

**Status:** Proposed — no test was written for Stage 4D yet.
**Date:** 2026-11-27
**Companion to:** `docs/STAGE-4D-FINANCIAL-METRICS-REGISTER.md`, `docs/STAGE-4D-STAGE-4C-DEPENDENCY-MATRIX.md`.

---

## 1. Database safety

- All automated tests inherit `Tests\TestCase`, which forces `APP_ENV=testing`, in-memory SQLite, array mail/session/cache and synchronous queue, and refuses to run against any protected database (`special_delicacies`, `special_delicacies_db`, `special_delicacies_stage4a_test`, `special_delicacies_stage4a_acceptance_test`, `special_delicacies_stage4b_test`).
- MySQL compatibility runs are opt-in via `BASE44_MYSQL_TEST_DATABASE`, accepted only for a disposable `special_delicacies_*_test` database. Stage 4D adds `special_delicacies_stage4d_test` as the MySQL target.
- No Stage 4D test may write to the development or acceptance database, and no test may import a real settlement statement.
- Preview/browser acceptance uses the sandbox database; any record created for verification is deleted in the same script, and no existing financial record is mutated.

---

## 2. Test layers

| Layer | Scope | Examples |
| --- | --- | --- |
| Unit | money conversion, FIFO attribution, filter normalisation, date bucketing | `toMinorUnits` edge cases, FIFO parity |
| Feature (HTTP) | permissions, filters, totals, export parity, read-only guarantees | every report endpoint |
| Data-integrity | identities and invariants from the metrics register | bridge, outstanding, balance |
| Performance | query counts, `EXPLAIN`, dataset scaling | dashboard, registers, exports |
| Reconciliation | import, matching, duplicates, manual, audit | statement workbench |
| Browser acceptance | responsive layout, themes, real gestures | report pages at 4 breakpoints |

Run commands (inside the app container, never on the sandbox host):

```
docker compose ... exec -T api php artisan test
docker compose ... exec -T api php artisan test --filter=Stage4d
BASE44_MYSQL_TEST_DATABASE=special_delicacies_stage4d_test docker compose ... exec -T api php artisan test --filter=Stage4d
```

---

## 3. Fixtures

A dedicated `Stage4dFixture` builder creates, per test:

- one customer and one organisation payer;
- one cash order receipt, one gateway order receipt, one invoice-purpose receipt (`order_id = NULL`), one delivery-fee receipt;
- one invoice with a partial cash allocation and one credit application;
- one credit designation, one credit-note credit (issued note), one credit reversal (voided note), one reversal of a designation;
- one refund in each status: `requested`, `approved`, `submitting`, `submitted`, `confirmed`, `rejected`, `failed`;
- one credit-reversal request in `requested` and one in `approved`.

Fixture amounts are chosen so every identity in the metrics register can be asserted with exact strings, and so a cross-currency case (GHS + a synthetic second currency row) can prove that currencies are never summed.

---

## 4. Invariant tests (must pass for every module)

| ID | Assertion |
| --- | --- |
| INV-1 | `A − Fc = X + D + U + Fo` exactly, per currency, to the cent |
| INV-2 | Customer credit balance equals `Σ credits − Σ debits` from the ledger for every payer/currency |
| INV-3 | Invoice outstanding equals `PaymentAllocationService::balance()['outstanding_balance']` for every invoice |
| INV-4 | Refundable equals `RefundService::refundableMinor()` for every receipt |
| INV-5 | `refundable ≤ allocatable` whenever a live refund exists, and equal otherwise |
| INV-6 | Batched FIFO attribution equals `remainingReversibleMinor()` for every credit entry |
| INV-7 | Credit-note `issued = applied + reversed + remaining` for every note |
| INV-8 | No report total mixes currencies; a mixed-currency fixture returns separate partitions |
| INV-9 | Confirmed refund totals exclude `requested`, `approved`, `submitted`, `submitting` |
| INV-10 | GET report endpoints perform zero writes (assert `updated_at`/row counts unchanged, and no audit rows created) |
| INV-11 | Report totals equal the sum of the same filter applied to a detail export (export parity) |
| INV-12 | Every money value is returned as a 2-decimal string; no float JSON numbers |

---

## 5. Per-module acceptance criteria

### 5.1 Allocation register
- Invoice-purpose receipt with `order_id = NULL` appears (regression for finding G1).
- Reversed allocation appears in history and is excluded from active totals.
- Credit-designated amount nets a reversed designation.
- Filters (payer, provider, method, status, invoice number, date basis) each change the result set as specified.
- Totals equal the aggregate of all matching rows, not the current page.

### 5.2 Unallocated payments
- Receipt with an open refund shows `allocatable > refundable` and is labelled correctly.
- Funds designated as credit are not counted as available.
- Ageing buckets sum to the total available amount.
- A negative residual produces an E-01 exception instead of a silent zero.

### 5.3 Customer credit
- Statement opening + movements = closing, per payer/currency.
- Credit-note credit and reversal appear with the right sign and authorizer.
- Ageing uses FIFO and matches INV-6.
- No endpoint exposes an editable balance.

### 5.4 Refunds
- One refund per status produces the exact stage totals of M-60…M-66.
- `submitting` is listed under unresolved outcomes and is absent from confirmed totals.
- Initiator/approver/confirmer columns match the snapshot fields.
- Manual-mode and provider-mode submissions are distinguishable.

### 5.5 Credit notes
- Issued note with auto-apply shows applied-to-origin and remaining correctly.
- Voided note shows its reversal and zero remaining, with issued history retained.
- Original invoice totals are unchanged by the report (assert the document row is untouched).
- Credit-note attribution mismatch raises E-03.

### 5.6 Dashboard
- Each block equals its underlying report for the same filters.
- Bridge identity INV-1 holds.
- Pending/failed/cancelled receipts are never added to received.
- Empty database renders zeros with `generated_at`, not an error.

### 5.7 Reconciliation
- Import of the same file twice is rejected by checksum (idempotent, no duplicate lines).
- Exact match, fee match, timing difference, amount mismatch, missing internal, missing statement, duplicate internal and duplicate statement each classify correctly.
- Manual settlement requires evidence and writes an audit row with before/after.
- Unmatched lines never appear as matched in summary figures.
- With no settlement source configured, every settlement metric renders “not configured”.

---

## 6. Permission tests

For every Stage 4D route, assert:

1. unauthenticated → 401;
2. authenticated without the permission → 403 (including `admin` by default);
3. with the read permission → 200;
4. export without `financials.reports.export` → 403;
5. export with export permission but without the report permission → 403;
6. reconciliation mutation without `financials.reconciliation.manage` → 403;
7. a user with only `financials.reports.refunds.view` cannot read allocations or settlements.

A frontend RBAC test (existing `npm run test:rbac` pattern) asserts the nav section appears only with a report permission.

---

## 7. Export tests

- CSV header, row count and totals equal the on-screen result for the same filters.
- Formula-injection prefixes (`=`, `+`, `-`, `@`) are escaped.
- Exceeding 366 days or 50,000 rows returns a validation error naming the limit.
- Exports never include fields the report does not show.
- Exported file carries applied filters and `generated_at`.

---

## 8. Performance tests

| Check | Threshold |
| --- | --- |
| Dashboard query count | ≤ 10 queries, no N+1 |
| Register list query count | ≤ 5 queries per page |
| `EXPLAIN` for each new aggregate | uses an index; no `type=ALL` on a financial table |
| Dashboard response at 100k receipts / 300k ledger entries | < 300 ms in the test container |
| Register page at 100k receipts | < 200 ms |
| Export 50k rows | streams without memory exhaustion (peak memory bounded) |
| Reconciliation match of 5k lines | completes in batches, < 5 s |

Seeding for performance tests uses factories in the disposable test database only.

---

## 9. Browser and responsive acceptance

- Breakpoints: 375, 675 (current preview), 1024, 1440 px.
- Assertions per report page: no horizontal overflow (`scrollWidth ≤ clientWidth + 2`), filters usable, table becomes cards on mobile, summary strip remains readable, money right-aligned and fully visible.
- Themes: light, dark, system — verify token-driven colours (no hard-coded hex) and legible contrast on money cells and status pills.
- Gestures verified end-to-end: change date range, apply payer filter, sort a column, paginate, open a row detail, export, and (reconciliation) import a synthetic CSV and match a line.
- Signed-in coverage requires a real admin session; if none is available, the gated pages are reported as not automatically verified rather than assumed.
- Screenshots are captured for each changed surface for the PR body.

---

## 10. Regression coverage for discovery findings

| Finding | Regression test |
| --- | --- |
| G1 invoice receipts invisible | invoice-purpose receipt appears in dashboard and register |
| G2 refunds always zero | confirmed refund appears in refund totals and in `Fc` |
| G4 allocatable vs refundable | INV-5 |
| G5 allocation date scan | `EXPLAIN` uses the new index |
| G6 credit ledger scan | `EXPLAIN` uses the new index |
| G7 refund date scan | `EXPLAIN` uses `requested_at` index |
| G8 FIFO attribution | INV-6 |
| G9 decimal width | large-amount fixture (10,000,000.00) formats correctly |
| G10 currency mixing | mixed-currency fixture produces separate partitions |
| G11 delivery-fee receipts | M-08 equals the delivery-fee row and is a subset of M-06 |

---

## 11. Acceptance gates

Stage 4D is accepted only when:

1. all Stage 4A/4B/4C tests still pass unchanged;
2. every Stage 4D test passes on SQLite **and** MySQL 8.4;
3. INV-1…INV-12 pass;
4. every report route has the permission matrix asserted;
5. export parity holds for every report;
6. performance thresholds pass on the seeded dataset;
7. browser acceptance is captured at all four breakpoints in light and dark themes;
8. no GET route writes data (audited by test and by log review);
9. documentation (`API-DOCUMENTATION.md`, `DATABASE-SCHEMA.md`, `FEATURES-AND-OPERATIONS.md`, `IMPLEMENTATION-LOG.md`) is updated;
10. the acceptance report records every metric against its authoritative source.

---

## 12. Reporting of results

Each verification item ends with an explicit status: **passing assertion**, **not attempted**, or **not automatically verified** with the reason. A green build is never reported as “figures verified”; each metric is verified against its formula and source.
