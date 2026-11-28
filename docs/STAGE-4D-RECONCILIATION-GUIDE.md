# Stage 4D — Internal Reconciliation Guide

**Status:** internal reconciliation implemented; external provider settlement reconciliation deliberately unavailable.
**Date:** 2026-11-28
**Branch:** `org-support-upgrade`
**Code:** `backend/app/Services/Reporting/ReconciliationService.php`, `backend/app/Http/Controllers/Api/V1/Admin/FinancialReconciliationController.php`, `frontend/src/pages/admin/financial/FinancialReconciliationPage.tsx`.
**Authoritative references:** `docs/STAGE-4D-DECISION-REGISTER.md` (D4, D5, D12, D13), `docs/STAGE-4D-API.md` §16.

---

## 1. What this guide covers — and what it does not

This guide describes **internal reconciliation**: comparing the business’s own records (receipts, allocations, credit and invoices) with each other for a period, and recording the result in an auditable run.

It does **not** describe provider settlement reconciliation. That capability has no genuine data source in this repository and is not implemented. Payment verification (Stage 4A–4C) confirms that a provider captured a payment; it does not report settlement batches, provider fees, bank deposits or payouts. No batch, fee, deposit or payout figure is estimated or fabricated anywhere in the application.

| Concept | Implemented? | Data source |
| --- | --- | --- |
| Internal receipt reconciliation | Yes | `payment_transactions`, allocations, designations, refunds |
| Internal invoice reconciliation | Yes | `commercial_documents`, allocations, credit ledger |
| Auditable reconciliation runs | Yes | `reconciliation_runs` + `admin_audit_logs` |
| External provider settlement reconciliation | **No** | No settlement feed exists |
| Statement import (CSV/API) | **No** | Blocked by D4/D13 |
| Provider fee / net settlement reporting | **No** | Blocked by D4/D5 |
| Manual match against a bank statement | **No** | Blocked by D4/D12 |

The UI and API state this explicitly rather than showing an empty settlement screen that could be mistaken for “zero differences”.

---

## 2. Who can do what

Reconciliation uses three separate permissions (decision D1/D2). They are granted to `super-admin` only by the Stage 4D migration.

| Permission | Capability |
| --- | --- |
| `financials.reconciliation.view` | Open the workbench, see the snapshot, browse run history |
| `financials.reconciliation.manage` | Prepare a run (captures the snapshot and creates history) |
| `financials.reconciliation.review` | Close a run as reconciled or reconciled with exceptions |
| `financials.reports.settlements.view` | Call the settlement availability endpoint |

Separation is enforced server-side and asserted by `test_reconciliation_view_prepare_and_review_are_separate_permissions`:

- A viewer cannot prepare (403) and cannot call the settlement endpoint (403).
- A preparer without the review permission cannot close a run (403).
- A reviewer without the manage permission cannot prepare (403).

The frontend hides the “Prepare reconciliation run” and “Review” controls without the matching permission, but that is convenience only.

---

## 3. Before you start

1. Confirm the period you are reconciling. Day boundaries are resolved in `Africa/Accra`; the end date includes the whole day.
2. Confirm the currency. Reconciliation is strictly per currency; a USD receipt never appears in a GHS run.
3. Confirm that Stage 4A–4C operations for the period are complete. Reconciliation does not block new receipts, allocations, designations or refunds: a snapshot is a **point-in-time** copy, not a lock.

---

## 4. The workflow

### Step 1 — Open the workbench

`/admin/financials/reconciliation` (permission `financials.reconciliation.view`).

Set `From`, `To` and `Currency`, then **Refresh**. The page loads the snapshot and the 20 most recent runs.

### Step 2 — Review the snapshot

**Receipt reconciliation** (succeeded invoice receipts with a received date in the period):

| Figure | Meaning |
| --- | --- |
| Receipts | count of receipts in scope |
| Receipt value | their total amount |
| Fully allocated | applied > 0 and available = 0 |
| Partially allocated | applied > 0 and available > 0 |
| Unallocated | applied = 0 |
| Reversed allocations | count and amount reversed in the period |
| Applied amount | active allocations + credit designations, net of designation reversals |
| Available amount | positive residual still available to allocate |
| Inconsistent | receipts with a negative available balance (E-01) |

**Invoice reconciliation** (issued or expired, non-voided invoices issued in the period):

| Figure | Meaning |
| --- | --- |
| Invoices / Invoice total | count and value |
| Allocated | active payment allocations |
| Credit applied | pooled credit applications |
| Outstanding | total − settled, floored at zero |
| Paid / partial / unpaid | counts by settlement state |
| Over-settled | invoices whose settled amount exceeds their total (E-07) |

**Exceptions** are counts, not amounts:

| Code | Meaning |
| --- | --- |
| E-01 | Receipts with a negative available balance |
| E-05 | Refunds awaiting a provider outcome (`submitting`) |
| E-07 | Invoices settled beyond their total |
| E-08 | Successful receipts without a received date |
| TOTAL | Sum of the four counts |

E-08 exists because a succeeded receipt without `received_at` cannot be placed on a date basis; it is surfaced rather than silently counted or repaired (decision D10).

### Step 3 — Prepare a run

Permission `financials.reconciliation.manage`. Button **Prepare reconciliation run**.

- The current snapshot (period, currency, receipt and invoice figures, exceptions and settlement availability) is stored as JSON in `reconciliation_runs.summary`.
- The run is created as `awaiting_review` with a reference `REC-<from>-<to>-<6 random chars>`, `prepared_by`, `prepared_by_name_snapshot`, `prepared_at`, and `exception_count`.
- Audit action `financials.reconciliation.prepared` is written.
- The new run appears at the top of the history table.

Preparing does not change any receipt, allocation, credit, invoice or refund.

### Step 4 — Review and close

Permission `financials.reconciliation.review`. Button **Review** on a run that is not closed.

1. Choose the outcome:
   - **Reconciled** — only allowed when `exception_count = 0`.
   - **Reconciled with exceptions** — required when one or more exceptions were recorded.
2. Enter resolution notes explaining how exceptions were resolved or accepted (recommended; max 2000 characters).
3. **Close reconciliation**.

The server rejects a mismatched outcome with HTTP 422 and a message naming the exception count. On success the run stores `reviewed_by`, `reviewed_by_name_snapshot`, `reviewed_at`, `resolution_notes`, and audit action `financials.reconciliation.reviewed` is written.

### Step 5 — History is read-only

A closed run is `immutable`:

- It cannot be reviewed again (422 “already closed”).
- It cannot be edited, reopened or deleted; no such endpoint exists.
- The stored `summary` remains the point-in-time snapshot, even if underlying records change later.

To reflect later activity, prepare a **new** run for the same or a later period. The history keeps both, which is the audit trail.

---

## 5. Run states

| Status | Meaning | Editable |
| --- | --- | --- |
| `awaiting_review` | Prepared, not closed | Review only |
| `reconciled` | Closed with no exceptions | No |
| `reconciled_with_exceptions` | Closed with exceptions and notes | No |

The schema also accepts `draft` and `in_progress` as historical status values in filters, but the implemented workflow only creates `awaiting_review` and closes to the two final states.

---

## 6. Audit history

Two independent records exist:

1. **`reconciliation_runs`** — one row per run: reference, source (`internal_combined`), period, status, prepared-by name snapshot, prepared-at, reviewed-by name snapshot, reviewed-at, exception count, resolution notes, summary JSON, timestamps.
2. **`admin_audit_logs`** — `financials.reconciliation.prepared` and `financials.reconciliation.reviewed`, with actor, description and `{reference, exception_count}` / `{reference, status}` metadata.

Name snapshots are stored so a later user rename or deletion does not rewrite history. Foreign keys use `nullOnDelete`; the snapshot keeps the name.

The run list endpoint is `GET /api/v1/admin/reconciliation/runs` (see `docs/STAGE-4D-API.md` §16.3). There is no export of reconciliation history.

---

## 7. Read-only behaviour

- The workbench never edits receipts, allocations, credit, invoices, refunds or payments.
- The only writes are the run row and the two audit entries.
- Closed runs are immutable by rule, not just by UI: the service rejects a second review.
- Reports and reconciliation derive every figure from the existing tables; there is no snapshot table other than the stored run summary.

---

## 8. External settlement reconciliation — unavailable

The settlement section of the workbench and `GET /api/v1/admin/reconciliation/settlements` return:

```json
{
  "available": false,
  "tier": "not_configured",
  "reason": "No genuine provider settlement feed is configured. Payment verification confirms that a provider captured a payment; it does not report settlement batches, provider fees or bank deposits.",
  "required_data": [
    "settlement batch reference", "provider", "currency", "settlement date",
    "gross amount", "provider fees", "net settlement amount", "matched transactions"
  ],
  "decisions_pending": ["D4", "D5", "D12", "D13"]
}
```

Why it stays unavailable:

- Paystack, Hubtel and Korapay integrations in this repository verify payments; none exposes a settlement batch, fee or payout feed.
- Implementing it without that feed would require inventing batches, fees, deposits or payout references — explicitly forbidden by the Stage 4D authorisation (§7C).
- The business rules for fees (D5), approval separation (D12) and statement retention (D13) are unresolved.

**Do not** represent an internal reconciliation run as a provider settlement reconciliation. They answer different questions: internal reconciliation checks that the ledger is coherent; settlement reconciliation would check that the provider paid the business the expected net amount.

### Unresolved owner decisions

Retrieved from `docs/STAGE-4D-DECISION-REGISTER.md` and `docs/STAGE-4D-OWNER-DECISION-REGISTER.md`:

- **D4** — settlement data source for v1. Resolved by instruction only to the extent of “no feed → document, do not fabricate”; the automated tier remains open.
- **D5** — gateway fee handling (gross/fee/net storage, who may edit). Not exercised; no fee table exists.
- **D12** — reconciliation approval separation for manual settlement/matches (no separation, threshold, or always two approvers). Not exercised; run prepare/review separation exists but no monetary threshold policy is assumed.
- **D13** — statement retention (what is stored from an imported statement). Not exercised; no import exists.

No answer to D5, D12 or D13 may be inferred from the current implementation.

---

## 9. Limitations

1. **No settlement feed, import or manual match.** See §8.
2. **Counts, not amounts, for exceptions.** The run stores exception counts; the underlying rows are visible in the reports.
3. **Point-in-time snapshot.** Later Stage 4A–4C activity is not reflected in a prepared run. Prepare a new run.
4. **No reconciliation CSV.** Reports export; reconciliation history does not.
5. **No scheduled/automatic reconciliation.** Runs are prepared manually.
6. **Single currency per run.** Cross-currency reconciliation is not supported (decision D14).
7. **Order receipts are out of scope** for receipt reconciliation: they are settled against their order, so their residual is not “unallocated money”.
8. **No reversal of a closed run.** Corrections require a new run and resolution notes.

---

## 10. Operating checklist

1. Period and currency chosen deliberately; end date includes the full day.
2. Snapshot reviewed; every exception code understood.
3. Run prepared by someone with `financials.reconciliation.manage`.
4. Exceptions investigated in the reports (receipt register, allocations, receivables, refunds).
5. Run closed by someone with `financials.reconciliation.review`; outcome matches the exception count; notes explain the exceptions.
6. History left untouched; a new run is prepared if records change.

---

## 11. Troubleshooting

| Symptom | Cause | Action |
| --- | --- | --- |
| 403 on the workbench | Missing `financials.reconciliation.view` | Ask an owner to grant the permission; it is not granted to `admin` by default |
| “Prepare” returns 403 | Missing `financials.reconciliation.manage` | Use an account with the prepare permission |
| Close returns 422 “records N exception(s)” | Chose `reconciled` with exceptions present | Close as `reconciled_with_exceptions` and explain them |
| Close returns 422 “already closed” | Run was already reviewed | Prepare a new run |
| Settlement panel says “Not configured” | No settlement feed exists | Expected; see §8 |
| Figures are zero | No records in the period/currency | Widen the period or check the currency |
| E-08 appears | Succeeded receipt without `received_at` | Investigate the original receipt; do not edit history |
