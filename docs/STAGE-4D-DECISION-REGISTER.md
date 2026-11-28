# Stage 4D — Decision Register (implementation record)

**Source of truth for the questions:** `docs/STAGE-4D-OWNER-DECISION-REGISTER.md` (unchanged).
**Date:** 2026-11-28
**Branch:** `org-support-upgrade`
**Rule applied:** the owner's Stage 4D authorisation was not treated as permission to invent financial policy. Each decision below records the exact question, the options previously proposed, the financial/technical consequence, the conservative default, whether it blocked a feature, and whether the owner's instruction explicitly resolved it.

Status vocabulary:

- **Resolved by instruction** — the owner's Stage 4D authorisation states the required behaviour explicitly.
- **Implemented with a labelled conservative default** — no explicit instruction; the previously recommended conservative option is used, displayed in the UI, and remains open for the owner to change.
- **Not exercised** — the blocked capability was not built, so the decision has no effect yet.
- **Open** — no explicit owner answer; affects a capability that is not implemented or only partially labelled.

---

## 1. Summary

| ID | Question (short) | Status | Blocks |
| --- | --- | --- | --- |
| D1 | Report permission granularity | Resolved by instruction (granular) | — |
| D2 | Default holders of reporting permissions | Resolved by instruction (super-admin only; no automatic grant) | — |
| D3 | What “refunded” means in reports | Resolved by instruction (confirmed only; pipeline separate) | — |
| D4 | Settlement data source for v1 | Resolved by instruction (no feed → document only, no fabricated data) | 4D-7 |
| D5 | Gateway fee handling | Not exercised (no settlement lines exist) | 4D-7 |
| D6 | Credit-note remaining attribution | Resolved by instruction (ledger is the only source; FIFO derived) | — |
| D7 | Default date basis per report | Implemented with labelled conservative default | — |
| D8 | Unallocated ageing definition | Implemented with labelled conservative default | — |
| D9 | Gross receipts scope | Resolved by instruction (all succeeded receipts, split by method/purpose) | — |
| D10 | Legacy/backfill treatment | Implemented with labelled conservative default (included, never rewritten) | — |
| D11 | Export formats | Resolved by instruction (CSV) | — |
| D12 | Reconciliation approval separation | Not exercised (no manual settlement actions exist) | 4D-7 |
| D13 | Statement retention | Not exercised (no statement import exists) | 4D-7 |
| D14 | Multi-currency policy | Resolved by instruction (per-currency only, no cross-currency total) | — |

---

## 2. Decision detail

### D1 — Report permission granularity

- **Question:** one read permission per report, or a single `financials.reports.view`?
- **Options:** (1) granular read permission per report; (2) reuse one reporting permission.
- **Consequence:** option 1 creates a larger matrix but lets the owner grant, for example, the receipt register without granting refund or settlement detail.
- **Conservative default (previously recommended):** option 1.
- **Owner instruction:** §11 of the Stage 4D authorisation requires granular permissions for dashboard, receipt, allocation, receivable, credit, refund, credit-note, audit and reconciliation viewing, and forbids silently granting them to ordinary administrators.
- **Status:** **Resolved by instruction** — option 1 implemented.
- **Implemented as:** `financials.reports.dashboard.view`, `.receipts.view`, `.allocations.view`, `.unallocated.view`, `.receivables.view`, `.customer_credit.view`, `.organisation_credit.view`, `.refunds.view`, `.credit_notes.view`, `.activity.view`, `.settlements.view`, `financials.reconciliation.manage`, `financials.reconciliation.review`.

### D2 — Default holders of reporting permissions

- **Question:** who receives the new reporting permissions by default?
- **Options:** (1) new optional finance roles, `admin` unchanged; (2) grant reads to the existing `admin` role; (3) named users only.
- **Consequence:** option 2 gives every administrator refund, credit and settlement visibility, contradicting the existing least-privilege posture.
- **Conservative default:** option 1.
- **Owner instruction:** “Do not silently grant permissions to ordinary administrators or operational staff” and “Update permission seeders without stripping existing permissions during re-seeding.”
- **Status:** **Resolved by instruction** — permissions created and granted to `super-admin` only, using `syncWithoutDetaching` so re-seeding never strips them. No new role was created (role creation remains an owner decision made in the UI).

### D3 — Meaning of “refunded”

- **Question:** which refund states may be described as refunded?
- **Options:** (1) `confirmed` only; (2) `submitted` + `confirmed`; (3) every state with a status column.
- **Consequence:** option 2 would overstate returned cash because Stage 4C never auto-confirms provider outcomes.
- **Conservative default:** option 1, with pipeline (`requested`, `approved`) and in-flight (`submitting`, `submitted`) reported separately.
- **Owner instruction:** “Do not treat an approved refund as completed cash leaving the business” and “Never treat a pending payment as successfully received.”
- **Status:** **Resolved by instruction** — `refunds_completed` and the refund report's confirmed stage contain `confirmed` only; separate `pipeline` and `in_flight` figures are published.

### D4 — Settlement data source for v1

- **Question:** what is the reconciliation source for the first release?
- **Options:** (1) imported statement + manual entry only, no automated claim; (2) automated provider API; (3) manual only.
- **Consequence:** no provider integration in this repository exposes settlement batches, fees or payouts; claiming automated reconciliation would fabricate evidence.
- **Conservative default:** option 1.
- **Owner instruction:** §7C — “Where no genuine settlement feed exists, implement only the verified internal reporting foundation and document the missing external data dependency. Do not fabricate settlement batches, settlement fees, bank deposits or payout references.”
- **Status:** **Resolved by instruction** — settlement reporting is implemented as an explicit “not configured” state naming the missing data and the pending decisions. No import workflow, settlement table or fabricated figure exists.
- **Blocks:** 4D-7 (external settlement reconciliation).

### D5 — Gateway fee handling

- **Question:** how are provider fees recorded and who may change them?
- **Options:** (1) store gross, fee and net per settlement line, audited edits; (2) record net only; (3) derive fees from a percentage.
- **Consequence:** option 3 invents fees; option 2 hides the cost.
- **Conservative default:** option 1.
- **Status:** **Not exercised** — no settlement line exists because there is no settlement feed (D4). No fee table was created, so no fee policy is implied.

### D6 — Credit-note remaining attribution

- **Question:** how is “remaining credit-note amount” determined when credit is pooled per payer and consumed FIFO?
- **Options:** (1) derived FIFO reuse of the 4C algorithm; (2) a new attribution table; (3) report only the note total.
- **Consequence:** option 2 creates a second attribution record that can drift from the ledger.
- **Conservative default:** option 1.
- **Owner instruction:** “Use the existing append-only credit ledger as the source of truth” and “Do not introduce a second source of truth for financial balances.”
- **Status:** **Resolved by instruction** — `CreditAttribution` mirrors `CustomerCreditReversalService::remainingReversibleMinor()`; a parity test asserts equality. The credit-note report additionally publishes the convention-derived “applied to originating invoice” amount (metric M-85) and labels the basis.

### D7 — Default date basis per report

- **Question:** which date drives each report by default?
- **Options (previously recommended):** receipts/dashboard `received_at`; allocations receipt date; unallocated receipt date; credit ledger `created_at`; refunds `requested_at`; credit notes `issued_at`; reconciliation settlement date.
- **Consequence:** a mixed basis without disclosure makes figures incomparable.
- **Conservative default:** the table above, with the basis displayed on every page and recorded in the export header row.
- **Status:** **Implemented with a labelled conservative default.** The owner has not confirmed the basis explicitly; each report states it and offers the documented alternative where one exists (allocations: allocation/receipt date; refunds: requested/confirmed; credit notes: issued/created).

### D8 — Unallocated ageing definition

- **Question:** from which date, in which buckets?
- **Options:** age from receipt date; age from last allocation date.
- **Conservative default:** receipt date, buckets 0–7, 8–30, 31–60, 61–90, 90+ days, `Africa/Accra`.
- **Status:** **Implemented with a labelled conservative default** (receipt date, those buckets).

### D9 — Gross receipts scope

- **Question:** what belongs in gross receipts?
- **Options:** (1) all succeeded receipts split by purpose and method, delivery fees a labelled subset; (2) order receipts only; (3) exclude delivery fees.
- **Consequence:** option 2/3 hides cash actually received.
- **Conservative default:** option 1.
- **Owner instruction:** §4 requires successful cash, successful online payments and invoice receivables to stay distinct, and forbids treating invoice value as cash revenue.
- **Status:** **Resolved by instruction** — successful receipts are reported in total and split by method (`cash`/`gateway`) and purpose (`order`/`invoice`). Order receipts are excluded from “unallocated” because they are settled against their order, not allocatable to an invoice (documented in the report description). Delivery-fee receipts are included in the totals as order-purpose receipts; a dedicated delivery-fee subset column was **not** added and remains open.

### D10 — Legacy and backfill treatment

- **Question:** how are legacy rows reported?
- **Options:** include with a flag; exclude.
- **Conservative default:** include, flag, never rewrite history.
- **Status:** **Implemented with a labelled conservative default** — legacy receipts are included in totals when they have a received date; succeeded receipts without one are surfaced as exception E-08 rather than silently counted or repaired. The per-row “legacy” badge was not added (open).

### D11 — Export formats

- **Question:** which export formats ship in v1?
- **Options:** CSV only; CSV + Excel; CSV + Excel + PDF.
- **Conservative default:** CSV only with caps.
- **Owner instruction:** §13 requires secure CSV exports.
- **Status:** **Resolved by instruction** — CSV only, 366-day range cap and a 50,000-row cap per export.

### D12 — Reconciliation approval separation

- **Question:** does a manual settlement or manual match need a second approver?
- **Options:** no separation; threshold-based second approver; always two approvers.
- **Status:** **Not exercised** — no manual settlement or match action exists (D4). Reconciliation runs record prepared-by and reviewed-by, and the review permission is separate from the prepare permission, but no monetary threshold policy is assumed.

### D13 — Statement retention

- **Question:** what is stored from an imported statement?
- **Status:** **Not exercised** — no statement import exists (D4). No file or line storage was created.

### D14 — Multi-currency policy

- **Question:** how is currency handled?
- **Options:** (1) report strictly per currency, refuse cross-currency totals; (2) assume GHS.
- **Conservative default:** option 1.
- **Owner instruction:** §4/§9 require consistent currency handling; §14 requires currency-separation tests.
- **Status:** **Resolved by instruction** — every figure is produced for one currency. The API publishes the currencies present in the ledger, the UI selects one, and a test asserts a USD receipt never appears in a GHS total.

---

## 3. Approval record

| Decision | Owner answer | Date | Implemented in |
| --- | --- | --- | --- |
| D1 | Granular permissions (instruction §11) | 2026-11-28 | Stage 4D migration + routes |
| D2 | Super-admin only, no automatic grant (instruction §11) | 2026-11-28 | Stage 4D migration |
| D3 | Confirmed only (instruction §4/§7G) | 2026-11-28 | `RefundReportService`, dashboard |
| D4 | No feed → document only (instruction §7C) | 2026-11-28 | `ReconciliationService::settlementAvailability()` |
| D5 | — | — | Not exercised |
| D6 | Ledger-only, FIFO derived (instruction §6E/§9) | 2026-11-28 | `CreditAttribution`, `CreditNoteReportService` |
| D7 | — (conservative default implemented, labelled) | — | `ReportPeriod`, every report header |
| D8 | — (conservative default implemented, labelled) | — | `UnallocatedReportService` |
| D9 | Succeeded receipts split by method/purpose (instruction §4) | 2026-11-28 | `DashboardService`, `ReceiptRegisterService` |
| D10 | — (included, not rewritten; E-08 surfaced) | — | `ReceiptLedger`, exceptions |
| D11 | CSV (instruction §13) | 2026-11-28 | `CsvExporter`, export routes |
| D12 | — | — | Not exercised |
| D13 | — | — | Not exercised |
| D14 | Per-currency (instruction §4/§14) | 2026-11-28 | All services + tests |

Decisions still requiring an explicit owner answer: **D5, D7, D8, D10 (delivery-fee subset + legacy badge), D12, D13**, plus the settlement feed itself for D4's later automated tier.
