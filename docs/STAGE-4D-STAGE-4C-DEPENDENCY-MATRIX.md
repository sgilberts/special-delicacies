# Stage 4D — Stage 4C Dependency Matrix

**Status:** Design reference — Stage 4C is unmerged and unaccepted at the time of writing.
**Date:** 2026-11-27
**Stage 4C head inspected:** `a6489c6` on `refunds-credit-notes` (PR #2, open, mergeable, base `main`).

---

## 1. Branch and merge conditions

| Condition | State | Consequence for Stage 4D |
| --- | --- | --- |
| Stage 4C accepted by owner | Not established | Implementation cannot begin |
| Stage 4C merged into `main` | No (PR #2 open) | 4D must branch from the accepted 4C head, not `main` |
| Stage 4B remainder merged (PR #1) | No (open) | Staff-recorded deposits out of scope for 4D v1 |
| Sandbox schema | All 4C migrations applied, all financial tables empty | Schema is inspectable; no volume evidence |

**Required starting point:** a branch created from the accepted Stage 4C head (`a6489c6`, or the merge commit once PR #2 lands). If PR #2 merges first, branch from `main` after the merge and verify the five 4C migrations are present before starting 4D-0.

**Prohibited:** branching 4D from `main` while 4C is unmerged, cherry-picking 4C tables into 4D, or recreating refund/credit-note/reversal structures.

---

## 2. Dependency classes

- **Hard** — the report cannot produce a truthful figure without the 4C artifact.
- **Soft** — the figure exists without 4C but changes behaviour once 4C is present.
- **None** — independent of 4C.

---

## 3. Report → Stage 4C artifact matrix

| Stage 4D module | 4C artifact | Class | Why | If 4C absent |
| --- | --- | --- | --- | --- |
| Dashboard — refund block | `refund_requests`, `refund_events`, `RefundService` statuses | Hard | Confirmed/in-flight/unresolved refund figures | Block: no refund data source |
| Dashboard — credit block | `customer_credit_ledger_entries` types `credit_note_credit`, `reversal` | Hard | Closing balance and origin split | Would understate credit and misclassify reversals |
| Dashboard — position bridge | Refund amounts + net designation rule (`creditDesignatedMinorForPayment`) | Hard | `A − Fc = X + D + U + Fo` | Bridge cannot balance |
| Allocation register | `PaymentAllocationService` incl. 4C reversal compensation | Soft | Credit-designated amount must net reversals | Would overstate designated credit |
| Unallocated payments | `RefundService::refundableMinor()` rule + net designation | Hard | “Available” must exclude refund-reserved funds | Would present reserved money as available |
| Customer-credit statement | Ledger entry types `designation`, `application`, `credit_note_credit`, `reversal`; `reverses_entry_id`; `authorized_by_*` | Hard | Statement completeness and authorizer column | Statement incomplete |
| Customer-credit ageing | FIFO attribution (`CustomerCreditReversalService::remainingReversibleMinor`) | Hard | Per-entry remaining credit | No defensible ageing |
| Refund register | `refund_requests` (all columns), `refund_events` (all actions) | Hard | Stage amounts, initiator/approver, provider reference, unresolved outcomes | Module impossible |
| Refund exceptions | `status='submitting'`, events `provider_error` / `reconciliation_mismatch` | Hard | Must not present unresolved as confirmed | Risk of false confirmation |
| Credit-note register | `commercial_documents.related_document_id`, credit-note lifecycle (`CreditNoteService`), `credit_note_credit` entry | Hard | Issued/applied/remaining and invoice linkage | No linkage, no ledger effect |
| Credit-note void history | Ledger `reversal` entry with `reverses_entry_id` = note's credit entry | Hard | Voided amount and reversal date | Void history unavailable |
| Settlement reconciliation | `refund_requests.provider_refund_reference`, refund settlement lines | Soft | Refund debits must reconcile too | Cash-in reconciliation only |
| Order receipts / cash confirmation | none | None | Independent of 4C | Works |

---

## 4. Dependency on Stage 4A and 4B

| Stage 4D module | 4A/4B artifact | Branch | Class |
| --- | --- | --- | --- |
| Allocation register | `payment_allocations`, `payment_allocation_operations`, `payment_allocation_reversals` | `main` | Hard |
| Unallocated payments | allocation tables + `CustomerCreditService` designation rule | `main` | Hard |
| Customer credit | `customer_credit_ledger_entries`, `payment_receipt_intents` | `main` | Hard |
| Deposit/advance reporting | `payment_receipt_intents.intent` | `main` | Hard |
| Staff-recorded deposits | `staff_deposit_records`, `DepositService` | PR #1 only | **Out of scope** until accepted |
| Invoice settlement | `commercial_documents` + allocation/credit rules | `main` | Hard |

---

## 5. Integration contracts Stage 4D must honour

1. **Do not recompute a balance with a different rule.** Invoice outstanding, credit balance, refundable amount and credit-designated amount must call or exactly mirror the existing service methods:
   - `PaymentAllocationService::balance()`, `invoiceOutstandingMinor()`, `paymentAllocatedMinorForPayment()`, `creditDesignatedMinorForPayment()`, `availableMinorForPayment()`;
   - `CustomerCreditService::balanceMinor()`, `currentBalanceMinor()`;
   - `RefundService::refundableMinor()`;
   - `CustomerCreditReversalService::remainingReversibleMinor()`.
2. **Do not write to 4C tables.** Reporting is read-only; reconciliation writes only to Stage 4D tables.
3. **Do not treat `payment_transactions.status` as a refund indicator.** D12a keeps it `succeeded`; refunds come from `refund_requests`.
4. **Do not assume `refund_requests.amount` is stage-specific.** Stage amounts come from the current status; history comes from `refund_events`.
5. **Do not assume per-note applied amounts are stored.** Derive with FIFO attribution (§7.6 of the design) and prove parity in tests.
6. **Respect the ledger's append-only invariant.** No reporting query may update or delete a ledger entry, and no reporting feature may offer an “edit balance” action.
7. **Respect `stage4c.php` policy.** If the owner enables `provider_submission_enabled` or `mark_transaction_refunded` later, reports must read the policy rather than hard-code today's defaults; `refunded_amount` must not silently switch source.

---

## 6. Fragile points in the 4C contract (flagged for implementation)

| Point | Risk | Handling |
| --- | --- | --- |
| `operation_reference` conventions (`cn:<number>:apply`, `cn:<number>:void`, `cn:<number>:credit`) | String convention, not a foreign key | Use only for the labelled “applied to origin” figure; authoritative attribution uses FIFO remaining |
| `metadata.mode` (`manual` / `provider`) on refunds | JSON, not a column | Read defensively; provider-submission rate is informational only |
| `refund_events` action vocabulary | Grows with 4C remediation | Report timelines from the stored actions, do not whitelist silently; unknown actions render verbatim |
| `creditDesignatedMinorForPayment()` nets reversals by joining on `reverses_entry_id` | Requires the 4C ledger extension migration | Verify migration present before computing; fail fast if absent |
| Refund `submitting` rows | Legitimately long-lived until reconciled | Exceptions view, never auto-expire or auto-fail |

---

## 7. Behaviour when a dependency is missing

Reports must **fail closed**:

- On boot or first use, a Stage 4D capability check asserts the required tables/columns exist (`Schema::hasTable`, `Schema::hasColumn`).
- A missing 4C artifact disables the affected report with an explicit “requires Stage 4C (not deployed)” state and a 409/503 response, never a zero-filled report.
- A missing index degrades performance but never correctness; a missing column is a hard error.

---

## 8. Post-merge verification checklist

1. Five 4C migrations present and applied (`refund_requests`, `refund_events`, `credit_reversal_requests`, `related_document_id`, ledger extension, 4C permissions).
2. `refund_requests.status` supports `submitting`.
3. `customer_credit_ledger_entries.reverses_entry_id` and `authorized_by_*` exist.
4. `commercial_documents.related_document_id` exists with its index.
5. 4C permission slugs seeded and granted as documented.
6. Stage 4C feature tests pass on the 4D branch before any 4D test is added.
7. No 4D migration references a 4C table that does not exist yet.
