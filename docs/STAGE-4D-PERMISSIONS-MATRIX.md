# Stage 4D — Permissions Matrix

**Status:** Proposed — no permission, role or middleware was changed.
**Date:** 2026-11-27
**Related decisions:** D1 (granularity), D2 (default holders), D12 (reconciliation approval).

---

## 1. Existing financial permission inventory

| Permission | Grants | Mutates data |
| --- | --- | --- |
| `financials.view` | Financial dashboard aggregates | no |
| `financials.transactions.view` | Order transaction register + detail | no |
| `financials.reports.view` | Financial reports (currently the snapshot) | no |
| `financials.reports.export` | CSV export of financial transactions | no |
| `financials.reconciliation.view` | Reconciliation queue (currently the transaction list) | no |
| `financials.allocations.view` | Allocation workbench reads | no |
| `financials.allocations.manage` | Create allocations | yes |
| `financials.allocations.reverse` | Reverse an allocation | yes |
| `financials.deposits.view` / `.manage` | Deposit/advance receipts; classify | read / yes |
| `financials.customer_credit.view` / `.manage` | Credit balances, ledger; designate/apply | read / yes |
| `financials.invoice_balances.view` | Derived invoice balances | no |
| `financials.payments.confirm_cash` | Confirm cash collection | yes |
| `financials.refunds.view` / `.initiate` / `.approve` | Refund queue; request; approve/submit/confirm/fail/reconcile | read / yes / yes |
| `financials.credit_notes.view` / `.create` / `.approve` | Credit notes; draft; issue/void | read / yes / yes |
| `financials.credit_reversals.view` / `.initiate` / `.approve` | Credit reversals; request; approve/reject | read / yes / yes |

Default holders today: `super-admin` holds everything; the `admin` role is seeded **without** any `financials.*` permission; operational roles hold none.

---

## 2. Proposed Stage 4D permissions

Read-only reporting (all backend-enforced, no writes):

| Permission | Guards |
| --- | --- |
| `financials.reports.dashboard.view` | `/admin/reports/dashboard` and the dashboard block endpoints |
| `financials.reports.allocations.view` | `/admin/reports/allocations` |
| `financials.reports.unallocated.view` | `/admin/reports/unallocated` |
| `financials.reports.customer_credit.view` | `/admin/reports/customer-credit` and statements |
| `financials.reports.refunds.view` | `/admin/reports/refunds` |
| `financials.reports.credit_notes.view` | `/admin/reports/credit-notes` |
| `financials.reports.settlements.view` | `/admin/reports/reconciliation` (read side) |
| `financials.reports.exceptions.view` | `/admin/reports/exceptions` (integrity + unresolved outcomes) |

Export: reuse the existing `financials.reports.export`, applied **in addition to** the report's read permission. A user without the read permission cannot export that report even with the export permission.

Reconciliation mutation (separate, audited):

| Permission | Guards |
| --- | --- |
| `financials.reconciliation.manage` | Statement import, match/unmatch, manual settle, resolution notes |
| `financials.reconciliation.approve` | Approving a manual settlement above a configured threshold (optional, decision D12) |

Design rules:

- No permission is reused for a mutation it does not describe; `financials.reconciliation.view` stays read-only.
- `financials.reports.settlements.view` never implies `financials.reconciliation.manage`.
- No Stage 4D permission is granted to the `admin` role automatically.

---

## 3. Capability matrix

Legend: **R** = read, **M** = mutation, **X** = export, — = no access.

| Capability | super-admin | finance-manager (proposed) | finance-officer (proposed) | auditor (proposed) | admin | operations roles |
| --- | --- | --- | --- | --- | --- | --- |
| Financial dashboard | R | R | R | R | — | — |
| Payment allocation register | R | R | R | R | — | — |
| Unallocated payments | R | R | R | R | — | — |
| Customer-credit reports | R | R | R | R | — | — |
| Refund reports | R | R | R | R | — | — |
| Credit-note reports | R | R | R | R | — | — |
| Settlement / reconciliation read | R | R | — | R | — | — |
| Integrity exceptions | R | R | R | R | — | — |
| Report export | X | X | X | X | — | — |
| Statement import | M | M | — | — | — | — |
| Match / unmatch / manual settle | M | M | — | — | — | — |
| Approve manual settlement (optional) | M | M | — | — | — | — |
| Existing operational finance (allocate, refund, credit) | R/M | per existing grant | per existing grant | — | — | — |

The proposed roles are **new, optional** roles; the recommendation is to create them rather than widen `admin`. If the owner prefers no new roles, assign the Stage 4D permissions to named users or to a renamed finance role (D2).

---

## 4. Route-level enforcement

| Route | Middleware |
| --- | --- |
| `GET /admin/reports/dashboard` | `permission:financials.reports.dashboard.view` |
| `GET /admin/reports/allocations` | `permission:financials.reports.allocations.view` |
| `GET /admin/reports/unallocated` | `permission:financials.reports.unallocated.view` |
| `GET /admin/reports/customer-credit[/{type}/{id}]` | `permission:financials.reports.customer_credit.view` |
| `GET /admin/reports/refunds` | `permission:financials.reports.refunds.view` |
| `GET /admin/reports/credit-notes` | `permission:financials.reports.credit_notes.view` |
| `GET /admin/reports/reconciliation` | `permission:financials.reports.settlements.view` |
| `GET /admin/reports/exceptions` | `permission:financials.reports.exceptions.view` |
| `GET /admin/reports/{report}/export.csv` | report read permission **and** `permission:financials.reports.export` |
| `POST /admin/reconciliation/*` | `permission:financials.reconciliation.manage` + `throttle` |
| `POST /admin/reconciliation/lines/{id}/approve` | `permission:financials.reconciliation.approve` (if enabled) |

All routes stay inside the existing authenticated admin group (Sanctum). No report route is public.

---

## 5. Frontend gating (cosmetic, mirrors backend)

- `AdminLayout` adds a “Reports” section only when the user holds at least one `financials.reports.*` permission.
- Each report route is wrapped in `ProtectedRoute` with its read permission.
- Export buttons render only with `financials.reports.export` **and** the report permission.
- Reconciliation mutation controls render only with `financials.reconciliation.manage`.
- Hiding a control is never treated as authorization; the backend returns 403 independently.

---

## 6. Separation of duties

- Reporting is read-only, so separation of duties does not apply to it.
- Reconciliation mutations follow the existing financial pattern: actor snapshot, reason, audit entry, and (if D12 is approved) a different approver above a threshold.
- A user who imports a statement may not approve their own manual settlement when `financials.reconciliation.approve` is enabled and the threshold is exceeded.

---

## 7. Sensitive-data handling

| Field | Dashboard | Registers | Exports |
| --- | --- | --- | --- |
| Payer name | aggregated counts only | shown | shown |
| Payer email/phone | not shown | shown only where needed for identification | shown only if the report requires it |
| Provider reference | not shown | shown | shown |
| Verification evidence (refund) | not shown | shown to approvers only | excluded |
| Bank/merchant credentials | never | never | never |

CSV cells are escaped against formula injection using the existing `csvSafe()` pattern.
