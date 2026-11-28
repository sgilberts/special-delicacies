# Stage 4D — Implementation Plan and Status

**Date:** 2026-11-28
**Branch:** `org-support-upgrade`
**Baseline:** `main` at `067e87d` (PR #3 merge), branch head before this work `e83de09`.
**Companion documents:** `docs/STAGE-4D-DECISION-REGISTER.md`, `docs/STAGE-4D-API.md`, `docs/STAGE-4D-RECONCILIATION-GUIDE.md`, `docs/STAGE-4D-IMPLEMENTATION-REPORT.md`.

The phase breakdown follows the approved sequence in the Stage 4D authorisation. Each phase was implemented additively: no Stage 4A–4C rule, table or service was changed, and no second ledger was introduced.

---

## 4D-0 — Baseline and decision verification — **done**

- Verified branch, HEAD, clean working tree and remote synchronisation before starting.
- Read the existing Stage 4D discovery, design, metrics, permissions and testing documents.
- Recorded every open owner decision and how the authorisation resolved or left it open (`STAGE-4D-DECISION-REGISTER.md`).
- No destructive operation was run; the protected development database was never reset.

## 4D-1 — Reporting kernel and calculation contracts — **done**

Delivered:

- `app/Services/Reporting/Money.php` — signed minor-unit conversion for SQL aggregates.
- `app/Services/Reporting/ReportPeriod.php` — timezone-aware ranges with end-of-day inclusion, 366-day cap and previous-period derivation.
- `app/Services/Reporting/ReceiptLedger.php` — the single per-receipt derivation (allocated, designated, refund-reserved, available, refundable) as SQL subqueries, plus the shared filters.
- `app/Services/Reporting/CreditAttribution.php` — FIFO attribution parity-tested against Stage 4C.
- `app/Services/Reporting/CsvExporter.php` — streamed CSV with formula-injection escaping.

Contracts are exercised by `Stage4dReportingKernelTest`.

## 4D-2 — Backend reporting services — **done**

- `DashboardService` — overview, comparisons, daily/weekly/monthly trends, operational summaries, exceptions.
- `ReceiptRegisterService`, `AllocationReportService`, `UnallocatedReportService`, `ReceivableReportService`, `CreditReportService`, `RefundReportService`, `CreditNoteReportService`, `ActivityReportService`.
- `ReconciliationService` — internal receipt/invoice reconciliation, run preparation and review, settlement availability state.

## 4D-3 — Reporting APIs and permissions — **done**

- 21 report routes and 5 reconciliation routes, all inside the existing Sanctum admin group.
- 13 new permissions; granted to `super-admin` only.
- CSV export requires the report's read permission **and** `financials.reports.export`.
- Contact details are withheld unless the caller holds `customers.view_financials` / `organisations.view_financials`.

## 4D-4 — Financial management dashboard — **done**

- `/admin/financials/dashboard`: twelve separated figures with their basis, previous-period comparison, daily/weekly/monthly charts, operational panels and the exceptions list.

## 4D-5 — Detailed financial reports — **done**

- `/admin/financials/reports/{receipts|allocations|unallocated|receivables|customer-credit|organisation-credit|refunds|credit-notes|activity}` with search, filters, date range, sorting where meaningful, pagination, CSV export and a credit statement drawer.

## 4D-6 — Internal reconciliation — **done**

- Receipt reconciliation (fully/partially/unallocated, reversed allocations, inconsistent records).
- Invoice reconciliation (active allocations, applied credit, outstanding, over-settled).
- Auditable runs: prepared-by, reviewed-by, timestamps, exception count, resolution notes; closed runs are read-only.

## 4D-7 — External settlement reconciliation — **blocked (documented)**

- No genuine settlement feed exists for Paystack, Hubtel or Korapay in this repository.
- The API returns `available: false`, tier `not_configured`, the missing data list and the pending decisions.
- No settlement batch, fee, deposit or payout is estimated; no import workflow was built.

## 4D-8 — Integration, regression and acceptance — **done**

- Full backend suite on SQLite and on a disposable MySQL 8.4 database.
- Frontend production build.
- Browser acceptance with a real, API-created financial scenario, then removed.
- Documentation set (this file plus the four companion documents).

---

## Guardrails honoured

- No change to Stage 4A/4B/4C business rules; the only edits outside the new `Reporting` namespace are the routes file, the migration and additive service reuse.
- No destructive migration; the new migration only creates permissions, indexes and `reconciliation_runs`.
- No report route writes data; the only writes are reconciliation runs, which are append-only and closed once reviewed.
- No second balance or ledger: every balance is derived from the existing tables.
- No invented settlement data.
