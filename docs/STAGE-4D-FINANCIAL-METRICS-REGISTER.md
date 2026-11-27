# Stage 4D — Financial Metrics and Formulas Register

**Status:** Design reference — no metric is implemented yet.
**Date:** 2026-11-27
**Companion to:** `docs/STAGE-4D-FINANCIAL-REPORTING-AND-RECONCILIATION-DESIGN.md` §3–§4.

Conventions used in every formula:

- `Σ` is a SQL aggregate over `DECIMAL` columns; the result is converted to integer minor units with `PaymentAllocationService::toMinorUnits()` before any arithmetic.
- All figures are produced **per currency** and never summed across currencies.
- `A` = receipts received, `X` = active allocations, `D` = net cash-derived credit designations, `Fn` = net credit-note credit, `Ap` = credit applications, `Fc` = confirmed refunds, `Fo` = refunds in flight (`submitted`, `submitting`), `Fp` = refund pipeline (`requested`, `approved`), `U` = unallocated available.
- “Active allocation” means a `payment_allocations` row with no `payment_allocation_reversals` row.
- Timezone for day boundaries: `config('app.timezone')` = `Africa/Accra`.

---

## 1. Receipts

| ID | Metric | Formula | Source | Included | Excluded | Currency | Date basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| M-01 | Gross receipts received | `Σ pt.amount` | `payment_transactions` | `status='succeeded'` AND `received_at IS NOT NULL` | pending, failed, cancelled, `refunded`-flagged legacy rows | grouped by `pt.currency` | `received_at` |
| M-02 | Receipt count | `COUNT(pt.id)` with M-01 filters | same | same | same | n/a | same |
| M-03 | Cash receipts | M-01 where `payment_method='cash'` | same | same | same | same | same |
| M-04 | Gateway receipts | M-01 where `payment_method='gateway'` | same | same | same | same | same |
| M-05 | Receipts by provider | M-01 grouped by `provider` (`paystack`, `hubtel`, `korapay`, null for cash) | same | same | same | same | same |
| M-06 | Order receipts | M-01 where `payment_purpose='order'` | same | same | invoice receipts | same | same |
| M-07 | Invoice receipts | M-01 where `payment_purpose='invoice'` | same | same | order receipts | same | same |
| M-08 | Delivery-fee receipts | M-01 where `transaction_reference LIKE 'DELIVERY-FEE-CASH-%'` | same | separate cash row written by `confirmDeliveryFeeCash` | all other receipts | same | same |
| M-09 | Pending receipt amount | `Σ pt.amount` where `status='pending'` | same | pending | succeeded/failed/cancelled | same | `created_at` (no `received_at`) |
| M-10 | Failed receipt amount | `Σ pt.amount` where `status='failed'` | same | failed | others | same | `verified_at` fallback `created_at` |
| M-11 | Cancelled receipt amount | `Σ pt.amount` where `status='cancelled'` | same | cancelled | others | same | `verified_at` fallback `created_at` |
| M-12 | Legacy receipts without `received_at` | `COUNT(*)` where `status='succeeded' AND received_at IS NULL` | same | integrity exception only | not counted in M-01 | n/a | n/a |
| M-13 | Average receipt value | `M-01 / M-02` (minor units, rounded half-up to 2 dp for display only) | derived | M-01/M-02 population | n/a | per currency | M-01 basis |

Notes: M-01 is the only “money in” figure. M-09–M-11 are never added to it. M-08 exists because delivery fees are separate receipts; it is a subset of M-06, not an addition to it.

---

## 2. Allocation

| ID | Metric | Formula | Source | Included | Excluded | Currency | Date basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| M-20 | Allocated amount (active) | `Σ pa.amount` where no reversal | `payment_allocations` ⟕ `payment_allocation_reversals` | active rows | reversed rows | `pa.currency` | `pa.allocated_at` |
| M-21 | Reversed allocation amount | `Σ pa.amount` where reversal exists | same | reversed rows | active rows | `pa.currency` | `pr.reversed_at` |
| M-22 | Allocation operations | `COUNT(DISTINCT po.id)` | `payment_allocation_operations` | all | n/a | n/a | `po.allocated_at` |
| M-23 | Receipts fully allocated | count of receipts where `allocatable = 0` and `A > 0` | derived per receipt | succeeded invoice/order receipts | receipts with residual | n/a | receipt date |
| M-24 | Receipts partially allocated | count where `0 < X + D < A` | derived | same | same | n/a | receipt date |
| M-25 | Receipts with no allocation | count where `X + D = 0` | derived | same | same | n/a | receipt date |
| M-26 | Invoice settled by allocation | `Σ` active allocations grouped by `commercial_document_id` | `payment_allocations` | issued invoices | quotations, voided | invoice currency | `allocated_at` |
| M-27 | Invoice settled by credit | `Σ` applications grouped by `commercial_document_id` | `customer_credit_ledger_entries` | `entry_type='application'` | designations, reversals | invoice currency | `created_at` |
| M-28 | Invoice outstanding | `invoice.total − M-26 − M-27`, floored at 0 for display but flagged if negative | `commercial_documents` + M-26/M-27 | `document_type='invoice'`, `status='issued'`, `voided_at IS NULL` | draft, voided, quotations | invoice currency | as-of query time |
| M-29 | Invoice settlement state | `unpaid` if M-26+M-27 = 0; `partially_paid` if 0 < settled < total; `fully_paid` if settled = total | derived | same as M-28 | same | n/a | as-of |

M-20 and M-21 are both reported; M-20 is never reduced by M-21 in the same column (that would hide history).

---

## 3. Unallocated funds

| ID | Metric | Formula | Source | Included | Excluded | Currency | Date basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| M-30 | Allocatable amount per receipt | `A − X − D` | `PaymentAllocationService::availableMinorForPayment()` | succeeded receipts | refund-reserved funds | receipt currency | receipt date |
| M-31 | Refund-reserved per receipt | `Fc + Fo + Fp` for that receipt | `refund_requests` | live statuses | rejected, failed | receipt currency | request date |
| M-32 | Available (refundable) per receipt | `A − X − D − Fc − Fo − Fp` | `RefundService::refundableMinor()` rule | same as M-30 | same | receipt currency | receipt date |
| M-33 | Total unallocated cash held (`U`) | `Σ (A − X − D − Fc − Fo)` per receipt | derived | receipts with residual cash | fully applied receipts | per currency | receipt date |
| M-34 | Unallocated ageing | days between receipt date and as-of date, bucketed 0–7/8–30/31–60/61–90/90+ | derived from M-32 rows | M-32 > 0 | fully applied receipts | per currency | receipt date |
| M-35 | Receipts holding available funds | `COUNT` of M-32 > 0 | derived | same | same | n/a | receipt date |
| M-36 | Negative-residual exceptions | receipts where `A − X − D − Fc − Fo − Fp < 0` | derived | integrity exception | none | per currency | receipt date |
| M-37 | Total refundable (available) | `Σ M-32` = `M-33 − Fp` | derived | M-32 > 0 | M-32 = 0 | per currency | receipt date |

M-30, M-33 and M-32/M-37 are all published and are three different figures. The UI labels M-30 “allocatable (ignores refund reservations)”, M-33 “unallocated cash held”, and M-32/M-37 “available to refund”. Only M-32/M-37 may be described as available money.

---

## 4. Customer credit

| ID | Metric | Formula | Source | Included | Excluded | Currency | Date basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| M-40 | Credit credited | `Σ amount` where `entry_type IN ('designation','credit_note_credit')` | `customer_credit_ledger_entries` | both credit types | applications, reversals | `currency` | `created_at` |
| M-41 | Cash-designated credit | `Σ amount` where `entry_type='designation'` | same | designations | credit-note credit | same | same |
| M-42 | Credit-note credit | `Σ amount` where `entry_type='credit_note_credit'` | same | credit notes | designations | same | same |
| M-43 | Credit applied | `Σ amount` where `entry_type='application'` | same | applications | credits | same | same |
| M-44 | Credit reversed | `Σ amount` where `entry_type='reversal'` | same | reversals | credits/applications | same | same |
| M-45 | Closing credit balance | `M-40 − M-43 − M-44` | same | all entries ≤ as-of | entries after as-of | per payer + currency | `created_at` |
| M-46 | Opening credit balance | `M-45` restricted to entries before period start | same | entries < period start | entries ≥ period start | per payer + currency | `created_at` |
| M-47 | Period movement | `Σ credits − Σ debits` within period | same | entries in period | outside period | per payer + currency | `created_at` |
| M-48 | Payers with credit | `COUNT(DISTINCT payer)` where M-45 > 0 | same | positive balance | zero/negative | per currency | as-of |
| M-49 | Credit ageing (available) | FIFO attribution of M-45 across credit entries; bucket by entry `created_at` | same + §7.6 algorithm | unconsumed credit | consumed credit | per currency | entry date |
| M-50 | Credit consumed per entry | `entry.amount − FIFO remaining` | same | credit entries | debit entries | per currency | entry date |
| M-51 | Over-consumption exception | any payer+currency where `M-45 < 0` | same | integrity exception | none | per currency | as-of |

No stored balance is read anywhere; M-45 is always derived from the append-only ledger.

---

## 5. Refunds

| ID | Metric | Formula | Source | Included | Excluded | Currency | Date basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| M-60 | Refunds requested | `Σ amount` where `status='requested'` | `refund_requests` | requested | all other statuses | `currency` | `requested_at` |
| M-61 | Refunds approved | `Σ amount` where `status='approved'` | same | approved | others | same | `approved_at` |
| M-62 | Refunds in flight | `Σ amount` where `status IN ('submitted','submitting')` | same | submitted + unresolved | requested/approved/confirmed | same | `submitted_at` |
| M-63 | Unresolved provider outcomes | `Σ amount` where `status='submitting'` OR latest event `provider_outcome='unknown'` | `refund_requests` + `refund_events` | unresolved only | resolved outcomes | same | event `created_at` |
| M-64 | Confirmed refunds | `Σ amount` where `status='confirmed'` | same | confirmed | everything else | same | `confirmed_at` |
| M-65 | Rejected refunds | `Σ amount` where `status='rejected'` | same | rejected | others | same | `rejected_at` |
| M-66 | Failed refunds | `Σ amount` where `status='failed'` | same | failed | others | same | `failed_at` |
| M-67 | Refund pipeline | `M-60 + M-61` | same | awaiting action | in flight/confirmed | same | request date |
| M-68 | Refund rate | `M-64 / M-01` for the same period, per currency (display only, 1 dp) | derived | confirmed vs gross receipts | pipeline | per currency | period |
| M-69 | Refund count by status | `COUNT(*)` grouped by `status` | same | all | n/a | n/a | status date |
| M-70 | Provider submission rate | count where `metadata.mode='provider'` / count where `submitted_at NOT NULL` | same | provider mode | manual mode | n/a | `submitted_at` |
| M-71 | Refund settlement unmatched | confirmed/submitted refunds with no settlement line | refunds + settlement lines | provider refund reference present | manual refunds | per currency | confirmation date |

M-64 is the only figure that may be called “refunded”. M-62 is “in flight”; M-63 is “unresolved”. No figure adds M-62/M-63 into M-64.

---

## 6. Credit notes

| ID | Metric | Formula | Source | Included | Excluded | Currency | Date basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| M-80 | Credit notes issued | `Σ cd.total` where `document_type='credit_note' AND status='issued'` | `commercial_documents` | issued | draft, voided | `cd.currency` | `issued_at` |
| M-81 | Credit-note credit created | `Σ amount` where `entry_type='credit_note_credit'` | `customer_credit_ledger_entries` | issued notes | voided notes | same | `created_at` |
| M-82 | Credit note applied | `issued − reversed − remaining` (M-80/M-84) | derived | issued notes | drafts | same | as-of |
| M-83 | Credit note reversed (voided) | `Σ amount` where `entry_type='reversal'` and `reverses_entry_id` = that note's credit entry | ledger | voided notes | active notes | same | `created_at` |
| M-84 | Credit note remaining | FIFO remaining of the note's `credit_note_credit` entry | ledger + §7.6 | unconsumed | consumed | same | as-of |
| M-85 | Applied to originating invoice | `Σ amount` of entries `operation_reference='cn:<number>:apply'` | ledger | auto-applied portion | pooled credit | same | `created_at` |
| M-86 | Credit notes voided | `Σ cd.total` where `status='voided'` | `commercial_documents` | voided | issued | same | `voided_at` |
| M-87 | Invoice remaining creditable | `invoice.total − Σ issued non-voided credit notes for that invoice` | `CreditNoteService::remainingCreditableMinor()` rule | issued notes | voided notes | invoice currency | as-of |
| M-88 | Credit-note attribution exception | any note where M-84 + M-83 + M-82 ≠ M-80 | derived | integrity exception | none | per currency | as-of |

M-85 is convention-derived and must be labelled as such in the UI; M-84/M-82 are the authoritative attribution figures.

---

## 7. Financial position

| ID | Metric | Formula | Notes |
| --- | --- | --- | --- |
| M-90 | Net cash retained | `A − Fc` | cash actually kept after confirmed refunds |
| M-91 | Cash applied to invoices | `X` (M-20) | not a cash outflow |
| M-92 | Cash held as customer credit | `D` | liability; excludes credit-note credit |
| M-93 | Cash unallocated | `U` (M-33) | liability/unapplied |
| M-94 | Cash reserved for in-flight refunds | `Fo` (M-62) | still held, committed |
| M-95 | Bridge check | `M-90 = M-91 + M-92 + M-93 + M-94` | must hold exactly per currency; a mismatch is an integrity exception |
| M-96 | Total credit liability | `M-45` | includes non-cash credit-note credit; never added to cash figures |
| M-97 | Net receivable | `Σ M-28` | invoice outstanding, per currency |
| M-98 | Refund-adjusted receipts | `A − Fc − Fo` | receipts not returned and not committed to be returned |

M-95 is the only permitted “grand reconciliation”. It is shown as a bridge with each term visible; the dashboard never shows a single “net financial position” number without the terms.

---

## 8. Reconciliation

| ID | Metric | Formula | Source | Notes |
| --- | --- | --- | --- | --- |
| M-100 | Settlement gross | `Σ gross_amount` | `settlement_lines` | provider-reported or imported |
| M-101 | Settlement fees | `Σ fee_amount` | same | never netted into receipts |
| M-102 | Settlement net | `Σ net_amount` | same | must equal M-100 − M-101 per line; mismatch = exception |
| M-103 | Matched amount | `Σ gross_amount` where `match_status IN ('matched_exact','matched_with_fee','timing_difference','manual_confirmed')` | same | tier-labelled |
| M-104 | Unmatched internal receipts | `Σ M-01` receipts with no settlement line after the configured window | receipts ⟕ lines | “missing_statement” |
| M-105 | Unmatched statement lines | `Σ gross_amount` where `match_status='missing_internal'` | `settlement_lines` | “missing_internal” |
| M-106 | Amount mismatches | `Σ ABS(variance_amount)` where `match_status='amount_mismatch'` | same | never auto-resolved |
| M-107 | Duplicates | count where `match_status IN ('duplicate_internal','duplicate_statement')` | same | resolved manually only |
| M-108 | Reconciliation coverage | `M-103 / M-01` for the same period and currency | derived | 1 dp, labelled with the tier |
| M-109 | Manual settlements | `Σ gross_amount` where tier = manual | same | requires evidence + audit |

If no settlement source is configured, M-100–M-109 render as “not configured”, never as zero.

---

## 9. Integrity exceptions (surfaced, never silently corrected)

| ID | Exception | Trigger |
| --- | --- | --- |
| E-01 | Negative receipt residual | M-36 |
| E-02 | Negative credit balance | M-51 |
| E-03 | Credit-note attribution mismatch | M-88 |
| E-04 | Position bridge mismatch | M-95 |
| E-05 | Unresolved provider outcome | M-63 |
| E-06 | Settlement fee arithmetic mismatch | M-102 |
| E-07 | Invoice over-settled | M-28 < 0 |
| E-08 | Succeeded receipt without `received_at` | M-12 |

Exceptions are read-only findings with a drill-down; correcting them is a separate, audited financial operation outside Stage 4D reporting.
