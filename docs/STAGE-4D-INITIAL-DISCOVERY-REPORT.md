# Stage 4D — Financial Reporting and Reconciliation: Initial Discovery Report

**Status:** Discovery complete — design-only assignment, no implementation performed.
**Report date:** 2026-11-27
**Classification:** **CONDITIONALLY READY** (see §12)
**Authorisation:** Discovery, requirements verification, architecture assessment and design only. No migrations, schema changes, service changes, endpoints, UI, permissions, statement imports or data mutations were performed.

---

## 1. Scope and method

Stage 4D covers financial reporting and reconciliation on top of the accepted Stage 4A–4C financial foundation:

- a financial dashboard;
- a payment allocation register;
- unallocated payment reporting;
- customer-credit reporting;
- refund reporting;
- credit-note reporting;
- settlement and financial reconciliation.

Method: static inspection of the working tree, Git history and branches, the running MySQL 8.4 schema (`information_schema`), `EXPLAIN` query plans, service/controller/model source, route definitions, permission seeding, and the Stage 4C design and acceptance records. No application data was created or modified; the sandbox database currently holds zero financial rows (§8), so all performance statements are structural (indexes and query plans), not volume measurements.

Environment inspected:

| Item | Value |
| --- | --- |
| Laravel | `laravel/framework ^13.0`, PHP 8.4.28 |
| Database | MySQL 8.4.13 (sandbox compose service `db`) |
| Frontend | React + TypeScript, Tailwind CSS design tokens, Vite dev server |
| Migrations applied in sandbox | 48, including all five Stage 4C migrations |

---

## 2. Repository and branch state

| Item | Finding |
| --- | --- |
| Current branch | `refunds-credit-notes` |
| HEAD | `a6489c63fd496038930db43b325e62eaf4eb85f8` — “Implement stage 4C credit reversal and dual-write refund logic” |
| Working tree | Clean (`git status --porcelain` returned nothing) before this discovery; only this documentation set is added |
| Main branch | `main` at `95d6517` (“Add Reverb broadcasting, local CORS and Vite build updates, and payment/delivery refinements”) |
| `main` is an ancestor of HEAD | Yes |
| Stage 4C commits ahead of `main` | 3: `ceed566`, `9017b9a`, `a6489c6` |
| Other remotes of interest | `origin/stage-4c-refunds-credit-notes`, `origin/audit-deposits-credit`, `origin/main` |

### 2.1 Pull request status

| PR | Title | State | Head → base | Mergeable | Updated |
| --- | --- | --- | --- | --- | --- |
| #2 | feat: implement stage 4c refunds, credit notes, and reversals | **OPEN, not merged** | `refunds-credit-notes` → `main` | MERGEABLE | 2026-11-27T13:23:28Z |
| #1 | Implement Stage 4B: Deposits and Credit Acceptance | **OPEN, not merged** | `audit-deposits-credit` → `main` | MERGEABLE | 2026-11-27T12:25:40Z |

Stage 4C is reported internally as **READY FOR OWNER ACCEPTANCE** (`docs/STAGE-4C-IMPLEMENTATION-AND-ACCEPTANCE-REPORT.md`). Owner acceptance and merge have **not** occurred. Neither PR was modified, merged or reviewed as part of this assignment.

### 2.2 Which migrations exist in which branch

**On `main` (accepted foundation):**

| Migration | Stage |
| --- | --- |
| `2026_11_23_000001_add_payment_fields_to_orders_table` | pre-4A |
| `2026_11_24_000001_add_online_payment_fields_to_orders_table` | pre-4A |
| `2026_11_24_000002_expand_payment_gateway_accounts_and_promote_super_admin` | pre-4A |
| `2026_11_24_000003_create_payment_transactions_table` | pre-4A receipt ledger |
| `2026_11_25_000008_create_commercial_document_tables` | Stage 3 documents |
| `2026_11_25_000009_add_commercial_document_permissions` | Stage 3 |
| `2026_11_25_000010_create_payment_allocation_tables` | **Stage 4A** |
| `2026_11_25_000011_add_payment_allocation_permissions` | Stage 4A |
| `2026_11_26_000001_add_stage4a_financial_visibility_and_actor_snapshots` | Stage 4A |
| `2026_11_26_000002_preserve_existing_financial_dashboard_permission` | Stage 4A |
| `2026_11_26_000003_create_stage4b_credit_and_deposit_tables` | Stage 4B |
| `2026_11_26_000004_add_customer_verification_and_security_challenges` | Stage 4B |
| `2026_11_26_000005_add_pickup_and_delivery_fee_tracking_to_orders` | Stage 4B |

**On the Stage 4C branch (and applied in the sandbox), not on `main`:**

| Migration | Adds |
| --- | --- |
| `2026_11_27_000001_create_refund_requests_and_events_tables` | `refund_requests`, `refund_events` |
| `2026_11_27_000002_add_credit_note_link_to_commercial_documents` | `commercial_documents.related_document_id` |
| `2026_11_27_000003_create_credit_reversal_requests_table` | `credit_reversal_requests` |
| `2026_11_27_000004_extend_customer_credit_ledger_for_reversals` | `reverses_entry_id`, `authorized_by_*`, `authorized_at`; extended entry-shape constraints |
| `2026_11_27_000005_add_stage4c_financial_permissions` | 9 `financials.refunds.*`, `financials.credit_notes.*`, `financials.credit_reversals.*` permissions |

**On PR #1 (`audit-deposits-credit`), not on `main` and not on the Stage 4C branch:**

| Migration | Adds |
| --- | --- |
| `2026_11_27_000001_create_staff_deposit_records_table` | `staff_deposit_records` |

PR #1 additionally adds `DepositService`, `DepositReceiptService`, `DepositController`, `CustomerDepositController`, `EnsureCustomerFullyVerified`, staff/customer deposit UI and Stage 4B acceptance documents. The two `2026_11_27_000001_*` migration filenames differ, so a later merge of both PRs applies both without a filename collision, but the shared timestamp prefix makes ordering depend on alphabetical filename order.

### 2.3 Which services exist in which branch

| Service | `main` | Stage 4C branch |
| --- | --- | --- |
| `PaymentTransactionService` | yes | yes |
| `PaymentAllocationService` | yes | yes (credit-reversal compensation added) |
| `CustomerCreditService` | yes | yes (`currentBalanceMinor` exposed) |
| `CommercialDocumentService` / `Access` / `Presenter` | yes | yes |
| `GatewayPaymentService` | yes | yes (Paystack refund + lookup added) |
| `RefundService` | no | **yes** |
| `CreditNoteService` | **no** | yes |
| `CustomerCreditReversalService` | **no** | yes |
| `Stage4cPolicy` + `config/stage4c.php` | no | yes |
| `DepositService`, `DepositReceiptService` | no | no (PR #1 only) |

`main` contains the Stage 4A allocation foundation and the Stage 4B credit/deposit tables with `CustomerCreditService`, but **none** of the Stage 4C refund, credit-note, reversal or policy services. All of those exist only on the Stage 4C branch.

### 2.4 Correct starting point for Stage 4D

Stage 4D must start from the **accepted Stage 4C head**, not from `main`:

- refund reports need `refund_requests` / `refund_events`;
- credit-note reports need `commercial_documents.related_document_id`;
- credit-reversal reporting needs `credit_reversal_requests` and the extended ledger entry types.

Recommended sequence: owner accepts PR #2 → PR #2 merges to `main` → Stage 4D branch is cut from the merged `main`. If 4D must begin before that merge, it branches from `refunds-credit-notes@a6489c6` and is rebased onto `main` after the 4C merge. Stage 4D must not duplicate or replace any 4C table or service.

PR #1 (Stage 4B remainder) is an independent dependency only for staff-recorded deposits. Stage 4D v1 reports deposits/advances from `payment_receipt_intents`, which is already on `main`; staff-recorded deposit reporting is deferred until PR #1 is accepted.

---

## 3. Financial architecture discovered

### 3.1 Authoritative sources of financial figures

| Figure | Authoritative source | Notes |
| --- | --- | --- |
| Money received | `payment_transactions` (`status = succeeded`, `received_at`, `verified_at`) | Canonical receipt ledger. `order_id` is nullable for standalone invoice receipts. |
| Cash vs gateway | `payment_transactions.payment_method`, `provider` | `cash`, `gateway`; providers `paystack`, `hubtel`, `korapay` |
| Receipt purpose | `payment_transactions.payment_purpose` (`order`, `invoice`) | invoice receipts have `order_id = NULL` |
| Receipt date | `payment_transactions.received_at` (event), `reporting_date` (capture date) | `reporting_date` is set to the day the row was written/updated, not the provider settlement date |
| Deposit / advance classification | `payment_receipt_intents` (one row per receipt, no amount) | amount lives on the receipt |
| Allocation to invoices | `payment_allocations` + `payment_allocation_operations` (actor, before/after unallocated) | active = no `payment_allocation_reversals` row |
| Allocation reversal | `payment_allocation_reversals` (one per allocation, reason, actor, before/after) | append-only history |
| Customer credit balance | `customer_credit_ledger_entries` (append-only) | credits: `designation`, `credit_note_credit`; debits: `application`, `reversal` |
| Invoice total / status | `commercial_documents` (`document_type='invoice'`) + `commercial_document_lines` | totals are snapshots on the document |
| Credit note | `commercial_documents` (`document_type='credit_note'`, `related_document_id` → invoice) | credit effect is a ledger entry |
| Refund | `refund_requests` + `refund_events` | status lifecycle; provider references |
| Credit reversal request | `credit_reversal_requests` + reversal ledger entry | affected |
| Order value | `orders.total` | not a receipt figure; receipts are authoritative for cash |
| Audit trail | `admin_audit_logs` via `AuditService` | all 4C mutations log `stage4c.*` actions |
| Settlement, gateway fees, payouts | **none exist** | see §5 |

No second balance store exists: invoice outstanding, customer credit balance and receipt availability are all **derived** by `PaymentAllocationService::balance()`, `CustomerCreditService::creditBalanceMinor()` and `PaymentAllocationService::availableMinorForPayment()`. Stage 4D reports must derive from the same definitions.

### 3.3 Integrity controls already in force

- Ledger entries are append-only (`CustomerCreditLedgerEntry::booted` throws on update/delete).
- All monetary mutation paths use `PaymentAllocationService::toMinorUnits()` (integer minor units) and `formatMinorUnits()`; no float arithmetic in 4A–4C services.
- Every mutation path is transactional, locks the payer/receipt/invoice rows and carries an idempotency reference (`operation_reference`, `refund_reference`).
- Refunds never rewrite the receipt: `stage4c.refunds.mark_transaction_refunded` defaults to `false` (D12a), so `payment_transactions.status` stays `succeeded`.
- Credit applications consume credit FIFO across a payer's credit entries (`CustomerCreditReversalService::remainingReversibleMinor`).

### 3.2 Existing reporting capability

| Capability | Route | Permission | Behaviour |
| --- | --- | --- | --- |
| Financial snapshot | `GET /admin/financials/snapshot` | `financials.view` | Daily aggregates joined to `orders` |
| Reports alias | `GET /admin/financials/reports` | `financials.reports.view` | Same snapshot payload |
| Transaction register | `GET /admin/financials/transactions`, `/{id}` | `financials.transactions.view` | Paginated, filtered, order-joined |
| Reconciliation alias | `GET /admin/financials/reconciliation` | `financials.reconciliation.view` | Alias of the transaction list; **no settlement data** |
| CSV export | `GET /admin/financials/export.csv` | `financials.reports.export` | Streamed transaction CSV |
| Admin UI | `/admin/financials` | `financials.view` | Snapshot cards, daily table, channel breakdown, transaction list, CSV |
| Allocations UI | `/admin/payment-allocations` | `financials.allocations.*` | Allocation workbench + history |
| Deposits & credit UI | `/admin/customer-credit` | `financials.deposits.*`, `financials.customer_credit.*` | Balances, ledger, designations, applications |
| Refunds UI | `/admin/refunds` | `financials.refunds.*` | Refund queue and lifecycle actions |
| Credit notes UI | `/admin/credit-notes` | `financials.credit_notes.*` | Create, issue, void |
| Credit reversals UI | `/admin/credit-reversals` | `financials.credit_reversals.*` | Request, approve, reject |

### 3.3 Missing reporting capabilities

- No consolidated financial dashboard that separates gross receipts, allocations, customer credit, refunds and net position.
- No allocation register across all payments (only per-invoice and per-payment views inside the allocation workbench).
- No unallocated-payments report, no ageing of unallocated funds.
- No customer-credit statement with opening/closing balance, ageing, or consumed vs outstanding credit.
- No refund register or summary; refund data exists only in the operational refund queue.
- No credit-note register with applied/remaining amounts.
- No settlement or reconciliation data model, no fee tracking, no statement import, no matching engine. `/admin/financials/reconciliation` is an alias of the transaction list.
- No report-level export other than the order-transaction CSV.

---

## 4. Defects and gaps found during discovery (documented, not fixed)

| # | Finding | Impact on Stage 4D | Severity |
| --- | --- | --- | --- |
| G1 | `FinancialController::snapshot()` and `::transactions()` **inner join `orders`**. Invoice-purpose receipts (`order_id = NULL`) and their allocations/credit are invisible. | A new reporting layer cannot reuse these queries. Dashboard must read `payment_transactions` directly with `LEFT JOIN orders`. | High |
| G2 | Dashboard `refunded_amount` is derived from `payment_transactions.status = 'refunded'`, which Stage 4C deliberately never sets (D12a, default `false`). | Refunds currently show as zero everywhere. Refund reporting must read `refund_requests`. | High |
| G3 | No settlement, fee, payout, batch or provider-settlement-date data anywhere in the schema or gateway client. `GatewayPaymentService` calls only initialize/verify (all providers) and refund/refund-list/refund-verify (Paystack only). | Reconciliation cannot be automated without a new additive data model and a provider/statement source decision. | High |
| G4 | `PaymentAllocationService::availableMinorForPayment()` subtracts allocations and net credit designations but **not** refunds reserved in `requested/approved/submitting/submitted` states (`RefundService::refundableMinor()` does). | Two different “available” figures exist. Reporting must publish both and never label refund-reserved money as available. | High |
| G5 | `payment_allocations` has no index on `allocated_at` alone; a date-range allocation report does a full table scan (`EXPLAIN type=ALL`). | Needs an additive index before the allocation register ships. | Medium |
| G6 | `customer_credit_ledger_entries` has per-payer indexes but no `created_at`/`entry_type, created_at` index; period credit summaries do a full scan. | Needs an additive index for period reporting. | Medium |
| G7 | `refund_requests` indexes are `(status, requested_at)`, `(payment_transaction_id, status)`, payer/date. A date-only refund range cannot use them efficiently. | Add `requested_at` index (or composite) in 4D. | Medium |
| G8 | Per-credit-note “remaining credit” is not stored. Credit is pooled per payer and consumed FIFO; attribution is computed by `remainingReversibleMinor()`. | Credit-note reporting must reuse the same FIFO algorithm (batched), not invent a second attribution rule. | Medium |
| G9 | `payment_transactions.amount` is `decimal(12,2)` while every other financial table uses `decimal(14,2)`. | Report sums must cast consistently; note the ceiling difference (GHS 9,999,999,999.99 vs 999,999,999,999.99). | Low |
| G10 | Currency is assumed `GHS` in several code paths (`verifyPaystack`/`verifyKora` hard-check GHS; receipt creation hardcodes `GHS`). | Reports must group by `currency` and never sum across currencies, even though only GHS exists today. | Low |
| G11 | Delivery-fee cash confirmation writes a **separate** `payment_transactions` row (`DELIVERY-FEE-CASH-…`, `payment_method='cash'`, `payment_purpose='order'`). | Gross receipts include delivery-fee receipts; reports must expose `payment_purpose` and transaction reference so they can be separated. | Low |
| G12 | Only `super-admin` holds financial permissions by default; the `admin` role is seeded **without** any `financials.*` permission. | Report permissions must be granted deliberately; no silent widening of admin access. | Medium (policy) |
| G13 | Sandbox financial tables are empty (0 rows in all nine financial tables). | No volume evidence exists locally; performance design relies on schema, indexes and `EXPLAIN`, and must be validated with seeded volume in tests. | Low |

---

## 5. Provider settlement capability assessment

| Provider | Integrated calls today | Settlement / fee data actually obtained |
| --- | --- | --- |
| Paystack | `POST /transaction/initialize`, `GET /transaction/verify/{ref}`, `POST /refund`, `GET /refund`, `GET /refund/{id}`; webhook signature verification | **None stored.** Verify response is used only to mark paid and capture `provider_reference` (transaction id). No settlement, fee or payout endpoint is called, and no fee field is persisted. |
| Hubtel | `POST /items/initiate`, `GET /transactions/{account}/status` | **None.** No settlement/fee retrieval, no refund support in the client. |
| Korapay | `POST /charges/initialize`, `GET /charges/{reference}` | **None.** No settlement/fee retrieval, no refund support in the client. |

Consequence: Stage 4D cannot claim automatic settlement reconciliation. Reconciliation must be designed in four explicit tiers — automated (only if the owner enables a provider settlement API), imported statement, manually recorded, and unmatched/unresolved — and the UI must state which tier produced each figure. Provider settlement APIs and statement formats are **not verified** for this merchant account; the design does not assume they exist.

---

## 5. Data model facts relevant to reporting

- `payment_transactions`: `reporting_date` (date index), `status`, `payment_method`, `provider`, `payment_purpose`, `order_id` (nullable), `customer_id`/`organisation_id` (exactly one for paid invoice receipts), `received_at`, `verified_at`, `provider_reference`.
- `payment_allocation_operations`: `payment_unallocated_before/after`, `allocated_by` + actor snapshots, `allocated_at`.
- `payment_allocations`: `amount`, `currency`, `invoice_balance_before/after`, `allocated_at`; unique per (operation, document).
- `payment_allocation_reversals`: one per allocation, `reason`, `payment_unallocated_before/after`, `invoice_balance_before/after`, `reversed_at`.
- `customer_credit_ledger_entries`: `entry_type`, `operation_reference` (unique), source payment / document / `reverses_entry_id`, payer, actor + `authorized_by_*` snapshots, `amount`, `currency`, `credit_balance_before/after`, optional payment/invoice before/after, `created_at` (no `updated_at`).
- `refund_requests`: `refund_reference`, receipt, payer, `amount`, `currency`, `status` (`requested`, `approved`, `submitting`, `submitted`, `confirmed`, `rejected`, `failed`), requester/approver/confirmer + snapshots, `provider`, `provider_refund_reference`, `submitted_at`, `confirmed_at`, `verification_reference`, `verification_evidence`, `metadata`.
- `refund_events`: append-only per action (`requested`, `approved`, `rejected`, `submitting`, `submitted`, `reconciled`, `reconciled_absent`, `reconciliation_mismatch`, `provider_error`, `confirmed`, `failed`) with actor snapshot, reason, provider reference, metadata.
- `credit_reversal_requests`: target ledger entry, `amount`, `status`, requester/approver, resulting `reversal_ledger_entry_id`.
- `commercial_documents`: `document_number`, `document_type`, `status`, `related_document_id`, `total`, `subtotal`, `discount_amount`, `tax_amount`, `currency`, `issued_at/by`, `voided_at`, `void_reason`.

Financial indexes currently present (relevant subset): `payment_transactions(reporting_date)`, `(status, received_at)`, `(payment_purpose, status, received_at)`, `(customer_id, payment_purpose)`, `(organisation_id, payment_purpose)`; `payment_allocation_operations(payment_transaction_id, allocated_at)`; `payment_allocations(commercial_document_id, allocated_at)`; `customer_credit_ledger_entries(customer_id, currency, created_at)`, `(organisation_id, currency, created_at)`, `(source_payment_transaction_id, entry_type)`, `(commercial_document_id, entry_type)`; `refund_requests(status, requested_at)`, `(payment_transaction_id, status)`, `(customer_id, created_at)`, `(organisation_id, created_at)`; `commercial_documents(document_type, status, issue_date)`, `(related_document_id, document_type)`.

---

## 6. Query-plan evidence (MySQL 8.4.13)

| Query | Plan |
| --- | --- |
| Allocation totals by document for an `allocated_at` range | `payment_allocations` `type=ALL`, `key=NULL`, `Using temporary` → full scan |
| Credit ledger totals by entry type for a `created_at` range | `customer_credit_ledger_entries` `type=ALL`, `key=NULL` → full scan |
| Refund totals by status for a `requested_at` range | `refund_requests` `type=index`, `key=refund_status_time` → index scan (status not fixed, so not selective) |

These plans, together with the index inventory above, define the additive index work in the Stage 4D design. No schema change was made.

---

## 7. Security and RBAC findings

- Authorization is backend-enforced through a `permission:` middleware on every financial route; frontend guards are cosmetic duplicates.
- Existing financial permissions: `financials.view`, `financials.transactions.view`, `financials.reports.view`, `financials.reports.export`, `financials.reconciliation.view`, `financials.allocations.view/manage/reverse`, `financials.deposits.view/manage`, `financials.customer_credit.view/manage`, `financials.invoice_balances.view`, `financials.payments.confirm_cash`, and the nine Stage 4C permissions (`refunds.view/initiate/approve`, `credit_notes.view/create/approve`, `credit_reversals.view/initiate/approve`).
- `super-admin` holds every permission. The `admin` role is seeded **excluding** every `financials.*` permission.
- Stage 4C grants new **view** permissions to roles that already hold the corresponding read permission (e.g. `financials.refunds.view` to roles with `financials.transactions.view`), and grants **mutations** only to `super-admin`.
- Audit: `AuditService` records `stage4c.*` actions for every refund, credit-note and reversal mutation with before/after values.
- No read-only “auditor” role exists; no report-specific export permission exists beyond `financials.reports.export`.

---

## 8. Design system facts

- Single token-based Tailwind design system (`--color-*`, `--color-accent`, `--color-surface`, `--color-border`, `--color-text`), light/dark/system themes, user-selectable theme.
- Admin shell: `AdminLayout` with permission-gated navigation sections; reusable `ConfirmDialog` + `usePrompt`; existing table/filter/pagination patterns on `AdminFinancialsPage`, `AdminRefundsPage`, `AdminCustomerCreditPage`.
- Existing pages are responsive; current preview viewport is mobile (675 px), so report layouts must be usable at that width without horizontal scroll.

---

## 9. Stage 4C dependency summary

Every report that depends on Stage 4C is enumerated in `docs/STAGE-4D-STAGE-4C-DEPENDENCY-MATRIX.md`. In summary:

- **Hard dependencies (cannot ship without accepted 4C):** refund register/summary, refund pipeline metrics, unresolved-provider-outcome reporting, credit-note register (needs `related_document_id`), credit-reversal reporting (needs reversal entries and `credit_reversal_requests`), customer-credit statement types (`credit_note_credit`, `reversal`).
- **Soft dependencies (exist on `main`, extended by 4C):** customer-credit balances, allocation register, unallocated payments (must account for 4C reversal compensation).
- **No dependency:** order receipts, cash confirmation, delivery-fee receipts.

---

## 10. Existing reports vs missing capabilities

| Area | Exists today | Missing |
| --- | --- | --- |
| Dashboard | Daily order-receipt aggregates, channels, transaction list | Consolidated position, invoice receipts, allocations, credit, refunds, credit notes, reconciliation |
| Allocation register | Per-payment and per-invoice views inside the workbench | Global searchable register with reversal history and totals |
| Unallocated | Implicit “available” in the allocation workbench | Dedicated report, ageing, refund-reservation awareness |
| Customer credit | Balances and ledger per payer | Opening/closing statements, ageing, origin split, consumption attribution |
| Refunds | Operational queue | Register, stage summary, provider-outcome exceptions, initiator/approver columns |
| Credit notes | Operational create/issue/void | Register with applied/remaining, void history, authorizer columns |
| Settlement | None | Statement import, matching, fee/net, exceptions, audit |
| Exports | Order-transaction CSV only | Per-report CSV with filter and permission parity (Excel/PDF assessed) |

---

## 11. Risks

| Risk | Mitigation in design |
| --- | --- |
| Building 4D on `main` would miss 4C tables | Start from accepted 4C head; documented in §2.4 and the dependency matrix |
| Reusing the existing snapshot queries would silently omit invoice receipts | New reporting queries with `LEFT JOIN orders`; regression test for invoice receipts |
| Presenting refund-reserved money as available | Publish `available_allocatable` and `refundable` separately; never label one as the other |
| Treating `submitting` as confirmed | Refund metrics separate confirmed from submitted/submitting/unresolved; explicit exclusion rule |
| Inventing settlement data | Four reconciliation tiers; figures carry their source tier; no automated claim without verified provider capability |
| Divergent FIFO attribution | Reuse 4C attribution algorithm in a batched read service with parity tests |
| Stale cached figures | No caching of monetary aggregates as “current”; if cached, TTL ≤ 60 s with `generated_at` and explicit invalidation on financial events |
| Report export leaking data | Same backend permission + same filter object for screen and export; streamed, row-capped |

---

## 12. Classification

**CONDITIONALLY READY.**

The discovery and design are complete, internally consistent and ready for owner review. Implementation must not begin until all of the following are resolved:

1. **C1 — Stage 4C acceptance and merge.** PR #2 must be accepted and merged (or the owner must explicitly authorise Stage 4D to branch from the accepted 4C head `a6489c6`). Refund, credit-note and reversal reporting have hard dependencies on 4C schema.
2. **C2 — Owner decisions D1–D14** in `docs/STAGE-4D-OWNER-DECISION-REGISTER.md` must be approved, especially the settlement data source (D4) and report permission granularity (D1).
3. **C3 — Settlement source confirmation.** The owner must confirm whether reconciliation v1 is statement-import/manual only, and whether any provider settlement API is contractually available for this merchant account.
4. **C4 — Stage 4B remainder.** Staff-recorded deposit reporting stays out of scope until PR #1 is accepted; v1 reports deposits from `payment_receipt_intents` only.

No implementation change was made for any finding; findings G1–G13 are documented for the owner's decision and the Stage 4D implementation plan.

---

## 13. Deliverables produced

1. `docs/STAGE-4D-INITIAL-DISCOVERY-REPORT.md` (this document)
2. `docs/STAGE-4D-FINANCIAL-REPORTING-AND-RECONCILIATION-DESIGN.md`
3. `docs/STAGE-4D-IMPLEMENTATION-BREAKDOWN.md`
4. `docs/STAGE-4D-FINANCIAL-METRICS-REGISTER.md`
5. `docs/STAGE-4D-PERMISSIONS-MATRIX.md`
6. `docs/STAGE-4D-STAGE-4C-DEPENDENCY-MATRIX.md`
7. `docs/STAGE-4D-TESTING-AND-ACCEPTANCE-STRATEGY.md`
8. `docs/STAGE-4D-OWNER-DECISION-REGISTER.md`
