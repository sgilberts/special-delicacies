# Stage 4D — Financial Reporting and Reconciliation API

**Status:** implemented and covered by automated tests (`Stage4dFinancialReportsTest`, `Stage4dPermissionTest`, `Stage4dReconciliationTest`, `Stage4dReportingKernelTest`).
**Date:** 2026-11-28
**Branch:** `org-support-upgrade`
**Implementation:** `backend/app/Http/Controllers/Api/V1/Admin/FinancialReportsController.php`, `FinancialReconciliationController.php`, `backend/app/Services/Reporting/*`, routes in `backend/routes/api.php` (Stage 4D block).
**Authoritative references:** `docs/STAGE-4D-DECISION-REGISTER.md`, `docs/STAGE-4D-IMPLEMENTATION-PLAN.md`, `docs/STAGE-4D-FINANCIAL-REPORTING-AND-RECONCILIATION-DESIGN.md`.

This document describes what the code implements. Where a rule is a labelled conservative default rather than an owner decision, it is called out.

---

## 1. Scope

- Read-only reporting over the existing Stage 4A–4C tables. No report endpoint writes data.
- Nine reports, one dashboard and one internal reconciliation surface.
- CSV export for each report.
- Reconciliation run preparation and review (the only writes in the Stage 4D API).
- External provider settlement reconciliation is **not available**; the API returns an explicit “not configured” payload instead of figures (decision D4).

Nothing in this API introduces a second ledger or a second balance. Every figure is derived from `payment_transactions`, `payment_allocations`, `payment_allocation_operations`, `payment_allocation_reversals`, `commercial_documents`, `customer_credit_ledger_entries`, `refund_requests`, `refund_events` and `commercial_document_histories`.

---

## 2. Base URL, authentication and middleware

- Base path: `/api/v1`
- Stage 4D routes:
  - `/api/v1/admin/reports/...`
  - `/api/v1/admin/reconciliation/...`
- Authentication: Laravel Sanctum bearer token (`Authorization: Bearer <token>`), inside the existing admin group:

```php
Route::middleware(['auth:sanctum', EnsureStaffAccount::class])
```

- Every route additionally carries the `permission:<slug>` middleware (`EnsureUserHasPermission`), evaluated **before** the controller.
- Permission checks are server-side. The frontend `ProtectedRoute` and sidebar entries mirror them for usability only; they are not the security boundary.

### Permission slugs used by Stage 4D

| Permission | Grants |
| --- | --- |
| `financials.reports.dashboard.view` | Dashboard overview, trends, operational panels and exceptions |
| `financials.reports.receipts.view` | Payment and receipt register |
| `financials.reports.allocations.view` | Payment allocation report |
| `financials.reports.unallocated.view` | Unallocated receipts report |
| `financials.reports.receivables.view` | Accounts receivable report |
| `financials.reports.customer_credit.view` | Customer credit report and statement |
| `financials.reports.organisation_credit.view` | Organisation credit report and statement |
| `financials.reports.refunds.view` | Refund report |
| `financials.reports.credit_notes.view` | Credit note report |
| `financials.reports.activity.view` | Financial activity and audit report |
| `financials.reports.settlements.view` | Settlement availability endpoint (no figures exist) |
| `financials.reports.export` | Required **in addition to** the report’s read permission to download CSV |
| `financials.reconciliation.view` | Reconciliation snapshot and run history |
| `financials.reconciliation.manage` | Prepare a reconciliation run |
| `financials.reconciliation.review` | Review and close a reconciliation run |

The Stage 4D migration grants all of these to `super-admin` only, using `syncWithoutDetaching`. No ordinary administrator receives them by default (decision D2). A caller holding only `financials.reports.export` cannot read any report.

Contact details are additionally masked: see §10.

---

## 3. Common request parameters

Every report endpoint validates the following, then its own extra filters:

| Parameter | Type / rule | Meaning |
| --- | --- | --- |
| `from` | `Y-m-d`, nullable | Period start. Default: 29 days before `to`. |
| `to` | `Y-m-d`, `after_or_equal:from`, nullable | Period end. Default: today in the business timezone. |
| `currency` | 3 characters, nullable | ISO currency. Figures are never mixed across currencies. |
| `search` | string, max 100, nullable | Report-specific free-text search (see each report). |
| `page` | integer ≥ 1, nullable | Page number. |
| `per_page` | integer 1–100, nullable | Page size. Default 25 (20 for reconciliation runs). Values above 100 are clamped. |
| `sort` | string, max 30, nullable | Sort key; unknown keys fall back to the report default. |
| `direction` | `asc` \| `desc`, nullable | Default `desc`. |

Validation failures return HTTP 422 with Laravel’s standard `{ "message": ..., "errors": { "<field>": [...] } }` body.

### Period semantics (`ReportPeriod`)

- Timezone: `config('app.timezone')`, i.e. `Africa/Accra`.
- `from` is start-of-day; `to` is **end-of-day**, so a transaction at 23:59:59 on the `to` date is included and the next day is excluded (asserted by `test_date_range_includes_the_end_of_the_selected_day_and_excludes_the_next`).
- If `from` > `to`, the two are swapped.
- The range is clamped to **366 days** (the last 366 days ending at `to`).
- The comparison period is the immediately preceding period of the same length.

### Currency semantics

- Each request reports one currency. `currency` defaults to the first currency present in the ledger (normally `GHS`).
- `meta.currencies` lists every currency present in `payment_transactions` and `commercial_documents` (the dashboard lists succeeded receipts and issued invoices).
- No endpoint produces a cross-currency total. A USD receipt never appears in a GHS figure (asserted by `test_reports_are_partitioned_per_currency`).

---

## 4. Common response shapes

### 4.1 Paginated report

```json
{
  "success": true,
  "data": [ { "...row..." } ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 25,
    "total": 6,
    "date_basis": "received_at",
    "currencies": ["GHS"],
    "summary": { "...report summary..." }
  },
  "generated_at": "2026-11-28T07:00:00+00:00"
}
```

`date_basis` is a machine label; the human sentence is rendered by the frontend. `summary` covers the **whole filtered set**, not only the current page.

### 4.2 Single object

```json
{
  "success": true,
  "data": { "...": "..." },
  "meta": { "date_basis": "ledger created_at", "currencies": ["GHS"] },
  "generated_at": "2026-11-28T07:00:00+00:00"
}
```

### 4.3 Money and dates

- Money is a **decimal string** with two places (`"1550.00"`, `"-12.50"`). Values are aggregated as integer minor units and converted by `Money::decimal()`.
- Dates are `Y-m-d`; timestamps are the stored database timestamps (UTC), formatted by the client.
- Counts are integers.

---

## 5. Errors

| Status | When | Body |
| --- | --- | --- |
| 401 | Missing/expired token | `{"success": false, "message": "Unauthenticated."}` |
| 403 | Missing permission | `{"success": false, "message": "Forbidden. You do not have permission to perform this action."}` |
| 403 | Deactivated account | `{"success": false, "message": "Your account is deactivated."}` |
| 422 | Invalid query/body | `{"message": "...", "errors": {"<field>": ["..."]}}` |
| 422 | Reconciliation domain rule | `{"success": false, "message": "<rule text>"}` |
| 429 | Write throttling (prepare/review) | Laravel throttle response (`throttle:20,1`) |

Domain errors currently produced by reconciliation:

- `A reconciliation can only be closed as reconciled or reconciled with exceptions.`
- `Reconciliation run not found.`
- `This reconciliation is already closed and cannot be edited.`
- `This run records N exception(s); close it as reconciled with exceptions instead.`

---

## 6. Dashboard

`GET /api/v1/admin/reports/dashboard` — permission `financials.reports.dashboard.view`

Filters: common parameters only (no report-specific filters). `search` is accepted by validation but has no effect on the dashboard.

`date_basis`: `received_at`.

### `data`

```json
{
  "period": { "from": "2026-10-30", "to": "2026-11-28", "date_basis": "received_at",
              "timezone": "Africa/Accra", "days": 30,
              "comparison": { "from": "2026-09-30", "to": "2026-10-29" } },
  "currency": "GHS",
  "currencies": ["GHS"],
  "overview": {
    "receipts_total": "0.00",
    "receipt_count": 0,
    "cash_receipts": "0.00",
    "online_receipts": "0.00",
    "allocated_total": "0.00",
    "unallocated_total": "0.00",
    "invoice_value_total": "0.00",
    "receivables_outstanding": "0.00",
    "customer_credit_balance": "0.00",
    "organisation_credit_balance": "0.00",
    "refunds_pending": "0.00",
    "refunds_completed": "0.00",
    "credit_notes_issued": "0.00",
    "previous": { "...same keys as above..." },
    "definitions": [ { "key": "receipts_total", "label": "Successful receipts", "basis": "..." } ]
  },
  "trends": {
    "daily": [ { "date": "2026-10-30", "cash_receipts": "0.00", "online_receipts": "0.00",
                 "receipts_total": "0.00", "allocated": "0.00", "invoices_issued": "0.00",
                 "refunds_confirmed": "0.00", "credit_notes_issued": "0.00",
                 "unallocated_movement": "0.00" } ],
    "weekly": [ { "period": "2026-W44", "...metrics..." } ],
    "monthly": [ { "period": "2026-10", "...metrics..." } ]
  },
  "operational": {
    "recent_receipts": [ "...receipt rows..." ],
    "recent_allocations": [ "...allocation rows..." ],
    "recent_refunds": [ "...refund rows..." ],
    "recent_credit_notes": [ "...credit note rows..." ],
    "unallocated_receipts": [ "...unallocated rows..." ],
    "outstanding_invoices": [ "...receivable rows..." ],
    "exceptions": [ { "code": "E-01", "label": "...", "count": 0 },
                    { "code": "TOTAL", "label": "Total exceptions", "count": 0 } ]
  }
}
```

Rules:

- `receipts_total` counts **succeeded** transactions with a `received_at` in the period. Pending, failed and cancelled money is excluded.
- `cash_receipts` = succeeded receipts with `payment_method = cash`; `online_receipts` = every other method.
- `allocated_total` = active allocations dated in the period (reversed allocations excluded).
- `unallocated_total` = positive available balance of succeeded **invoice** receipts received in the period. Order receipts are settled against their order and never appear here.
- `invoice_value_total` is invoice value, **not** cash revenue.
- `receivables_outstanding` = issued invoices minus active allocations and applied credit, as of the period end.
- `customer_credit_balance` / `organisation_credit_balance` are ledger-derived (credits − applications − reversals). Credit is not cash received.
- `refunds_pending` = `requested`, `approved`, `submitting`, `submitted` by request date.
- `refunds_completed` = `confirmed` only, by confirmation date.
- `credit_notes_issued` = issued credit notes by issue date.
- `previous` repeats the same calculation for the comparison period; the client shows “no previous baseline” when every previous figure is zero.
- `trends.daily` has one row per day in the period (including zero days). `weekly` keys are `o-\WW`; `monthly` keys are `Y-m`.
- Operational panels return at most 5 rows each, using the same row shapes as the reports below.
- Exception codes: `E-01` receipts with a negative available balance, `E-05` refunds awaiting a provider outcome, `E-07` invoices settled beyond their total, `E-08` succeeded receipts without a received date, plus a `TOTAL`.

The dashboard is **not** exportable.

---

## 7. Report A — Payment and receipt register

`GET /api/v1/admin/reports/receipts` — permission `financials.reports.receipts.view`

Extra filters:

| Parameter | Values |
| --- | --- |
| `status` | `pending`, `succeeded`, `failed`, `cancelled` |
| `method` | `cash`, `gateway` |
| `provider` | string, max 40 (e.g. `paystack`, `hubtel`) |
| `purpose` | `order`, `invoice` |
| `payer_type` | `customer`, `organisation` |
| `search` | transaction reference, provider reference, order number, customer name, organisation name |

Sort keys: `received_at` (default), `amount`, `created_at`. `date_basis`: `received_at` (falling back to `created_at` for unresolved payments).

### Row

| Field | Meaning |
| --- | --- |
| `id` | payment transaction id |
| `transaction_reference` | internal receipt reference |
| `provider_reference` | provider reference, nullable |
| `payer` | `{type: customer\|organisation\|order, id, name}`; adds `email`/`phone` only with contact permission |
| `order_number` | order reference for order receipts |
| `payment_purpose` | `order` \| `invoice` |
| `payment_method` | `cash` \| `gateway` |
| `provider` | provider slug, nullable for cash |
| `currency` | ISO currency |
| `amount` | original receipt amount |
| `allocated_amount` | active allocations applied to invoices |
| `designated_amount` | cash designated as credit, net of credit reversals |
| `refund_reserved_amount` | live refund requests (pipeline + in flight + confirmed) |
| `available_amount` | `amount − allocated − designated` (never negative) |
| `refundable_amount` | `available − reserved` (never negative) |
| `status` | `pending` \| `succeeded` \| `failed` \| `cancelled` |
| `verification_status` | `verified` \| `unverified` \| `pending` \| `not_applicable` |
| `received_at` | receipt date (falls back to `created_at`) |
| `reporting_date` | stored reporting date, nullable |
| `confirmed_by` | name of the confirming user, nullable |
| `age_days` | whole days since the receipt date |

### Summary

```json
{
  "by_status": { "succeeded": {"count": 4, "amount": "1550.00"}, "pending": {"count": 1, "amount": "50.00"} },
  "by_method": { "cash": {"count": 1, "amount": "350.00"}, "gateway": {"count": 3, "amount": "1200.00"} },
  "by_provider": { "paystack": {"count": 2, "amount": "1200.00"}, "cash": {"count": 1, "amount": "350.00"} },
  "by_purpose": { "invoice": {"count": 3, "amount": "1400.00"}, "order": {"count": 1, "amount": "150.00"} },
  "totals": { "receipts": 6, "amount": "1620.00", "succeeded_amount": "1550.00" }
}
```

`by_method`, `by_provider` and `by_purpose` count **succeeded** receipts only. `by_status` and `totals.receipts/amount` include every status, so the register can reconcile pending and failed rows. Cash and online keys are always present, even at zero.

### CSV

`GET /api/v1/admin/reports/receipts/export.csv` — permissions `financials.reports.receipts.view` **and** `financials.reports.export`.

Columns: Transaction reference, Provider reference, Payer, Payer type, Order, Purpose, Method, Provider, Currency, Amount, Status, Verification, Received at, Confirmed by, Allocated, Designated credit, Available, Refundable.

---

## 8. Report B — Payment allocations

`GET /api/v1/admin/reports/allocations` — permission `financials.reports.allocations.view`

Extra filters: `status` = `active` \| `reversed` \| `all`; `basis` = `allocation` \| `receipt`. `search` matches receipt reference, allocation/operation reference, invoice number and payer name.

Sort keys: `allocated_at` (default), `amount`. `date_basis`: `allocated_at`.

### Row

| Field | Meaning |
| --- | --- |
| `id` | allocation id |
| `allocation_reference` | allocation operation reference |
| `receipt_reference` | originating receipt |
| `receipt_amount` | receipt total |
| `receipt_allocated_amount` | all active allocations on that receipt |
| `receipt_available_amount` | remaining unallocated, un-designated money |
| `allocation_amount` | this allocation |
| `invoice_reference`, `invoice_id` | target invoice |
| `payer` | `{type, id, name}` |
| `currency` | ISO currency |
| `allocated_at` | allocation timestamp |
| `actor` | staff name snapshot |
| `reversed` | boolean |
| `reversal` | `null`, or `{reference, reversed_at, reason, actor}` |

### Summary

```json
{ "active_amount": "700.00", "reversed_amount": "100.00", "active_count": 2, "reversed_count": 1 }
```

Reversed allocations are reported in their own bucket and are **never** subtracted from `active_amount`.

### CSV

`GET /api/v1/admin/reports/allocations/export.csv` — read + export permission.

Columns: Receipt, Allocation reference, Receipt amount, Receipt allocated, Receipt available, Allocation amount, Invoice, Payer, Currency, Allocated at, Actor, State, Reversal reference, Reversal reason.

---

## 9. Report C — Unallocated receipts

`GET /api/v1/admin/reports/unallocated` — permission `financials.reports.unallocated.view`

Extra filter: `bucket` = `0-7` \| `8-30` \| `31-60` \| `61-90` \| `90+`. `search` behaves as in the receipt register.

Sort keys: `received_at` (default) and `amount`, where `amount` means the **available** amount. `date_basis`: `received_at`.

Only succeeded **invoice** receipts with a positive available balance are returned. Order receipts, pending, failed and cancelled money are excluded.

### Row

Same fields as the receipt register row, plus `age_bucket` (`0-7`, `8-30`, `31-60`, `61-90`, `90+`, or `unknown`).

### Summary

```json
{
  "as_of": "2026-11-28",
  "timezone": "Africa/Accra",
  "buckets": {
    "0-7": {"count": 3, "amount": "600.00"},
    "8-30": {"count": 0, "amount": "0.00"},
    "31-60": {"count": 0, "amount": "0.00"},
    "61-90": {"count": 0, "amount": "0.00"},
    "90+": {"count": 0, "amount": "0.00"}
  },
  "receipts": 3,
  "total_amount": "600.00"
}
```

Age is measured from the receipt date to the period end (decision D8, labelled default). Refund reservations do **not** reduce `total_amount`; they reduce `refundable_amount` on each row.

### CSV

`GET /api/v1/admin/reports/unallocated/export.csv` — read + export permission.

Columns: Receipt, Payer, Payer type, Original amount, Allocated, Designated credit, Available, Currency, Received at, Method, Age (days), Age bucket.

---

## 10. Report D — Accounts receivable

`GET /api/v1/admin/reports/receivables` — permission `financials.reports.receivables.view`

Extra filters: `payment_state` = `unpaid` \| `partially_paid` \| `fully_paid`; `overdue_only` = `1`/`true`. `search` matches invoice number, customer name and organisation name.

Sort keys: `issue_date` (default), `due_date`, `outstanding`. `date_basis`: invoice issue date; ageing as of the period end.

### Row

| Field | Meaning |
| --- | --- |
| `id` | commercial document id |
| `invoice_reference` | invoice number |
| `payer` | `{type, id, name}` |
| `invoice_date` | issue date |
| `due_date` | expiry date, falling back to issue date |
| `currency` | ISO currency |
| `invoice_total` | invoice total |
| `allocated_amount` | active payment allocations |
| `credit_note_applied_amount` | credit-note credit applied to this invoice |
| `credit_applied_amount` | other pooled credit applied (net of credit-note credit) |
| `settled_amount` | allocated + credit applied |
| `outstanding_amount` | `max(0, total − settled)` |
| `payment_state` | `unpaid` \| `partially_paid` \| `fully_paid` |
| `days_overdue` | whole days past the due date (0 when current) |
| `ageing_bucket` | `current` \| `1-30` \| `31-60` \| `61-90` \| `90+` |
| `status` | document status |

Invoice value is not cash revenue; the report never implies cash was received.

### Summary

```json
{
  "as_of": "2026-11-28",
  "buckets": {
    "current": {"count": 1, "outstanding": "700.00"},
    "1-30": {"count": 0, "outstanding": "0.00"},
    "31-60": {"count": 1, "outstanding": "400.00"},
    "61-90": {"count": 0, "outstanding": "0.00"},
    "90+": {"count": 0, "outstanding": "0.00"}
  },
  "invoices": 2,
  "outstanding_total": "1100.00"
}
```

### CSV

`GET /api/v1/admin/reports/receivables/export.csv` — read + export permission.

Columns: Invoice, Payer, Payer type, Invoice date, Due date, Currency, Invoice total, Allocated, Credit note applied, Credit applied, Outstanding, Payment state, Days overdue, Ageing.

---

## 11. Reports E and F — Customer and organisation credit

`GET /api/v1/admin/reports/customer-credit` — permission `financials.reports.customer_credit.view`
`GET /api/v1/admin/reports/organisation-credit` — permission `financials.reports.organisation_credit.view`

Extra filter: `include_zero` = `0` \| `1`. `search` matches payer name and reference.

Sorting: not configurable; rows are ordered by closing balance then payer. `date_basis`: `ledger created_at`; opening balance is everything before the period.

### Portfolio row

| Field | Meaning |
| --- | --- |
| `payer` | `{type, id, name, reference}` |
| `currency` | ISO currency |
| `opening_balance` | balance before the period |
| `credited` | designations + credit-note credit in the period |
| `designated` | cash designated as credit |
| `credit_note_credit` | credit created from credit notes |
| `applied` | credit applied to invoices |
| `reversed` | credit reversals |
| `closing_balance` | `opening + credited − applied − reversed` |
| `last_activity_at` | most recent ledger entry timestamp |

### Totals (`meta.summary`)

```json
{ "payers": 1, "credited": "200.00", "designated": "100.00", "credit_note_credit": "100.00",
  "applied": "100.00", "reversed": "0.00", "closing_balance": "100.00" }
```

### Statement

`GET /api/v1/admin/reports/customer-credit/{customer}` — same permission, `customer` numeric.
`GET /api/v1/admin/reports/organisation-credit/{organisation}` — same permission, `organisation` numeric.

```json
{
  "payer_type": "customer",
  "payer_id": 12,
  "currency": "GHS",
  "period": { "...ReportPeriod meta..." },
  "opening_balance": "0.00",
  "movement": "100.00",
  "closing_balance": "100.00",
  "entries": [
    {
      "id": 91,
      "entry_type": "designation",
      "operation_reference": "credit-4d-0001",
      "amount": "100.00",
      "currency": "GHS",
      "reason": "Overpayment retained as credit",
      "actor": "Staff Name",
      "created_at": "2026-11-28 09:00:00",
      "related_receipt": "RPT-4D-1",
      "related_invoice": "INV-4D-1",
      "related_invoice_type": "invoice",
      "balance_after": "100.00",
      "remaining_attributed": "100.00"
    }
  ]
}
```

`remaining_attributed` is FIFO attribution of a credit-note credit entry, derived from the ledger by `CreditAttribution` (decision D6). The ledger remains the only source of truth.

Statements have no CSV export of their own; the portfolio export covers the report.

### CSV

`GET /api/v1/admin/reports/customer-credit/export.csv`
`GET /api/v1/admin/reports/organisation-credit/export.csv`

Columns: Payer, Reference, Currency, Opening balance, Credited, Cash designated, Credit-note credit, Applied, Reversed, Closing balance, Last activity.

---

## 12. Report G — Refunds

`GET /api/v1/admin/reports/refunds` — permission `financials.reports.refunds.view`

Extra filters:

| Parameter | Values |
| --- | --- |
| `status` | `requested`, `approved`, `submitting`, `submitted`, `confirmed`, `rejected`, `failed` |
| `provider` | string, max 40 |
| `basis` | `requested` (default) \| `confirmed` |
| `search` | refund reference, receipt reference, payer name |

Sorting: not configurable; rows are ordered by the most recent lifecycle timestamp (`confirmed_at`, else `submitted_at`, else `requested_at`), newest first. `date_basis`: `requested_at`, or `confirmed_at` when `basis=confirmed`.

### Row

| Field | Meaning |
| --- | --- |
| `id`, `refund_reference`, `receipt_reference` | identifiers |
| `payer` | `{type, id, name}` |
| `amount`, `currency` | refund amount |
| `status` | raw status |
| `stage` | `pipeline` (requested/approved), `in_flight` (submitting/submitted), `completed` (confirmed), `rejected`, `failed` |
| `reason` | request reason |
| `requesting_staff`, `approving_staff`, `confirming_staff` | name snapshots |
| `provider` | refund provider or original payment provider |
| `provider_submission_status` | `not_submitted` \| `submitted` \| `confirmed` |
| `provider_confirmation_reference` | verification/provider refund reference |
| `requested_at`, `approved_at`, `submitted_at`, `completed_at`, `rejected_at`, `failed_at` | lifecycle timestamps |
| `payment_method` | original receipt method |

Only `confirmed` refunds count as money returned (decision D3). `submitted` is in flight, `approved` is pipeline.

### Summary

```json
{
  "stages": {
    "requested": {"count": 0, "amount": "0.00"},
    "approved": {"count": 1, "amount": "30.00"},
    "pipeline": {"count": 1, "amount": "30.00"},
    "in_flight": {"count": 0, "amount": "0.00"},
    "confirmed": {"count": 1, "amount": "50.00"},
    "rejected": {"count": 0, "amount": "0.00"},
    "failed": {"count": 0, "amount": "0.00"}
  },
  "by_status": { "approved": {"count": 1, "amount": "30.00"}, "confirmed": {"count": 1, "amount": "50.00"} }
}
```

### CSV

`GET /api/v1/admin/reports/refunds/export.csv` — read + export permission.

Columns: Refund reference, Receipt, Payer, Payer type, Amount, Currency, Status, Stage, Reason, Requested by, Approved by, Provider, Provider submission, Confirmation reference, Requested at, Completed at.

---

## 13. Report H — Credit notes

`GET /api/v1/admin/reports/credit-notes` — permission `financials.reports.credit_notes.view`

Extra filters: `status` = `draft` \| `issued` \| `voided`; `basis` = `issued` (default) \| `created`. `search` matches credit-note number, invoice number and payer name.

Sorting: not configurable. `date_basis`: `issued_at`.

### Row

| Field | Meaning |
| --- | --- |
| `id`, `credit_note_number` | identifiers |
| `original_invoice`, `original_invoice_total` | originating invoice |
| `payer` | `{type, id, name}` |
| `reason` | note reason (`notes`) |
| `amount`, `currency` | credit note total |
| `applied_to_origin_amount` | amount auto-applied to the originating invoice (`cn:<number>:apply`) |
| `remaining_amount` | FIFO attribution of the note’s credit entry |
| `attribution_basis` | fixed explanatory string |
| `issued_at`, `issue_date` | issue date |
| `issuing_staff` | issuer name snapshot |
| `status` | `draft` \| `issued` \| `voided` |
| `application_status` | `applied` \| `partially_applied` \| `unapplied` \| `voided` |
| `voided_at`, `void_reason` | void metadata |

`remaining_amount` and `applied_to_origin_amount` are both published and labelled because pooled FIFO attribution can leave a note’s own credit entry unconsumed while older credit is consumed (decision D6). A credit note is not a cash refund.

### Summary

```json
{ "issued_amount": "100.00", "issued_count": 1, "voided_amount": "0.00", "voided_count": 0 }
```

### CSV

`GET /api/v1/admin/reports/credit-notes/export.csv` — read + export permission.

Columns: Credit note, Original invoice, Payer, Payer type, Amount, Applied to originating invoice, Remaining (FIFO), Currency, Reason, Issued at, Issuing staff, Status, Application, Voided at, Void reason.

---

## 14. Report I — Financial activity and audit

`GET /api/v1/admin/reports/activity` — permission `financials.reports.activity.view`

Extra filter: `event_type` = any of `receipt`, `allocation`, `allocation_reversal`, `credit_designation`, `credit_application`, `credit_reversal`, `refund`, `refund_confirmed`, `credit_note_issued`, `credit_note_voided`. A prefix such as `refund` matches every `refund_*` event. `search` matches reference, subject, actor and reason.

Sorting: not configurable (chronological, newest first). `date_basis`: event time.

### Row

| Field | Meaning |
| --- | --- |
| `id` | source row id |
| `event_at` | event timestamp |
| `event_type` | normalised type (see list above) |
| `reference` | transaction / allocation / refund / note reference |
| `subject` | secondary reference |
| `actor` | actor name snapshot |
| `amount`, `currency` | amount when the event has one, otherwise `null` |
| `reason` | reason/notes when recorded |

The report is read-only. Corrections happen through the existing Stage 4A–4C audited operations, never by editing history.

### Summary

Empty (`{}`): the activity report publishes no aggregate.

### CSV

`GET /api/v1/admin/reports/activity/export.csv` — read + export permission.

Columns: Event at, Type, Reference, Subject, Actor, Amount, Currency, Reason.

---

## 15. CSV export contract

- One export route per report: `GET /api/v1/admin/reports/<report>/export.csv`.
- Authorisation: the report’s read permission **and** `financials.reports.export`. Holding export alone is not enough.
- Response: streamed `text/csv; charset=UTF-8` attachment.
- Filename: `stage4d-<report>-<from>-to-<to>.csv`, e.g. `stage4d-receipts-2026-10-30-to-2026-11-28.csv`.
- Rows: the same rows and filters as the JSON report, ordered by primary id, capped at **50,000 rows**.
- Header row is always written.
- Every cell is escaped against spreadsheet formula injection: a value starting with `=`, `+`, `-`, `@`, tab or carriage return is prefixed with `'`. A receipt reference `=EVIL()` is exported as `'=EVIL()` (asserted by `test_exports_use_the_same_rows_and_escape_formula_injection`).
- The 366-day period clamp also bounds exports.

---

## 16. Reconciliation

All reconciliation routes live under `/api/v1/admin/reconciliation`.

### 16.1 Snapshot

`GET /admin/reconciliation` — permission `financials.reconciliation.view`

Query: `from`, `to`, `currency` (no pagination, no search).

```json
{
  "success": true,
  "data": {
    "period": { "from": "...", "to": "...", "date_basis": "receipt date for receipts; invoice issue date for invoices", "timezone": "Africa/Accra", "days": 30, "comparison": {...} },
    "currency": "GHS",
    "receipts": {
      "receipts": 3, "receipt_amount": "620.00", "applied_amount": "300.00", "available_amount": "320.00",
      "fully_allocated": 1, "partially_allocated": 0, "unallocated": 2, "inconsistent": 0,
      "reversed_allocations": 0, "reversed_amount": "0.00"
    },
    "invoices": {
      "invoices": 1, "invoice_total": "300.00", "allocated_amount": "300.00",
      "credit_applied_amount": "0.00", "outstanding_amount": "0.00",
      "unpaid": 0, "partially_paid": 0, "fully_paid": 1, "over_settled": 0
    },
    "exceptions": [
      {"code": "E-01", "label": "Receipts with a negative available balance", "count": 0},
      {"code": "E-05", "label": "Refunds awaiting a provider outcome", "count": 0},
      {"code": "E-07", "label": "Invoices settled beyond their total", "count": 0},
      {"code": "E-08", "label": "Successful receipts without a received date", "count": 0},
      {"code": "TOTAL", "label": "Total exceptions", "count": 0}
    ],
    "settlements": {
      "available": false,
      "tier": "not_configured",
      "reason": "No genuine provider settlement feed is configured. Payment verification confirms that a provider captured a payment; it does not report settlement batches, provider fees or bank deposits.",
      "required_data": ["settlement batch reference", "provider", "currency", "settlement date", "gross amount", "provider fees", "net settlement amount", "matched transactions"],
      "decisions_pending": ["D4", "D5", "D12", "D13"]
    }
  },
  "meta": { "date_basis": "receipt date for receipts; invoice issue date for invoices" },
  "generated_at": "..."
}
```

Receipt classification uses succeeded **invoice** receipts with a received date:

- `fully_allocated`: applied > 0 and available = 0.
- `partially_allocated`: applied > 0 and available > 0.
- `unallocated`: applied = 0.
- `inconsistent`: available < 0 (exception E-01).

Invoice classification uses issued/expired, non-voided invoices:

- `unpaid`, `partially_paid`, `fully_paid` from settled (allocations + applied credit) versus total.
- `over_settled`: settled > total (exception E-07).

### 16.2 Settlement availability

`GET /admin/reconciliation/settlements` — permission `financials.reports.settlements.view`

Returns `{"success": true, "data": {...same settlements object as above...}}`.

This is the complete implemented settlement surface. There is no batch, fee, deposit or payout figure, and no import endpoint. External settlement reconciliation stays unavailable until a genuine provider settlement feed and decisions D4, D5, D12 and D13 are resolved.

### 16.3 Run history

`GET /admin/reconciliation/runs` — permission `financials.reconciliation.view`

Query: `status`, `source`, `from`, `to`, `per_page` (1–100, default 20).

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "reference": "REC-2026-11-01-2026-11-28-AB12CD",
      "source": "internal_combined",
      "period_start": "2026-11-01",
      "period_end": "2026-11-28",
      "status": "awaiting_review",
      "prepared_by": "Staff Name",
      "prepared_at": "2026-11-28 10:00:00",
      "reviewed_by": null,
      "reviewed_at": null,
      "exception_count": 1,
      "resolution_notes": null,
      "immutable": false
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

`from`/`to` overlap the run period (`period_end >= from`, `period_start <= to`). History is newest first. Rows never include the stored snapshot; the prepare/review responses do.

### 16.4 Prepare a run

`POST /admin/reconciliation/runs` — permission `financials.reconciliation.manage` (throttled 20/min)

Body: `from`, `to`, `currency` (same rules as the snapshot).

- Captures the snapshot above into `reconciliation_runs.summary`.
- Creates the run with `status = awaiting_review`, `source = internal_combined`, `prepared_by`, `prepared_by_name_snapshot`, `prepared_at`, `exception_count` = the `TOTAL` exception count, and a reference `REC-<from>-<to>-<6 random chars>`.
- Writes an audit log entry `financials.reconciliation.prepared`.
- Returns **201** with the run plus `summary`.

### 16.5 Review and close a run

`POST /admin/reconciliation/runs/{run}/review` — permission `financials.reconciliation.review` (throttled 20/min)

Body:

| Field | Rule |
| --- | --- |
| `status` | required, `reconciled` \| `reconciled_with_exceptions` |
| `resolution_notes` | nullable string, max 2000 |

Rules enforced by `ReconciliationService::review()`:

- Only the two closing statuses are accepted.
- An unknown run returns 422.
- A run already closed (`reconciled`, `reconciled_with_exceptions`) cannot be edited; 422.
- A run with `exception_count > 0` cannot be closed as `reconciled`; it must be `reconciled_with_exceptions`; 422.
- On success: `reviewed_by`, `reviewed_by_name_snapshot`, `reviewed_at`, `resolution_notes` are stored, the run becomes `immutable`, and audit action `financials.reconciliation.reviewed` is written.
- Returns 200 with the updated run plus `summary`.

There is no delete, update, reopen or export endpoint. History is append-only.

---

## 17. Contact visibility

Receipt, unallocated and allocation rows expose `payer.email` and `payer.phone` only when:

- the caller is `super-admin`, or
- the caller holds `customers.view_financials` (customer payers) or `organisations.view_financials` (organisation payers).

Without it the keys are absent (not null). Covered by `test_contact_details_are_withheld_without_the_payer_financial_permission`.

---

## 18. Read-only guarantees and boundaries

- No report route writes data. The only Stage 4D writes are `reconciliation_runs` (prepare/review) and the two audit entries.
- Reports never call Stage 4A–4C mutation services.
- No second ledger, balance table, snapshot table or cached aggregate exists.
- No settlement data is fabricated, estimated or imported.
- The API does not expose a “profit”, “net revenue” or cross-currency total.
