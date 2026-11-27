# Stage 4C — Refunds, Credit Notes and Customer Credit Reversals: Required Initial Report

**Status:** Discovery complete — implementation not started.
**Classification:** **DESIGN READY FOR OWNER APPROVAL**
**Branch:** `stage-4c-refunds-credit-notes`
**Report date:** 2026-11-27
**Design document:** `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md`

This report answers the eleven required items of the Stage 4C execution brief. It records
discovery and design only. No migration, model, service, endpoint, UI or permission change has
been made, and neither PR #1 nor Stage 4C has been merged.

---

## 0. Design document confirmation

The design document is **present on this branch** and complete (570 lines, 31.8 KB, all 16
sections).

| Check | Verified value |
|---|---|
| File | `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md` |
| Commit that added it | `6d29dd7` (`Save changes before switching branches`) |
| Parent of that commit | `feb0446` — the PR #1 head at discovery time |
| Diff from PR #1 head | 1 file changed, 570 insertions, 0 deletions — the document only |
| Working tree | Clean |

**Branch-state drift to note.** At discovery the PR #1 head was `feb0446` and the Stage 4C
branch was stacked on it. The design-doc commit `6d29dd7` was then written on the
`audit-deposits-credit` branch, so **both `audit-deposits-credit` (PR #1 head) and
`stage-4c-refunds-credit-notes` now point at `6d29dd7`**. The stacked branch therefore carries
the document because it sits on the same commit, and PR #1's head has advanced past the
`feb0446` value recorded in §1 of the design document. That table is a discovery-time snapshot;
the live values are given in item 1 below.

---

## 1. Repository and branch state

| Item | Verified value (this report) |
|---|---|
| Working branch | `stage-4c-refunds-credit-notes` |
| HEAD | `6d29dd7` (`6d29dd7b77c94acf500fc1703234f164982de489`) |
| Working tree | Clean (no uncommitted changes) |
| Base of the stack | `feb0446`, confirmed ancestor of HEAD |
| PR #1 | **OPEN**, `MERGEABLE`, head `audit-deposits-credit`, base `main` |
| PR #1 head commit | `6d29dd7` (includes the Stage 4C design document) |
| Stage 4C branch head | `6d29dd7` (same commit — stacked, not diverged) |
| Merge status | PR #1 **not merged**; Stage 4C **not merged**, no PR opened for it |
| Target database | MySQL **8.4.13** (verified live in the running container) |
| Sandbox database | `special_delicacies` |

The sandbox stack is healthy: `db` (mysql:8.4), `web` (nginx on host port 3000) and `mailpit`
all report healthy.

---

## 2. Stage 4B dependency verification

Stage 4B is **present and consistent** on the PR #1 head, and Stage 4C is correctly stacked on
it rather than assuming a merge.

Verified live in the running MySQL 8.4.13 schema — all ten financial tables exist:

`payment_transactions`, `payment_allocation_operations`, `payment_allocations`,
`payment_allocation_reversals`, `payment_receipt_intents`, `customer_credit_ledger_entries`,
`commercial_documents`, `commercial_document_lines`, `commercial_document_histories`,
`staff_deposit_records`.

Both ledger CHECK constraints are present: `credit_ledger_single_payer` and
`credit_ledger_entry_shape`.

Baseline regression from the discovery run: the Stage 4A/4B financial suites
(`PaymentAllocationTest`, `Stage4b*`, `CommercialDocumentsTest`, `FinancialManagementTest`)
completed with **0 failures / 621 assertions**. The 84 warnings are benign test-helper
environment reads, not financial defects. Not re-run for this report because no code has
changed since that run.

**No Stage 4B issue blocks Stage 4C design.** Two capability gaps define the work:

1. **No refund capability.** `payment_transactions.status` accepts `refunded` and the dashboard
   reports it, but no code path sets it; there is no refund table, service, endpoint or provider
   refund call.
2. **No credit-note capability.** `commercial_documents.document_type` supports only
   `quotation` and `invoice`, and there is no link from a document to an originating invoice.

---

## 3. Existing payment and credit architecture

- **`payment_transactions`** is the canonical receipt ledger: unique `transaction_reference`
  (idempotency anchor), `payment_purpose` of `order` or `invoice`, nullable `order_id` only for
  invoice-purpose receipts, payer identity, provider references, `amount decimal(12,2)` and
  `currency char(3)` (GHS).
- **Allocation foundation (Stage 4A):** `payment_allocation_operations` (one audited operation
  per request, unique `operation_reference`), `payment_allocations` (one row per invoice line,
  balance before/after), `payment_allocation_reversals` (one row per reversed allocation, unique
  on `payment_allocation_id`). Reversals are new records; allocations are never edited.
  Available receipt funds = `amount − active allocations − credit designations`.
- **Receipt intents and credit (Stage 4B):** `payment_receipt_intents` classifies a verified
  invoice-purpose receipt as `deposit` or `advance`.
  `customer_credit_ledger_entries` is append-only (`designation` / `application`), balance is
  derived as `Σ designations − Σ applications` per payer and currency, and the model throws on
  update/delete.
- **Commercial documents (Stage 3):** `quotation` / `invoice` with priced line snapshots and an
  append-only history table; invoice voiding is blocked while active allocations exist.
- **Staff deposits:** `staff_deposit_records` already implements recorder ≠ verifier
  separation of duties with actor snapshots — the pattern Stage 4C reuses.
- **Providers:** `GatewayPaymentService` supports Paystack, Hubtel and Korapay for collection
  with server-side verification. **No refund API exists for any provider.**
- **Permissions:** `financials.<area>.<action>` slugs enforced by route middleware plus service
  rules; `super-admin` holds all, `admin` holds no `financials.*` by default.

---

## 4. Existing ledger and database constraints

| Table | Constraint relevant to Stage 4C |
|---|---|
| `payment_transactions` | `transaction_reference` unique; `amount decimal(12,2)`; `currency char(3)`; status string |
| `payment_allocation_operations` | `operation_reference` unique; FK payment restrict |
| `payment_allocations` | unique (operation, document); FK restrict |
| `payment_allocation_reversals` | `payment_allocation_id` unique; `operation_reference` unique |
| `payment_receipt_intents` | `payment_transaction_id` unique; `operation_reference` unique |
| `customer_credit_ledger_entries` | `operation_reference` unique; CHECK `credit_ledger_single_payer`; CHECK `credit_ledger_entry_shape`; no `updated_at` |
| `commercial_documents` | `document_number` unique; `total decimal(14,2)`; `currency char(3)` |
| `commercial_document_lines` | FK document restrict |
| `staff_deposit_records` | external-reference uniqueness enforced in the service |

Append-only behaviour is enforced at two levels: model events reject update/delete on credit
ledger entries, and financial change is expressed as new rows (allocation reversals, document
histories). All money maths uses minor-unit integers via
`PaymentAllocationService::toMinorUnits`.

The financial tables currently hold **zero rows** (live check: 0 credit ledger entries,
0 payment transactions, no document types present). The design is therefore based on schema and
code, not production data.

---

## 5. Proposed refund workflow

A refund is a first-class record, never inferred from a click or a browser success response.

```
requested ──approve──▶ approved ──submit──▶ submitted ──provider verify──▶ confirmed
    │                      │                    │
    └──reject──▶ rejected  └──reject──▶ rejected└──provider failure──▶ failed
```

- **Eligibility:** one `succeeded`, verified, received `payment_transaction`.
- **Refundable amount:** `payment.amount − Σ confirmed refunds − Σ active allocations −
  Σ credit designations`. Allocated or designated funds must be released through an existing
  allocation reversal or a new credit reversal first, so the same funds cannot be both committed
  and refunded.
- **Verification:** Paystack refund initiation plus server-side refund/transaction status
  verification, matching exact amount and currency. Hubtel/Korapay only if the merchant account
  supports refunds; otherwise manual. Cash/manual refunds require an authorised verifier with
  recorded evidence.
- **Separation of duties:** `refunds.initiate` and `refunds.approve` are distinct; the approver
  must differ from the initiator when the owner requires it.
- **Idempotency:** unique `refund_reference`; replay with identical payload returns the existing
  refund, different payload is rejected.
- **Data model (additive):** `refund_requests` (lifecycle, payer, amount, currency, actor
  snapshots, provider references, reasons) and append-only `refund_events`.
- Refunds never edit allocations, ledger entries or commercial documents.

---

## 6. Proposed credit-note workflow

A credit note is its **own** commercial document, never an edit of the invoice.

- **Model:** new `document_type` value `credit_note` (string column, no type change) plus a
  nullable self-FK `related_document_id` on `commercial_documents` pointing at the originating
  invoice. Lines and histories are reused; the invoice is never mutated.
- **Eligibility:** originating document must be an `issued`, non-voided `invoice`, same payer and
  currency. Credit total ≤ invoice total − prior issued credit notes for it.
- **Lifecycle:** `draft` created by an authorised creator, `issued` by an authorised approver who
  must differ from the creator when independent approval is required; voiding only while the
  credit is unused.
- **Balance interaction:** issuing writes a new append-only ledger entry
  `entry_type = credit_note_credit` for the credit-note amount. Invoice outstanding stays
  derived as `total − active allocations − credit applications`. Recommended default:
  auto-apply the portion that offsets the invoice's outstanding balance, leaving any excess as
  available customer credit (owner alternative: leave it all as available credit).
- **Data model (additive):** `related_document_id` + index; no new table.

---

## 7. Proposed credit-reversal workflow

Reversals are **new** append-only ledger entries; originals are never updated or deleted.

- **Model:** new `entry_type` value `reversal`, nullable self-FK `reverses_entry_id`, and
  authorizer fields (`authorized_by_user_id`, name snapshot, `authorized_at`). The
  `credit_ledger_entry_shape` CHECK is dropped and re-added to permit the reversal shape;
  `credit_ledger_single_payer` is unchanged.
- **Derived balance:** `Σ designations + Σ credit_note_credit − Σ applications − Σ reversals`.
- **Limits:** remaining reversible = `original.amount − credit consumed from it − Σ prior
  reversals`. Applications are attributed FIFO within payer and currency, so already-consumed
  credit cannot be reversed (decision D13). Over-reversal and duplicates are rejected under a
  payer lock plus the unique `operation_reference`.
- **Approval:** `credit_reversals.initiate` / `credit_reversals.approve`, independent authorizer
  when required; customers have no mutation route.
- Reversal of credit-note-derived credit requires the credit note to be voided or recorded as a
  linked adjustment (decision D10).

---

## 8. Financial integrity risks

| Risk | Mitigation in the design |
|---|---|
| Double-spend between refund, allocation and credit | Refundable excludes allocations/designations; allocated or designated funds must be reversed first |
| Over-reversal of consumed credit | FIFO consumed-credit attribution plus remaining-amount check under lock |
| Duplicate submissions | Unique `refund_reference` / `operation_reference`; identical replay returns existing, different payload rejected |
| Concurrency | `DB::transaction` + `lockForUpdate` with a fixed lock order (payer → payment → document → ledger) |
| False refund confirmation | Only provider-confirmed or authorised-manual verification moves a refund to `confirmed`; provider failure stays pending with the error recorded |
| Constraint migration on live ledger | `credit_ledger_entry_shape` replacement must preserve all existing rows; must be tested on MySQL 8.4.13 before release |
| Double-counting credit note vs. invoice | Recommended auto-apply design applies the credit note exactly once |
| Provider refund APIs unimplemented | Paystack refund support must be added and verified; Hubtel/Korapay depend on merchant capability; cash/manual needs an evidence path |
| SQLite-only test confidence | Final acceptance must be repeated on MySQL 8.4.13 |

**No irreversible financial behaviour is introduced by this design.**

---

## 9. Required owner decisions

All thirteen decisions in §12 of the design document require owner approval. Recommended safe
defaults:

| # | Decision | Recommended default |
|---|---|---|
| D1 | Who can initiate a refund | `financials.refunds.initiate`, super-admin until assigned |
| D2 | Who can approve a refund | `financials.refunds.approve`, super-admin |
| D3 | Must initiator and approver differ | **Yes — different people** |
| D4 | Are partial refunds allowed | **Yes, up to uncommitted funds** |
| D5 | Refunds after allocation | **No — reverse allocation first** |
| D6 | Refund to a different payment method | **No — same method and payer** |
| D7 | Which documents qualify for credit notes | **Issued, non-voided invoices only** |
| D8 | Who issues/approves credit notes | Creator ≠ issuer; super-admin |
| D9 | Partial credit notes allowed | **Yes, up to remaining creditable** |
| D10 | Credit reversal needs independent approval | **Yes — different authorizer** |
| D11 | Fees/discounts/taxes treatment | Refund the receipt amount; credit note mirrors invoice line totals |
| D12 | Provider unavailable during refund | Stay pending, record error, retry idempotently, never auto-confirm |
| D12a | Mark fully refunded transaction `refunded` | **Keep `succeeded`**; report refunds from `refund_requests` |
| D13 | Already-designated/applied credit during reversal | Reverse only unconsumed credit (FIFO attribution) |

---

## 10. Proposed implementation sequence

1. **Schema + permissions** — additive migrations, model updates, permission rows/grants, tests.
2. **Credit reversals** — service, endpoints, views, tests (smallest, most self-contained).
3. **Credit notes** — document type and link, issue/void workflow, ledger entry, UI, tests.
4. **Refunds** — request/approve/submit/verify lifecycle, provider refund calls, manual path, UI,
   tests.
5. **Integration and regression** — Stage 4A/4B compatibility, RBAC, audit, public site,
   MySQL 8.4.13 acceptance, documentation.

Each increment ships with focused tests and documented evidence. No merge of PR #1 or Stage 4C
occurs in any increment.

---

## 11. Test and acceptance strategy

- **Fast feedback:** in-memory SQLite via `backend/phpunit.xml`, with the `TestCase` guard that
  refuses any non-`:memory:` SQLite path. SQLite results are **not** MySQL compatibility
  evidence.
- **Refunds:** full refund; partial (if authorised); duplicate; failed provider refund; pending
  provider refund; successful server-side verification; unauthorised refund; concurrent requests;
  refund against an allocated payment must be blocked until reversal.
- **Credit notes:** full; partial (if authorised); invalid originating document; duplicate;
  unauthorised issuance; creator cannot approve; outstanding-balance calculation; interaction
  with allocations and credit.
- **Credit reversals:** valid; duplicate; over-reversal; reversal of consumed credit; reversal of
  designated credit; unauthorised; independent approval (if required); concurrency; exact
  before/after balances; payer and currency preservation.
- **Regression:** Stage 4A allocation compatibility, Stage 4B deposit/credit compatibility,
  customer identity and verification, RBAC, financial audit logs, public website.
- **Acceptance gate:** repeat the critical financial suites against MySQL 8.4.13 in an isolated
  disposable database before acceptance. Never run destructive operations against the protected
  database.

---

## 12. Classification

**DESIGN READY FOR OWNER APPROVAL**

Stage 4B is present and consistent on the stacked base, so the dependency does not block Stage
4C. The only outstanding blocker is the owner's approval of decisions **D1–D13** in §12 of the
design document; those are business rules that cannot be safely inferred.

## 13. Explicitly not done

- No Stage 4C implementation, service, endpoint or UI.
- No migration created or run; no schema changed.
- No application code changed (this branch's only commit adds documentation).
- PR #1 not merged; Stage 4C not merged and no PR opened for it.
- No destructive operation against the database; no production deployment.

**Status: paused, awaiting owner approval of D1–D13.**
