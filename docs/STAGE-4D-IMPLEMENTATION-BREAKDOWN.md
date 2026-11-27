# Stage 4D — Proposed Implementation Breakdown

**Status:** Proposal — implementation is not authorised until the owner approves the scope.
**Date:** 2026-11-27
**Prerequisite:** conditions C1–C4 in `docs/STAGE-4D-INITIAL-DISCOVERY-REPORT.md` §12.

Each phase is independently shippable, keeps the app bootable, adds no destructive migration, and ends with its own tests and acceptance evidence.

---

## Phase 4D-0 — Branch, schema and kernel guard (no UI)

**Deliverables**

- Branch created from the accepted Stage 4C head (`a6489c6` or the post-merge `main`).
- Capability check asserting the 4C tables/columns exist; affected reports fail closed with an explicit state if not.
- Additive migrations:
  - indexes: `payment_allocations(allocated_at, commercial_document_id)`, `customer_credit_ledger_entries(created_at, entry_type)`, `refund_requests(requested_at)`, `refund_requests(provider_refund_reference)`, `payment_transactions(payment_purpose, received_at, status)`, `payment_transactions(provider, received_at)`;
  - reconciliation tables: `settlement_imports`, `settlement_lines`, `settlement_matches`, `reconciliation_notes` (design §8).
- Rollback-verified migrations (existing `MigrationRollbackVerificationTest` pattern).

**Exit criteria:** migrations up/down clean on SQLite and MySQL; no 4A/4B/4C test regression; `EXPLAIN` shows the new indexes used for their target queries.

**Risk:** low. **Depends on:** C1.

---

## Phase 4D-1 — Reporting kernel

**Deliverables**

- `app/Services/Reporting/` read-only services:
  - `Money` (thin wrapper over `PaymentAllocationService` minor-unit helpers),
  - `ReportFilters` (validation + normalisation shared by screen and export),
  - `ReportPeriod` (timezone-aware ranges, `date_basis` handling),
  - `CreditAttribution` (batched FIFO, parity-tested against `CustomerCreditReversalService::remainingReversibleMinor()`),
  - `FinancialPosition` (the §3 identities, including exception detection).
- Base controller with the response envelope (`success`, `data`, `meta`, `generated_at`, `date_basis`, `currency_partition`).
- Unit tests for money, periods, filters and FIFO parity.

**Exit criteria:** INV-1…INV-9 pass on the fixture; parity test green; no endpoint yet.

**Risk:** medium (FIFO parity). **Depends on:** 4D-0.

---

## Phase 4D-2 — Allocation register and unallocated payments

**Deliverables**

- `AllocationRegisterService`, `UnallocatedPaymentService`, `AllocationReportController`, `UnallocatedReportController`.
- Routes + permissions `financials.reports.allocations.view`, `financials.reports.unallocated.view`.
- Pages `/admin/reports/allocations`, `/admin/reports/unallocated` with `ReportShell`, `ResponsiveTable`, row expansion (allocation history), ageing summary.
- CSV export for both.

**Exit criteria:** G1 and G4 regressions pass; ageing buckets reconcile to the total; permission tests pass; responsive captures at 375/675/1024/1440.

**Risk:** medium. **Depends on:** 4D-1.

---

## Phase 4D-3 — Customer-credit reports

**Deliverables**

- `CustomerCreditReportService` (statement + portfolio + ageing), controller, routes, permission `financials.reports.customer_credit.view`.
- Pages `/admin/reports/customer-credit` and the payer statement view.
- Export.

**Exit criteria:** INV-2 and INV-6 pass; opening + movement = closing; no editable balance; authorizer columns present.

**Risk:** medium (attribution). **Depends on:** 4D-1.

---

## Phase 4D-4 — Refund register and exceptions

**Deliverables**

- `RefundReportService`, controller, routes, permission `financials.reports.refunds.view`.
- Page `/admin/reports/refunds` with stage summary, register, timeline drawer, unresolved-outcomes panel.
- Exception endpoint `financials.reports.exceptions.view` covering E-05 (and the other exceptions as they land).
- Export.

**Exit criteria:** every status appears exactly once in its stage total; `submitting` never in confirmed; initiator/approver/confirmer verified; permission tests.

**Risk:** low-medium. **Depends on:** 4D-1, 4C.

---

## Phase 4D-5 — Credit-note register

**Deliverables**

- `CreditNoteReportService`, controller, routes, permission `financials.reports.credit_notes.view`.
- Page `/admin/reports/credit-notes` with issued/applied/remaining, void history, authorizer.
- Export.

**Exit criteria:** INV-7 passes; invoices unmodified; convention-derived “applied to origin” labelled; E-03 exception raised on mismatch.

**Risk:** medium (attribution). **Depends on:** 4D-1, 4D-3 (attribution), 4C.

---

## Phase 4D-6 — Financial dashboard

**Deliverables**

- `DashboardService` composing 4D-2…4D-5 aggregates into the eight blocks plus the position bridge.
- Page `/admin/reports` (hub + dashboard), permission `financials.reports.dashboard.view`.
- CSS/SVG trend rendering from tokens (no new dependency).
- Export of the dashboard summary.

**Exit criteria:** each block equals its report for identical filters; INV-1 rendered and passing; empty state correct; permission tests.

**Risk:** medium (composition). **Depends on:** 4D-2, 4D-3, 4D-4, 4D-5.

---

## Phase 4D-7 — Settlement import and reconciliation

**Deliverables**

- `SettlementImportService` (CSV parse, checksum idempotency, validation), `ReconciliationMatcher` (ordered rules), `ReconciliationService` (match/unmatch/manual, audit).
- Controllers + routes; permissions `financials.reports.settlements.view`, `financials.reconciliation.manage`, optional `financials.reconciliation.approve`.
- Pages `/admin/reports/reconciliation` (read) and the workbench (import, unmatched queue, match detail, manual settle).
- Synthetic fixture statement for tests only.

**Exit criteria:** duplicate import rejected; all eight match statuses classified correctly; manual settlement requires evidence and audits; unmatched never counted as matched; “not configured” state correct.

**Risk:** high (matching semantics). **Depends on:** 4D-0 schema, D4/D5/D12 decisions.

---

## Phase 4D-8 — Exports, performance, acceptance, documentation

**Deliverables**

- Export parity suite for every report; caps and escaping.
- Performance suite with seeded dataset; `EXPLAIN` evidence recorded.
- Browser acceptance at four breakpoints, light and dark.
- Documentation updates and acceptance report.
- `docs/STAGE-4D-ACCEPTANCE-REPORT.md`.

**Exit criteria:** all §11 gates of the testing strategy pass; documentation merged.

**Risk:** low. **Depends on:** all previous phases.

---

## Dependency graph

```
4D-0 ── 4D-1 ── 4D-2 ──┐
            ├── 4D-3 ──┼── 4D-6 ── 4D-8
            ├── 4D-4 ──┤
            └── 4D-5 ──┘
4D-0 ── 4D-7 ─────────────┘
```

---

## Relative effort

| Phase | Relative size | Notes |
| --- | --- | --- |
| 4D-0 | S | migrations + guard |
| 4D-1 | M | kernel + parity |
| 4D-2 | M | two reports + history UI |
| 4D-3 | M | statement + ageing |
| 4D-4 | S–M | register + exceptions |
| 4D-5 | M | attribution reuse |
| 4D-6 | M | composition + bridge |
| 4D-7 | L | import, matching, audit |
| 4D-8 | M | acceptance + docs |

Sizes are relative, not calendar estimates; no schedule is committed before owner approval.

---

## Guardrails for every phase

- No change to Stage 4A/4B/4C business rules or services beyond additive reuse.
- No destructive migration; no `migrate:fresh` outside the disposable test database.
- No report route writes data.
- No second balance or ledger.
- No invented settlement data.
- The app stays bootable; each phase is merged only with its tests green.
- Documentation and acceptance evidence land with the phase, not afterwards.
