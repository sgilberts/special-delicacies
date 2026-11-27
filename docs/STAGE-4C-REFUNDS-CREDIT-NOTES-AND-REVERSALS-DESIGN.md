# Stage 4C — Refunds, Credit Notes and Customer Credit Reversals: Technical Design

**Status:** DESIGN READY FOR OWNER APPROVAL — no Stage 4C implementation has begun.
**Working branch:** `stage-4c-refunds-credit-notes` (stacked on the verified PR #1 head; not a merge).
**Design date:** 2026-11-27
**Classification:** **DESIGN READY FOR OWNER APPROVAL**

This document is discovery and design only. It proposes workflows, data-model additions,
permissions and integrity controls. It does **not** create migrations, services, endpoints or
UI, and it does **not** merge PR #1 or Stage 4C. Implementation starts only after the owner
signs off on the business decisions in §12.

---

## 1. Repository and branch state

| Item | Verified value |
|---|---|
| Working branch (discovery base) | `audit-deposits-credit` |
| HEAD at discovery | `feb0446` (`feb04463ca21976e923eb37e227825e1718c6252`) |
| Working tree | Clean (no uncommitted changes) |
| PR #1 | **OPEN**, mergeable, head `audit-deposits-credit`, base `main`, head commit `feb0446` |
| Stage 4C branch | `stage-4c-refunds-credit-notes`, stacked on the PR #1 head (separate PR; not merged) |
| Target database engine | MySQL **8.4.13** (verified in the running container) |
| Sandbox database name | `special_delicacies` |

> **Database-name note.** The execution brief names `special_delicacies_db` as the protected
> database. The sandbox development database is `special_delicacies`; the two names differ.
> No reset, truncate, reseed or destructive operation is performed in this stage. Automated
> tests are forced to in-memory SQLite by `backend/phpunit.xml` and the `TestCase` guard.

The financial tables in the sandbox currently contain **zero rows** (empty development
fixtures), so the design below is based on the schema and code, not on production data.

---

## 2. Stage 4B dependency verification

Stage 4B is **available and consistent on PR #1's head**, but it is **not merged**. Stage 4C is
therefore built on a stacked branch, exactly as the brief requires.

Verified present on this branch:

- `payment_transactions` with order-purpose and invoice-purpose receipts.
- `payment_allocation_operations`, `payment_allocations`, `payment_allocation_reversals`.
- `payment_receipt_intents` (deposit / advance classification).
- `customer_credit_ledger_entries` (append-only designation / application ledger).
- `commercial_documents`, `commercial_document_lines`, `commercial_document_histories`.
- `staff_deposit_records` with independent verification.
- `financials.*` permission slugs and route middleware.

Baseline regression: the Stage 4A/4B financial suites
(`PaymentAllocationTest`, `Stage4b*`, `CommercialDocumentsTest`, `FinancialManagementTest`)
run with **0 failures / 621 assertions**. The 84 reported warnings are benign: a test helper
reads `/workspace/backend/.env`, which does not exist because configuration is supplied by
Compose; they are not financial defects.

**Unresolved Stage 4B issues relevant to Stage 4C:** none that block design. Two facts matter:

1. **No refund capability exists.** `payment_transactions.status` accepts the string
   `refunded` and the financial dashboard reports it, but nothing ever sets it. There is no
   refund table, service, endpoint or provider-refund call.
2. **No credit-note capability exists.** `commercial_documents.document_type` supports only
   `quotation` and `invoice`; there is no link from a document back to an originating invoice.

---

## 3. Existing financial architecture findings

### 3.1 Canonical receipt ledger — `payment_transactions`

The single source of truth for money received.

- `transaction_reference` is unique and is the idempotency anchor for gateway charges.
- `payment_purpose` is `order` (checkout) or `invoice` (standalone receipt/deposit).
- `order_id` is nullable only for invoice-purpose receipts.
- `customer_id` / `organisation_id` identify exactly one payer (enforced by service logic and,
  for credit entries, by a CHECK constraint).
- `status`: `pending`, `succeeded`, `failed`, `cancelled` (and the never-written `refunded`).
- `provider`, `provider_reference`, `verified_at`, `received_at`, `confirmed_by`, `metadata`.
- `amount` is `decimal(12,2)`; `currency` is `char(3)` (GHS).

### 3.2 Payment allocation foundation (Stage 4A)

- `payment_allocation_operations` — one audited operation per allocation request
  (`operation_reference` unique, actor snapshot, payment unallocated before/after).
- `payment_allocations` — one row per invoice line of the operation, with invoice balance
  before/after; unique per (operation, document).
- `payment_allocation_reversals` — one row per reversed allocation, unique on
  `payment_allocation_id`; a reversal is a **new** record and never edits the allocation.
- Available receipt funds = `payment.amount − active allocations − credit designations`.
- `PaymentAllocationService` uses minor-unit integers (`toMinorUnits` / `formatMinorUnits`),
  `lockForUpdate`, and a unique-constraint catch for idempotent replay.

### 3.3 Receipt intents and customer credit (Stage 4B)

- `payment_receipt_intents` classifies a verified invoice-purpose receipt as `deposit` or
  `advance`; one row per payment, unique `operation_reference`.
- `customer_credit_ledger_entries` is **append-only**:
  - `entry_type` is `designation` (credit created from a receipt) or `application` (credit
    consumed against an invoice).
  - Balance is **derived**, never stored: `Σ designations − Σ applications`, per payer and
    currency.
  - The model throws on update/delete; corrections must be new compensating entries.
  - CHECK `credit_ledger_single_payer`: exactly one of `customer_id` / `organisation_id`.
  - CHECK `credit_ledger_entry_shape`: a designation must reference a source payment and no
    document; an application must reference a document and no source payment.
  - Every entry carries actor snapshots and before/after balances (credit, payment
    unallocated, invoice outstanding).

### 3.4 Commercial documents (Stage 3)

- `commercial_documents`: `document_type` (`quotation` | `invoice`), `status`
  (`draft` → `issued`; also `accepted`, `declined`, `expired`, `cancelled`, `voided`),
  payer (`customer_id`, optional `organisation_id`), `currency`, `total`, `issued_by`,
  `issued_at`, `voided_at`, `void_reason`.
- `commercial_document_lines`: priced lines with tax/discount snapshots.
- `commercial_document_histories`: append-only action log with actor, status transition,
  metadata, IP and user agent.
- Invoice voiding is blocked while active allocations exist, forcing allocation reversal first.

### 3.5 Staff deposits and separation of duties

`staff_deposit_records` already implements the pattern Stage 4C must reuse: staff record a
receipt (`recorded_by`), a **different** staff member verifies or rejects it (`verified_by`),
and the recorder can never verify their own record. Snapshots preserve actor identity after
user deletion.

### 3.6 Payment providers

`GatewayPaymentService` supports **Paystack, Hubtel and Korapay** for collection. It performs
server-side verification of collections (reference, amount, currency) before marking a payment
successful. **No refund API is implemented for any provider.** Stage 4C must add provider
refund calls and server-side refund verification for providers that support them, and must not
invent a new gateway.

### 3.7 Permissions and audit

- Permission slugs follow `financials.<area>.<action>`; routes enforce them with
  `permission:` middleware and services enforce business rules.
- `super-admin` receives every permission; `admin` receives no `financials.*` by default.
  View permissions are extended to roles that already have `financials.transactions.view`.
- `admin_audit_logs` (via `AuditService`) and the per-domain history tables provide audit
  evidence.

---

## 4. Current database schema and constraints (findings)

| Table | Key constraints relevant to Stage 4C |
|---|---|
| `payment_transactions` | `transaction_reference` unique; `amount decimal(12,2)`; `currency char(3)`; status string(30) |
| `payment_allocation_operations` | `operation_reference` unique; FK payment restrict |
| `payment_allocations` | unique (operation, document); FK operation/document restrict |
| `payment_allocation_reversals` | `payment_allocation_id` unique; `operation_reference` unique |
| `payment_receipt_intents` | `payment_transaction_id` unique; `operation_reference` unique |
| `customer_credit_ledger_entries` | `operation_reference` unique; CHECK `credit_ledger_single_payer`; CHECK `credit_ledger_entry_shape`; no `updated_at` |
| `commercial_documents` | `document_number` unique; `total decimal(14,2)`; `currency char(3)` |
| `commercial_document_lines` | FK document restrict |
| `staff_deposit_records` | external-reference uniqueness enforced in service |

Append-only guarantees are enforced at two levels: model events reject update/delete on credit
ledger entries, and financial history is expressed as new rows (allocation reversals,
document histories) rather than mutations.

---

## 5. Proposed refund workflow

### 5.1 States

A refund is a first-class record with an explicit lifecycle. A click or a browser success
response is **never** treated as a completed refund.

```
requested ──approve──▶ approved ──submit──▶ submitted ──provider verify──▶ confirmed
    │                      │                    │
    └──reject──▶ rejected  └──reject──▶ rejected└──provider failure──▶ failed
```

- `requested` — created by an authorised initiator; no money has moved.
- `approved` — a different authorised approver accepted it (separation of duties).
- `submitted` — the refund instruction was sent to the provider (or, for cash/manual, handed
  to the paying officer) and is awaiting authoritative confirmation.
- `confirmed` — the provider (server-side) or an authorised manual verifier confirmed the exact
  amount and currency were returned.
- `failed` / `rejected` — terminal non-payment states, with a recorded reason.

### 5.2 Eligibility and amount

- Refund targets one `payment_transaction` that is `succeeded`, verified and received.
- Refundable amount = `payment.amount − Σ confirmed refunds − Σ active allocations −
  Σ credit designations` (the receipt's currently uncommitted funds).
- Funds already allocated to an invoice or designated as customer credit are **not** directly
  refundable; they must first be released through the existing allocation reversal or the new
  credit reversal. This guarantees the same funds cannot be both allocated/credited and
  refunded.
- Partial refunds are supported only if the owner authorises them (decision D4). Full refund is
  always allowed within the refundable amount.
- Currency must equal the payment currency; payer identity must match the payment payer.

### 5.3 Provider verification

- **Paystack:** initiate a refund via `POST /refund` (transaction reference + amount), then
  verify with the provider's refund/transaction status endpoint. Only a provider-confirmed,
  amount-and-currency-matched result moves the refund to `confirmed`.
- **Hubtel / Korapay:** implement their documented refund endpoints only if the owner confirms
  the merchant account supports refunds; until then those refunds remain manual.
- **Cash / manual:** a refund is confirmed only by an authorised staff member recording the
  payment evidence and reference; approval separation still applies.
- Provider unavailability (decision D12): the refund stays `submitted`/`approved` with the
  error recorded; it is never marked confirmed. A retry is idempotent.

### 5.4 Approval and separation

- Initiation and approval are distinct permissions (`refunds.initiate`, `refunds.approve`).
- If the owner requires independent approval (decision D3), the approver must differ from the
  initiator, mirroring `staff_deposit_records`.
- Submission and verification are audited with actor snapshots.

### 5.5 Idempotency and concurrency

- `refund_reference` is unique and acts as the idempotency key. Replaying the same reference
  with the same payload returns the existing refund; a different payload is rejected.
- The payment row, payer row and refund row are locked with `lockForUpdate` inside one
  transaction; unique constraints are the final backstop.
- Concurrent refunds cannot exceed the refundable amount because the check runs under the
  payment lock.

### 5.6 Proposed data model (additive)

**`refund_requests`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `refund_reference` | string(100) unique | idempotency key |
| `payment_transaction_id` | FK restrict | original receipt |
| `customer_id` / `organisation_id` | nullable FKs, exactly one | payer snapshot from the payment |
| `amount` | decimal(14,2) | refunded amount |
| `currency` | char(3) | must match payment |
| `status` | string(20) | requested/approved/rejected/submitted/confirmed/failed |
| `reason` | text | required |
| `requested_by` | FK users nullOnDelete | initiator |
| `requested_by_name_snapshot` / `requested_by_reference_snapshot` | string | actor snapshot |
| `requested_at` | timestamp | |
| `approved_by` / `approved_at` / `approval_reason` | nullable | approver |
| `rejected_by` / `rejected_at` / `rejection_reason` | nullable | decision |
| `provider` | string(40) nullable | gateway used for the refund |
| `provider_refund_reference` | string(150) nullable | provider id |
| `submitted_at` / `confirmed_at` / `failed_at` | nullable timestamps | lifecycle |
| `failure_reason` | text nullable | |
| `metadata` | json nullable | |
| timestamps | | |

Indexes: (`payment_transaction_id`, `status`), (`status`, `requested_at`), payer index.

**`refund_events`** (append-only audit)

`id`, `refund_request_id` FK restrict, `action` (requested/approved/rejected/submitted/
confirmed/failed/verified), `from_status`, `to_status`, `actor_user_id` nullable +
snapshots, `reason`, `provider_reference`, `metadata` json, `created_at`.

### 5.7 Interaction with Stage 4A/4B

A refund never edits an allocation, a credit ledger entry or a commercial document. It only
consumes the receipt's uncommitted funds. Reporting can derive refunded totals from
`refund_requests` (status `confirmed`) without rewriting `payment_transactions.status`;
whether a fully refunded transaction is additionally marked `refunded` is decision D12a.

---

## 6. Proposed credit-note workflow

### 6.1 Document model

A credit note is its **own** commercial document, never an edit of the invoice.

- New `document_type` value `credit_note` (the column is a string; no type change required).
- New nullable `related_document_id` self-FK on `commercial_documents`, restricted on delete,
  pointing at the originating invoice. This is the traceability link.
- The credit note copies the invoice's payer, currency and line detail as snapshots; it carries
  its own `total` (the credited amount).
- `commercial_document_histories` records creation, approval/issue and void actions.

### 6.2 Eligibility

- The originating document must be an `invoice`, `issued`, non-voided, same payer, same
  currency.
- Credit note total ≤ invoice total − Σ prior issued credit notes for that invoice.
- A credit note cannot target a quotation, a draft, a voided invoice, or a different payer.

### 6.3 Full and partial

- Full credit note: total equals the invoice's remaining creditable amount.
- Partial credit note: allowed only if the owner authorises partial adjustments (decision D9);
  multiple partial credit notes are permitted up to the remaining creditable amount.

### 6.4 Outstanding balance and customer-credit interaction

To reuse the existing, tested balance maths rather than inventing a parallel one:

- Issuing an approved credit note writes a **new append-only ledger entry**
  `entry_type = credit_note_credit` for the full credit-note amount, with
  `commercial_document_id` = the credit note and the payer/currency of the invoice.
- The invoice's outstanding balance continues to be derived as
  `invoice.total − active allocations − credit applications`. A credit note reduces what is
  owed only when its credit is applied:
  - **Recommended default:** automatically create an `application` entry against the
    originating invoice for the portion that offsets its current outstanding balance, leaving
    any excess as available customer credit.
  - **Alternative (owner choice):** leave the whole credit note as available credit for the
    customer to apply later.
- A credit note never silently changes the original invoice total or erases its history; the
  invoice remains `issued` and its lines are untouched.

### 6.5 Approval and lifecycle

- Created as `draft` by an authorised creator (`credit_notes.create`).
- Issued (`issued`) by an authorised approver (`credit_notes.approve`) who must differ from the
  creator when independent approval is required (decision D8). `issued_by` is the approver;
  `created_by` is the initiator.
- Voiding a credit note is allowed only while its credit is unused; otherwise the credit must
  be reversed first.

### 6.6 Proposed data model (additive)

- `commercial_documents`: add nullable `related_document_id` (self-FK, restrictOnDelete) and
  an index on (`related_document_id`, `document_type`).
- No new table is required for credit notes; lines and histories are reused.
- `customer_credit_ledger_entries`: new `entry_type` `credit_note_credit` (see §7).

---

## 7. Proposed customer-credit reversal workflow

### 7.1 Ledger extension (append-only)

Reversals are **new** ledger entries; original entries are never updated or deleted.

- New `entry_type` value `reversal`.
- New nullable `reverses_entry_id` self-FK on `customer_credit_ledger_entries`
  (restrictOnDelete), pointing at the original `designation` or `credit_note_credit`.
- New nullable authorizer fields: `authorized_by_user_id` (FK users nullOnDelete),
  `authorized_by_name_snapshot`, `authorized_at` — used only for reversals requiring
  independent approval.
- The existing CHECK `credit_ledger_entry_shape` is replaced (drop + re-add) with a shape that
  additionally allows a reversal: `entry_type = 'reversal'` with `reverses_entry_id` NOT NULL,
  a positive amount, and the same payer/currency as the original. The existing
  `credit_ledger_single_payer` CHECK is unchanged. This is an additive constraint update, not
  a weakening: every entry type remains shape-checked.
- Derived balance becomes `Σ designations + Σ credit_note_credit − Σ applications −
  Σ reversals`, per payer and currency.

### 7.2 Eligibility, duplicates and over-reversal

- A reversal references exactly one original credit entry.
- Remaining reversible amount for that entry =
  `original.amount − credit consumed from it − Σ prior reversals of it`.
- **Consumed-credit attribution:** applications are attributed to credit entries
  chronologically (FIFO) within the same payer and currency. If the original entry's credit has
  already been consumed, the consumed portion cannot be reversed; only the unconsumed remainder
  can (decision D13). Over-reversal is rejected.
- Duplicate reversal is prevented by the unique `operation_reference` and by the remaining-
  amount check under a lock.
- Reversal of credit-note-derived credit additionally requires the credit note to be voided or
  is recorded as a linked adjustment (decision D10).

### 7.3 Approval and separation

- `credit_reversals.initiate` and `credit_reversals.approve`.
- If independent approval is required (decision D10), the authorizer differs from the
  initiator; both are recorded with snapshots.
- Customers can never reverse their own ledger entries; only staff with the permission can.

### 7.4 Idempotency and concurrency

- `operation_reference` is unique (existing column). Replay returns the existing reversal when
  the payload matches; a different payload is rejected.
- The payer row, the original ledger entry and the new entry are locked in one transaction.
- The remaining-amount check runs under the payer lock, so concurrent reversals cannot
  over-reverse.

### 7.5 Proposed data model (additive)

- `customer_credit_ledger_entries`: add `reverses_entry_id`, `authorized_by_user_id`,
  `authorized_by_name_snapshot`, `authorized_at`; index `reverses_entry_id`; replace the
  `credit_ledger_entry_shape` CHECK.
- No existing row is modified.

---

## 8. Shared financial integrity controls

| Control | Mechanism |
|---|---|
| Duplicate requests | Unique `refund_reference` / `operation_reference`; replay returns existing on identical payload, rejects otherwise |
| Idempotency | Deterministic references + unique constraints + QueryException duplicate classification |
| Concurrency | `DB::transaction` + `lockForUpdate` on payment, payer, document and ledger rows |
| Transaction locking | Consistent lock order (payer → payment → document → ledger) to avoid deadlocks |
| Decimal precision | Integer minor units via `PaymentAllocationService::toMinorUnits`; `decimal(14,2)` storage |
| Currency consistency | Refund/credit note/reversal currency must equal the source record |
| Audit logging | `refund_events`, `commercial_document_histories`, ledger entries, `admin_audit_logs` |
| Permission enforcement | Route middleware + service-level checks; frontend hiding is never sufficient |
| Cross-customer isolation | Payer identity enforced on every operation; customer endpoints read-only for own ledger |
| Original traceability | Every new record references its source payment, invoice or ledger entry |
| Stage 4A interaction | Refunds/credit notes never mutate allocations; reversals are separate rows |
| Stage 4B interaction | Credit reversals are compensating entries; balances remain derived |
| Double-spend prevention | Refundable = receipt − allocations − designations − confirmed refunds; reversal ≤ unconsumed credit |

---

## 9. Permission design

New slugs, following the existing `financials.<area>.<action>` convention:

| Slug | Purpose | Initial grant |
|---|---|---|
| `financials.refunds.view` | View refunds and history | Roles with `financials.transactions.view` |
| `financials.refunds.initiate` | Request a refund | super-admin only |
| `financials.refunds.approve` | Approve / verify a refund | super-admin only |
| `financials.credit_notes.view` | View credit notes | Roles with `commercial_documents.view` |
| `financials.credit_notes.create` | Create a draft credit note | super-admin only |
| `financials.credit_notes.approve` | Issue / void a credit note | super-admin only |
| `financials.credit_reversals.view` | View credit reversals | Roles with `financials.customer_credit.view` |
| `financials.credit_reversals.initiate` | Initiate a reversal | super-admin only |
| `financials.credit_reversals.approve` | Approve a reversal | super-admin only |

- View permissions are extended to the existing financial-viewer roles by migration, matching
  the Stage 4A/4B pattern.
- Mutation permissions go to `super-admin` only until the owner assigns them to finance roles.
- Every endpoint is backend-authorised; customers have no mutation route for any of these
  operations.

---

## 10. Data model and migration strategy

Additive migrations only, in this order:

1. `create_refund_requests_and_events_tables` — new tables, FKs, indexes, unique references.
2. `add_credit_note_link_to_commercial_documents` — nullable `related_document_id` self-FK +
   index; no change to existing rows.
3. `extend_customer_credit_ledger_for_reversals` — nullable `reverses_entry_id`, authorizer
   fields, index; drop and re-add `credit_ledger_entry_shape` CHECK with the new shape.
4. `add_stage4c_financial_permissions` — permission rows and role grants.

Rollback: drop the new tables/columns and restore the original CHECK constraint. All new
columns are nullable and all new tables are independent, so rollback loses no existing data.
No existing constraint is weakened; the append-only model events and the single-payer CHECK
remain in force.

---

## 11. Test strategy

Run on in-memory SQLite for fast feedback (guard verified: `phpunit.xml` forces
`sqlite :memory:` and `TestCase` refuses any other SQLite path). Do **not** present SQLite
results as MySQL compatibility evidence; repeat the critical suites against MySQL 8.4.13 in an
isolated disposable database before acceptance.

**Refunds:** full refund; partial refund (if authorised); duplicate request; failed provider
refund; pending provider refund; successful server-side verification; unauthorised refund;
concurrent requests; refund against an allocated payment (must be blocked until reversal).

**Credit notes:** full credit note; partial credit note (if authorised); invalid originating
document; duplicate credit note; unauthorised issuance; approval workflow (creator cannot
approve); invoice outstanding-balance calculation; interaction with allocations and credit.

**Credit reversals:** valid reversal; duplicate; over-reversal; reversal of consumed credit;
reversal of designated credit; unauthorised reversal; independent approval (if required);
concurrent attempts; exact before/after balances; payer and currency preservation.

**Integration / regression:** Stage 4A allocation compatibility; Stage 4B deposit and credit
compatibility; customer identity and verification; RBAC; financial audit logs; public website
functionality.

---

## 12. Owner decisions required

These cannot be safely inferred. Each row states the consequence, a recommended safe default,
the existing implementation it touches, and whether owner approval is mandatory.

| # | Decision | Financial consequence | Recommended safe default | Existing implementation | Owner approval |
|---|---|---|---|---|---|
| D1 | Who can initiate a refund | Controls who can start money leaving the business | `financials.refunds.initiate`, super-admin until assigned | `financials.*` RBAC | **Required** |
| D2 | Who can approve a refund | Controls authorisation of payout | `financials.refunds.approve`, super-admin | Staff-deposit verifier pattern | **Required** |
| D3 | Must initiator and approver differ | Prevents self-approved payouts | **Yes — different people** | `staff_deposit_records` recorder ≠ verifier | **Required** |
| D4 | Are partial refunds allowed | Affects refundable-amount rules | **Yes, up to uncommitted funds** | Allocation partial logic | **Required** |
| D5 | Refunds after allocation | Refunding allocated funds would double-spend | **No — reverse allocation first** | `payment_allocation_reversals` | **Required** |
| D6 | Refund to a different payment method | Changes payout destination and reconciliation | **No — same method/payer** | `payment_transactions` payer | **Required** |
| D7 | Which documents qualify for credit notes | Defines creditable scope | **Issued, non-voided invoices only** | `CommercialDocument` statuses | **Required** |
| D8 | Who issues/approves credit notes | Controls credit creation | Creator ≠ issuer; super-admin | `commercial_documents.issued_by` | **Required** |
| D9 | Partial credit notes allowed | Affects creditable remaining | **Yes, up to remaining creditable** | Document totals | **Required** |
| D10 | Credit reversal needs independent approval | Prevents unilateral credit removal | **Yes — different authorizer** | Staff-deposit pattern | **Required** |
| D11 | Fees/discounts/taxes in refunds and credit notes | Changes calculated amounts | **Refund the receipt amount; credit note mirrors invoice line totals** | Document line snapshots | **Required** |
| D12 | Provider unavailable during refund | Risk of false confirmation | **Stay pending, record error, retry idempotently; never auto-confirm** | Gateway verification | **Required** |
| D12a | Mark fully refunded transaction `refunded` | Reporting vs. history preservation | **Keep `succeeded`; report refunds from `refund_requests`** | Financial dashboard | **Required** |
| D13 | Already-designated/applied credit during reversal | Prevents reversing spent credit | **Reverse only unconsumed credit (FIFO attribution)** | `customer_credit_ledger_entries` | **Required** |

No irreversible financial behaviour is introduced before these are approved.

---

## 13. Risks and dependencies

- **Provider refund APIs are unimplemented.** Paystack refund support must be added and
  verified; Hubtel/Korapay refunds depend on merchant-account capability. Cash/manual refunds
  need an evidence-and-verification path.
- **Constraint migration risk.** Replacing `credit_ledger_entry_shape` must preserve all
  existing rows; it is additive but must be tested on MySQL 8.4.13 before release.
- **Consumed-credit attribution.** FIFO attribution must be deterministic and tested; without
  it, over-reversal is possible.
- **Double-counting.** Credit-note credit plus invoice application must not reduce outstanding
  twice; the recommended auto-apply design applies the credit note once.
- **Stage 4B not merged.** Stage 4C remains stacked on PR #1; merging order matters and is an
  owner decision.
- **MySQL vs SQLite.** Final acceptance must run on MySQL 8.4.13.

---

## 14. Implementation sequence (after approval)

1. **Schema + permissions:** additive migrations, model updates, permission grants, tests.
2. **Credit reversals:** service, endpoints, admin/customer views, tests (smallest, most
   self-contained increment).
3. **Credit notes:** document type, link, issue/void workflow, credit entry, UI, tests.
4. **Refunds:** request/approve/submit/verify lifecycle, provider refund calls, manual path,
   UI, tests.
5. **Integration and regression:** Stage 4A/4B compatibility, RBAC, audit, public site,
   MySQL 8.4.13 acceptance, documentation.

Each increment ships with focused tests and documented evidence; no merge of PR #1 or Stage 4C.

---

## 15. Required initial report (summary)

1. **Repository/branch:** `audit-deposits-credit` at `feb0446`, clean; PR #1 open/mergeable;
   Stage 4C stacked branch `stage-4c-refunds-credit-notes`.
2. **Stage 4B dependency:** present and consistent on PR #1 head; not merged; no blocking
   defects.
3. **Payment/credit architecture:** receipt ledger, allocation operations/allocations/
   reversals, receipt intents, append-only credit ledger, commercial documents, staff deposits.
4. **Ledger/constraints:** unique references, single-payer and entry-shape CHECKs, append-only
   model events, minor-unit decimals, GHS char(3).
5. **Refund workflow:** requested → approved → submitted → confirmed/failed/rejected with
   server-side provider verification and separation of duties.
6. **Credit-note workflow:** own document linked to the originating invoice; credit-note ledger
   entry; optional auto-application; original invoice never mutated.
7. **Credit-reversal workflow:** append-only compensating ledger entries referencing the
   original; FIFO consumed-credit attribution; duplicate/over-reversal prevention.
8. **Integrity risks:** provider refunds unimplemented, constraint migration, FIFO attribution,
   double-counting, MySQL acceptance.
9. **Owner decisions:** D1–D13 above; all require approval.
10. **Implementation sequence:** schema/permissions → reversals → credit notes → refunds →
    integration.
11. **Test/acceptance:** SQLite fast feedback, MySQL 8.4.13 acceptance, full refund/credit/
    reversal/regression matrix.

---

## 16. Classification

**DESIGN READY FOR OWNER APPROVAL**

The Stage 4B foundation required by Stage 4C is present and consistent, so the design is not
blocked by the dependency. Implementation is intentionally paused until the owner approves the
business decisions in §12; no financial behaviour has been changed.
