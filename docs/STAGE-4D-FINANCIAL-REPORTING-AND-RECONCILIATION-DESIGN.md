# Stage 4D — Financial Reporting and Reconciliation: Technical Design

**Status:** Design only — no migrations, schema changes, endpoints, services or UI were created.
**Date:** 2026-11-27
**Depends on:** `docs/STAGE-4D-INITIAL-DISCOVERY-REPORT.md` (findings G1–G13), Stage 4A/4B on `main`, and the accepted Stage 4C head.
**Classification:** **CONDITIONALLY READY** — see §16.

---

## 1. Design principles

1. **One authoritative source per figure.** Every number is derived from an existing table through an existing service rule. No second balance, no editable snapshot of a balance, no duplicated ledger.
2. **Reports are read-only.** The only Stage 4D writes are inside the new reconciliation module (statement imports, matches, manual settlements), which never mutates receipts, allocations, credit or refunds.
3. **Additive only.** New migrations add tables, columns and indexes. No 4A/4B/4C table is altered destructively, no existing service is replaced.
4. **Exact money.** All arithmetic in integer minor units via `PaymentAllocationService::toMinorUnits()` / `formatMinorUnits()`. SQL aggregates run on `DECIMAL` columns (exact in MySQL), never on floats. API returns money as 2-decimal strings, as the existing financial endpoints already do.
5. **Currency is a partition, never a summand.** Every aggregate is grouped by `currency`; no cross-currency total is produced. GHS is the only currency today, but the rule is enforced in code and tests.
6. **Unlike categories are never added.** Receipts, allocations, customer credit, refunds and invoice settlement are separate blocks. Where they must reconcile, a named bridge identity is shown (§4) rather than a single “total”.
7. **Every figure is dated.** Each metric declares its date basis: receipt date (`received_at`), allocation date (`allocated_at`), ledger date (`created_at`), refund request/confirm date, credit-note issue date, settlement date.
8. **Status honesty.** Requested, approved, submitted, `submitting` (unresolved provider outcome), confirmed, rejected and failed refunds are distinct. An unresolved outcome is never shown as confirmed.
9. **No stale “current” money.** Monetary aggregates are computed per request. If a short cache is used it is opt-in, ≤ 60 seconds, labelled with `generated_at`, and invalidated by the existing financial broadcast events.
10. **Backend authorization is the only authorization.** Screen and export share one permission check and one filter object.

---

## 2. Authoritative source map

| Figure | Source | Derived by |
| --- | --- | --- |
| Receipt amount, status, method, provider, purpose, payer | `payment_transactions` | direct |
| Receipt date | `payment_transactions.received_at` (fallback: `created_at` only for legacy rows, flagged) | direct |
| Deposit/advance classification | `payment_receipt_intents.intent` | join on receipt |
| Allocation | `payment_allocations` + `payment_allocation_operations` | active = no reversal |
| Allocation reversal | `payment_allocation_reversals` | join on allocation |
| Customer credit movement/balance | `customer_credit_ledger_entries` | `credit types − debit types` |
| Credit-note credit and per-note remaining | `commercial_documents` (credit note) + its ledger entry | FIFO attribution (§7.6) |
| Invoice total/outstanding | `commercial_documents` + allocations + credit applications | `PaymentAllocationService::balance()` |
| Refund lifecycle | `refund_requests` + `refund_events` | status + events |
| Credit reversal | `credit_reversal_requests` + reversal ledger entry | join |
| Settlement/fee/payout | **new Stage 4D tables only** (§8) | import/manual |
| Audit | `admin_audit_logs` | direct |

---

## 3. Financial position model

Per `(payer scope, currency)` the following identities hold and are the only permitted reconciliation statements:

```
A  = Σ receipts.amount                     status='succeeded', received_at NOT NULL
X  = Σ allocations.amount                  allocation has no reversal row
D  = Σ designations − Σ reversals(of designations)        (cash-derived credit, net)
Fn = Σ credit_note_credit − Σ reversals(of credit_note_credit)   (non-cash credit created)
Ap = Σ applications                        (credit consumed to settle invoices)
C  = D + Fn − Ap                           (customer credit liability; D and Fn are already net of their reversals)

Fc = Σ refunds.amount   status='confirmed'
Fo = Σ refunds.amount   status IN ('submitted','submitting')
Fp = Σ refunds.amount   status IN ('requested','approved')

U  = A − X − D − Fc − Fo        (unallocated cash held; pipeline refunds Fp are still inside U)
```

Three distinct figures exist and must never be interchanged:

- `allocatable = A − X − D` — what the allocation workbench offers.
- `U` — cash held that is neither allocated, credited nor returned/in flight; still includes `Fp` (requested/approved refunds not yet submitted).
- `refundable = U − Fp` — what a new refund may consume (`RefundService::refundableMinor()`).

Bridge identities:

```
Net cash retained     = A − Fc = X + D + U + Fo
Customer credit       = D + Fn − Ap                      (liability, not cash; D and Fn are net of reversals)
Invoice outstanding   = Σ invoice.total − Σ allocations(invoice) − Σ applications(invoice)
Receipt refundable    = A − X − D − Fc − Fo − Fp          (matches RefundService::refundableMinor)
Receipt allocatable   = A − X − D                        (matches availableMinorForPayment)
```

`allocatable` and `refundable` differ whenever a refund is in flight (finding G4). Both are published, never interchanged.

Reports must **detect** a negative residual (`U < 0` or `refundable < 0`) as a data-integrity exception and surface it in an exception panel, instead of silently clamping with `max(0, …)` the way the write-path services do.

---

## 4. Module designs

### A. Financial dashboard (`/admin/reports`)

Purpose: a single truthful position for management and finance, composed of separate blocks — not one grand total.

Blocks:

1. **Receipts** — gross received, count, by method (cash/gateway), by provider, by purpose (order/invoice), split by status (`succeeded`, `pending`, `failed`, `cancelled`). Pending/failed/cancelled are shown as counts and amounts but never added to received.
2. **Refunds** — confirmed (cash returned), in flight (`submitted` + `submitting`), pipeline (`requested` + `approved`), rejected, failed; unresolved provider outcomes called out separately with a link to the exception list.
3. **Allocation** — allocated (active), reversed, unallocated (`U`), with the count of receipts holding unallocated funds.
4. **Customer credit** — closing balance split by origin (cash designation vs credit note), movements in period (designations, credit notes, applications, reversals), payer count, and ageing buckets.
5. **Invoices** — invoiced total, settled by allocation, settled by credit, outstanding, count of invoices with outstanding balance.
6. **Credit notes** — issued in period, applied, remaining, voided.
7. **Reconciliation** — matched, unmatched-internal, unmatched-statement, amount mismatch, timing difference, manual; plus “no statement imported” state.
8. **Position bridge** — the §3 identity rendered as a bridge with the arithmetic shown, so a reader can verify it.

Presentation rules:

- Every block shows its date basis and `generated_at`.
- Trend visualisation uses CSS bars/sparklines built from the existing design tokens; **no new chart dependency** unless the owner approves one (D11).
- Each block drills into its detailed report with the current filters preserved in the URL.

### B. Payment allocation register (`/admin/reports/allocations`)

Columns: payment reference, provider reference, payer (customer/organisation), receipt date, currency, receipt amount, allocated amount, unallocated amount, credit-designated amount (net), reversed amount, allocation status (`unallocated`, `partially_allocated`, `fully_allocated`, `reversed`), invoice numbers (list), last allocation date.

Row expansion shows the full allocation history for that receipt: each operation (reference, actor, reason, before/after unallocated), each line (invoice, amount, invoice balance before/after), and each reversal (actor, reason, timestamps). History is append-only and never collapsed.

Filters: date range (receipt date or allocation date — explicit selector), payer, currency, provider, method, status, invoice number, search.

### C. Unallocated payments (`/admin/reports/unallocated`)

Columns: receipt reference, payer, receipt date, currency, amount received, allocated amount, credit-designated amount (net), refund-reserved amount, **refundable/available amount**, refundable-as-of ageing (days since receipt), receipt status, purpose.

Rules:

- “Available” = `refundable` (§3). `allocatable` is shown as a secondary column labelled “allocatable (ignores refund reservations)”.
- Funds designated as customer credit are never counted as available.
- Ageing buckets: 0–7, 8–30, 31–60, 61–90, 90+ days, computed on receipt date in the restaurant timezone (`config('app.timezone')`, `Africa/Accra`).
- Summary strip: total available by currency and bucket.

### D. Customer-credit reports (`/admin/reports/customer-credit`)

Two views:

1. **Statement** (one payer, one currency): opening balance, movements in chronological order (designation, credit note, application, reversal) with running balance from the ledger's `credit_balance_before/after`, closing balance. Opening balance = closing balance of all entries before the period start, derived from the ledger (no stored balance).
2. **Portfolio**: per payer/currency closing balance, total credited, total consumed, total reversed, last movement date, ageing of available credit (FIFO: oldest unconsumed credit first), and count of payers with zero balance.

Attribution: available credit is attributed FIFO across credit entries, reusing the 4C algorithm (see §7.6). The report exposes `consumed` and `outstanding` per payer and per originating credit entry (cash designation vs credit note), never per “edited balance”.

### E. Refund reports (`/admin/reports/refunds`)

Register columns: refund reference, original receipt reference, payer, requested amount, approved amount, submitted amount, confirmed amount, current status, initiator, approver, confirmer, provider, provider refund reference, requested date, submitted date, confirmed/failed date, resolution flag.

Because a refund row carries one amount and a status, stage amounts are produced as:

- requested/approved/submitted/confirmed amount = amount of rows **currently** in that status;
- historical stage transitions = `refund_events` (append-only), used for the timeline and for “submitted in period, confirmed later” analysis.

Summary block: counts and amounts per status, plus a dedicated **unresolved provider outcomes** list (`status='submitting'`, and `refund_events` with `provider_outcome='unknown'` or `reconciliation_mismatch`). Confirmed totals never include submitted or submitting amounts.

### F. Credit-note reports (`/admin/reports/credit-notes`)

Register columns: credit-note number, originating invoice number, payer, currency, issued amount, applied amount, remaining amount, issuing officer, authorizer (creator when different), issue date, status, void date/reason, linked ledger reference.

Derivations:

- issued amount = `commercial_documents.total` for the credit note;
- applied amount = issued − reversed − FIFO remaining of its `credit_note_credit` entry;
- remaining = FIFO remaining of that entry (unconsumed credit attributable to this note);
- applied-to-origin portion is separately shown from the auto-apply ledger entry (`operation_reference = 'cn:<number>:apply'`, `commercial_document_id = invoice`), labelled as a convention-derived figure.

Original invoices are never modified to produce these numbers; the report reads documents and ledger entries only.

### G. Settlement and financial reconciliation (`/admin/reports/reconciliation`)

Four explicitly labelled tiers (never merged):

| Tier | Source | What it can claim |
| --- | --- | --- |
| `automated` | Provider settlement API, if the owner enables and verifies it | “Provider-reported settlement” |
| `imported` | Statement file uploaded by finance | “Imported statement” |
| `manual` | Figure entered by an authorised officer with evidence | “Manually recorded” |
| `unmatched` | Internal records with no counterpart, or statement lines with no internal match | “Unresolved” |

Reconciliation record per statement line: gateway reference, gross amount, gateway fee, net settlement, settlement date, currency, batch/reference, internal receipt reference, match status, variance, resolver, resolved at, notes.

Match statuses: `matched_exact`, `matched_with_fee` (gross matches, net = gross − fee), `timing_difference` (matched but settled in a later batch), `amount_mismatch`, `missing_internal` (statement line without receipt), `missing_statement` (receipt without settlement after N days), `duplicate_internal`, `duplicate_statement`, `manual_confirmed`.

Matching order: exact `provider_reference` + amount + currency → `provider_reference` + amount mismatch → amount + date window (± 3 days) → manual queue. Duplicates are detected before matching. Refund settlements are reconciled against `refund_requests.provider_refund_reference` as debit lines.

If no settlement source is configured, the module renders a clear empty state (“No settlement source configured — internal records are complete but unreconciled”), never a fabricated match rate.

---

## 5. Filters, sorting, pagination, exports

One filter object per report, validated server-side and reused verbatim by the export endpoint:

`from`, `to` (with an explicit `date_basis` where a report supports more than one), `customer_id`, `organisation_id`, `payment_method`, `provider`, `currency`, `status`, `purpose`, `document_number`, `payment_reference`, `search`, `sort`, `direction`, `page`, `per_page` (1–100, default 25).

- Date range is required for register exports and defaults to the current month for screen views.
- Maximum export span: 366 days; maximum export rows: 50,000, streamed with a cursor; exceeding either returns a validation error naming the limit.
- CSV is the Stage 4D baseline. Excel/PDF are assessed but deferred to an owner decision (D11), because they need new dependencies and a rendering pipeline.
- Export response headers include the applied filters and `generated_at` so a downloaded file is reproducible.

---

## 6. API design (proposed, not created)

All routes are `GET`, under `admin/reports`, authenticated by Sanctum, authorized by the permission in §9, throttled, and read-only:

```
GET /admin/reports/dashboard
GET /admin/reports/allocations
GET /admin/reports/unallocated
GET /admin/reports/customer-credit            (portfolio)
GET /admin/reports/customer-credit/{payerType}/{payerId}   (statement)
GET /admin/reports/refunds
GET /admin/reports/credit-notes
GET /admin/reports/reconciliation
GET /admin/reports/exceptions                 (data-integrity + unresolved outcomes)
GET /admin/reports/{report}/export.csv
```

Reconciliation mutations (separate permission, audited):

```
POST   /admin/reconciliation/imports           (statement upload → parsed lines)
GET    /admin/reconciliation/imports/{id}
POST   /admin/reconciliation/lines/{id}/match
POST   /admin/reconciliation/lines/{id}/unmatch
POST   /admin/reconciliation/lines/{id}/manual-settle
GET    /admin/reconciliation/unmatched
```

Every response follows the existing envelope: `{ success, data, meta }`, money as strings, `generated_at`, `currency_partition` and `date_basis` echoed.

---

## 7. Aggregation and correctness rules

### 7.1 Query strategy

- Aggregates are computed in SQL over indexed columns, then converted to minor units in PHP.
- Detail lists are paginated in SQL; totals come from a separate aggregate query over the same filter — never by summing the current page.
- No report loads a full ledger into memory or into the browser.
- Per-row correlated subqueries (as used by the allocation workbench) are acceptable only for a single page of ≤ 100 rows; register totals use grouped joins/subqueries.

### 7.2 Index additions (additive migrations)

| Table | Index | Reason |
| --- | --- | --- |
| `payment_allocations` | `(allocated_at, commercial_document_id)` | allocation register date range (finding G5) |
| `customer_credit_ledger_entries` | `(created_at, entry_type)` | period credit summaries (G6) |
| `refund_requests` | `(requested_at)` | date-only refund ranges (G7) |
| `refund_requests` | `(provider_refund_reference)` | provider reconciliation lookups |
| `payment_transactions` | `(payment_purpose, received_at, status)` | reporting by purpose and period |
| `payment_transactions` | `(provider, received_at)` | provider settlement matching |
| new settlement tables | unique on `(import_id, line_number)`, `(provider_reference, settlement_date)`, index on `match_status` | import idempotency and queue |

Indexes are added only after `EXPLAIN` confirms the target query uses them; no index is added speculatively.

### 7.3 Caching

Default: **no server-side caching of monetary figures.** The existing financial page uses a 5-second client cache (`cachedGet`) and invalidates it on the `financials` broadcast; Stage 4D reports may keep at most that 5-second window, must display `generated_at`, and must invalidate on the same events. Anything longer is server-side only, keyed by the filter hash, TTL ≤ 60 s, invalidated by `FinancialPaymentUpdated`, and always labelled “as of HH:MM:SS”. A stale-by-design figure presented as current is prohibited.

### 7.4 Date bases

| Report | Default basis | Alternative |
| --- | --- | --- |
| Receipts | `received_at` | `reporting_date` (legacy comparability) |
| Allocation register | receipt date | allocation date |
| Unallocated | receipt date | — |
| Customer credit | ledger `created_at` | — |
| Refunds | `requested_at` | `confirmed_at` |
| Credit notes | `issued_at` | `created_at` |
| Reconciliation | settlement date | receipt date |

### 7.5 Reversals and refunds in aggregates

- A reversed allocation is excluded from active allocated totals and counted once in reversed totals; both figures appear.
- A reversal of a credit designation returns funds to the receipt's refundable/allocatable pool (`creditDesignatedMinorForPayment` already nets it).
- Confirmed refunds reduce net cash retained; in-flight refunds are shown as reserved; neither is subtracted from “gross receipts”.
- Voided credit notes keep their issued history and show a compensating reversal; issued/voided/remaining are separate columns.

### 7.6 FIFO credit attribution (shared, not re-implemented)

Per-credit-entry remaining credit is computed with the same rule as `CustomerCreditReversalService::remainingReversibleMinor()`: credits ordered by `created_at, id`, applications consuming them FIFO, reversals reducing the specific entry first. Stage 4D adds a **read-only batched implementation** in the reporting layer that processes one payer+currency in a single pass, plus a parity test asserting it returns identical values to the 4C service for the same ledger. Divergence is a test failure, not a UI discrepancy.

---

## 8. Data model additions (Stage 4D only, additive)

```
settlement_imports
  id, provider (paystack|hubtel|korapay|other), source_label, file_name, file_checksum (unique),
  period_start, period_end, currency, imported_by, imported_at, line_count, status,
  metadata json, timestamps

settlement_lines
  id, settlement_import_id, line_number, provider_reference, gross_amount, fee_amount,
  net_amount, settlement_date, currency, batch_reference, raw_row json, match_status,
  matched_payment_transaction_id (nullable), matched_refund_request_id (nullable),
  variance_amount, resolved_by, resolved_at, resolution_note, timestamps
  unique (settlement_import_id, line_number)
  index (provider_reference), (match_status), (settlement_date)

settlement_matches            (audit of every match/unmatch action, append-only)
  id, settlement_line_id, action (matched|unmatched|manual_settled),
  payment_transaction_id, refund_request_id, amount, currency,
  actor_user_id, actor_name_snapshot, reason, created_at

reconciliation_notes          (manual adjustments/explanations, append-only)
  id, subject_type, subject_id, note, actor_user_id, actor_name_snapshot, created_at
```

Rules: imports are idempotent by `file_checksum`; a re-import of the same file is rejected, not merged. Statement lines are immutable once imported; corrections are new lines or notes. No table stores a “current balance”. `fee_amount` is stored separately and never netted into a receipt amount.

---

## 9. Security and RBAC

Full matrix in `docs/STAGE-4D-PERMISSIONS-MATRIX.md`. Design rules:

- Read permissions gate reads; `financials.reconciliation.manage` gates every reconciliation mutation; no report route mutates data.
- Export requires the same read permission **plus** `financials.reports.export`, and applies the identical filter object.
- No financial permission is added to the `admin` role by default; grants are explicit per role or per user.
- Sensitive fields (payer email/phone, provider references) are included only where the report needs them and never in a dashboard aggregate.
- Reconciliation mutations log to `admin_audit_logs` (`stage4d.reconciliation.*`) with before/after and reason.

---

## 10. Frontend architecture

- New route group under `/admin/reports/*`, guarded by `ProtectedRoute` with the matching permissions, plus a “Reports” nav section in `AdminLayout` that appears only when at least one report permission is held.
- Shared components (new, focused files): `ReportShell` (title, date range, filter bar, export button, generated-at), `MoneyCell`, `MetricBlock`, `StatusPill`, `EmptyReportState`, `ExceptionBanner`, `ResponsiveTable` (table on ≥ md, stacked cards on mobile).
- Design system only: existing tokens, typography, spacing, radii, shadows; light/dark/system themes inherited; no second palette, no new UI library.
- Responsive: desktop/laptop table layout; tablet two-column metric grid; mobile single-column cards, filter drawer, sticky summary strip. Verified at 375/675/1024/1440 px.
- Charts (if approved) are CSS/SVG built from tokens; no chart library is added without an owner decision.
- Money is rendered from the API's 2-decimal string with `Intl.NumberFormat('en-GH', { currency: 'GHS' })`, never from JS float arithmetic.

---

## 11. Performance plan

- Every register: server-side pagination, indexed filters, count + aggregate query per request.
- Dashboard: 6–8 aggregate queries per request (one per block), each hitting a covering index; target < 300 ms at 1M receipts.
- Exports: cursor streaming, 366-day / 50k-row caps, no eager loading of relations.
- Reconciliation: matching runs in batches of 500 lines with `provider_reference` index lookups; a dry-run preview precedes commit.
- Concurrency: reports take no locks; reconciliation commits lock only the affected line and use idempotent transitions.
- Acceptance includes query-count assertions (no N+1) and `EXPLAIN` review for every new aggregate.

---

## 12. What Stage 4D will not do

- No second ledger, balance table, or editable “current balance”.
- No modification of `payment_transactions`, allocations, credit entries, refunds or credit notes from reporting code.
- No retroactive rewrite of historical rows or backfilled “reporting_date” corrections.
- No claim of automated reconciliation without a verified provider settlement source.
- No Excel/PDF export until approved.
- No change to Stage 4A/4B/4C business rules (including D12a: receipts are never marked `refunded`).

---

## 13. Implementation sequence

Detailed in `docs/STAGE-4D-IMPLEMENTATION-BREAKDOWN.md`. Summary:

1. 4D-0 — branch from accepted 4C head; index + reconciliation-schema migrations (no UI).
2. 4D-1 — reporting kernel: money/filter/date utilities, aggregate query builders, parity harness for FIFO attribution.
3. 4D-2 — allocation register + unallocated report (backend + UI).
4. 4D-3 — customer-credit statement and portfolio.
5. 4D-4 — refund register and exceptions.
6. 4D-5 — credit-note register.
7. 4D-6 — financial dashboard (composes 4D-2…4D-5 figures).
8. 4D-7 — settlement import + reconciliation workbench.
9. 4D-8 — exports, responsive acceptance, performance and documentation.

---

## 14. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| 4C not merged when 4D starts | Branch from accepted 4C head; re-base after merge; dependency matrix gates each module |
| Invoice receipts omitted (G1) | New queries; regression test with an `order_id = NULL` receipt |
| Refund-reserved money shown as available (G4) | Two explicit columns; test asserting `refundable < allocatable` when a refund is open |
| FIFO attribution divergence | Batched implementation + parity test against 4C service |
| Provider settlement assumptions | Tier-labelled figures; empty state when unconfigured |
| Slow registers at scale | Index plan + `EXPLAIN` + query-count tests + export caps |
| Permission creep | Read/mutation split; admin role untouched by default |

---

## 15. Documentation to update on implementation

`docs/API-DOCUMENTATION.md` (report endpoints), `docs/DATABASE-SCHEMA.md` (new tables/indexes), `docs/FEATURES-AND-OPERATIONS.md` (report operations), `docs/IMPLEMENTATION-LOG.md` (stage record), `docs/README.md` (index entries).

---

## 16. Classification

**CONDITIONALLY READY.** The design is complete and ready for owner approval. Implementation is blocked on C1–C4 in the discovery report: Stage 4C acceptance/merge, approval of D1–D14, a settlement-source decision, and the Stage 4B remainder scope for staff deposits.
