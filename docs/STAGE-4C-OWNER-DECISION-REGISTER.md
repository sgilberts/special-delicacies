# Stage 4C — Owner Decision Register (D1–D13)

**Purpose:** a single, accurate register of the thirteen financial-policy decisions in Stage 4C, separating
what the code **implements** from what the **owner must approve**.
**Companion documents:** `STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md` (original recommendations),
`STAGE-4C-IMPLEMENTATION-AND-ACCEPTANCE-REPORT.md` (evidence and residual risk).
**Register date:** 2026-11-27

> Authorising the *implementation* of Stage 4C is not approval of these thirteen business-policy decisions.
> Every row below still requires an explicit owner decision. Until then, the operation is either restricted to
> super-admin, bounded by a conservative default, or disabled outright.

---

## 1. How to read this register

| Column | Meaning |
|---|---|
| **Recommended default** | The exact recommendation recorded in the design (§12), quoted. |
| **Implemented behaviour** | What the shipped code actually does today. |
| **Configuration key** | Where the behaviour is changed: an env/config key, or a permission row in the database. |
| **Enabled / restricted** | The current state without any owner change. |

The policy layer is `backend/config/stage4c.php`, read only through `App\Services\Stage4cPolicy`. Permission
grants live in the database and are seeded by the Stage 4C migration; they are **not** environment toggles.

---

## 2. Summary register

| # | Original decision | Recommended default (design) | Implemented behaviour | Configuration key | Owner approval | Current state |
|---|---|---|---|---|---|---|
| D1 | Who can initiate a refund | `financials.refunds.initiate`, super-admin until assigned | Permission enforced on the create route; super-admin holds it by default | permission `financials.refunds.initiate` | **Required** (assign roles) | **Restricted** — super-admin only |
| D2 | Who can approve a refund | `financials.refunds.approve`, super-admin | Permission gates approve, reject, submit, confirm, fail and reconcile | permission `financials.refunds.approve` | **Required** | **Restricted** — super-admin only |
| D3 | Must initiator and approver differ | **Yes — different people** | Enforced on approve and confirm; a matching user is rejected with a clear message | `STAGE4C_SEPARATION_OF_DUTIES` (`stage4c.separation_of_duties`, default `true`) | **Required** (confirm) | **Enabled** (separation on) |
| D4 | Are partial refunds allowed | **Yes, up to uncommitted funds** | Amount must be ≤ the receipt's refundable balance; partial allowed | `STAGE4C_PARTIAL_REFUNDS` (`stage4c.refunds.partial_allowed`, default `true`) | **Required** | **Enabled**, bounded by refundable funds |
| D5 | Refunds after allocation | **No — reverse allocation first** | Refundable balance subtracts active allocations and net credit designations | no toggle (invariant) | **Required** | **Enforced** — allocated funds not refundable |
| D6 | Refund to a different payment method | **No — same method/payer** | No destination input exists; the refund copies the receipt's payer/currency and the provider call uses the original transaction reference | `STAGE4C_REFUND_DIFFERENT_METHOD` (`stage4c.refunds.allow_different_method`, default `false`, informational) | **Required** | **Enforced structurally** |
| D7 | Which documents qualify for credit notes | **Issued, non-voided invoices only** | Eligibility asserted in the service before a draft is created | no toggle (invariant) | **Required** | **Enforced** |
| D8 | Who issues/approves credit notes | Creator ≠ issuer; super-admin | `create` and `approve` are distinct permissions; the creator cannot issue | `STAGE4C_CREDIT_NOTE_SEPARATION` (`stage4c.credit_notes.require_independent_approval`, default `true`) | **Required** | **Enabled**, super-admin only |
| D9 | Partial credit notes allowed | **Yes, up to remaining creditable** | Amount capped by the invoice's remaining creditable balance under lock | `STAGE4C_PARTIAL_CREDIT_NOTES` (`stage4c.credit_notes.partial_allowed`, default `true`) | **Required** | **Enabled**, bounded |
| D10 | Credit reversal needs independent approval | **Yes — different authorizer** | Independent authorizer enforced; **credit-note linkage sub-decision is open** (see §3) | `STAGE4C_REVERSAL_SEPARATION` (`stage4c.credit_reversals.require_independent_approval`, default `true`) | **Required — two parts** | **Partially restricted** (see §3) |
| D11 | Fees/discounts/taxes in refunds and credit notes | **Refund the receipt amount; credit note mirrors invoice line totals** | Refund uses the receipt amount; credit notes snapshot their own line totals, discount and tax | no toggle (invariant) | **Required** | **Enforced** |
| D12 | Provider unavailable during refund | **Stay pending, record error, retry idempotently; never auto-confirm** | Unknown outcome is held in `submitting`; provider idempotency reference sent; reconciliation required; never auto-confirmed | `STAGE4C_PROVIDER_REFUNDS_ENABLED` (`stage4c.refunds.provider_submission_enabled`, default `false`) | **Required** (to enable provider refunds) | **Disabled** — manual payout path only |
| D12a | Mark fully refunded transaction `refunded` | **Keep `succeeded`; report refunds from `refund_requests`** | Receipt status is never rewritten; refunds are reported from `refund_requests` | `STAGE4C_MARK_REFUNDED` (`stage4c.refunds.mark_transaction_refunded`, default `false`) | **Required** | **Disabled** (receipt stays `succeeded`) |
| D13 | Already-designated/applied credit during reversal | **Reverse only unconsumed credit (FIFO attribution)** | Applications consume credit entries chronologically; the remainder is reversible; over-reversal rejected | `stage4c.credit_reversals.consumption_method` = `fifo` (fixed) | **Required** | **Enforced** — consumed credit rejected |

**Related configuration not covered by a D-number:** `STAGE4C_CREDIT_NOTE_AUTO_APPLY`
(`stage4c.credit_notes.auto_apply`, default `true`) applies an issued credit note against the originating
invoice's outstanding balance, leaving any excess as customer credit. It is an implementation default from
design §6.4, not an owner decision; the owner may still choose to require manual application.

---

## 3. D10 in detail — the open decision

### 3.1 What D10 governs

D10 covers two connected questions:

1. **Authorisation.** Does removing customer credit (a reversal) require an authorizer who is a *different*
   person from the initiator? Design recommendation: **yes**.
2. **Credit-note linkage.** Design §7.2 adds: *“Reversal of credit-note-derived credit additionally requires
   the credit note to be voided or is recorded as a linked adjustment (decision D10).”* The design does **not**
   pick between those two mechanisms, and the implementation does neither today.

### 3.2 Recommended default

- **Authorisation: independent authorizer.** This is implemented and on by default
  (`stage4c.credit_reversals.require_independent_approval = true`, combined with the global separation switch).
- **Linkage: the design offers two acceptable mechanisms and leaves the choice to the owner.** No
  recommendation is being made on your behalf.

### 3.3 The three policy choices

| Choice | What happens when credit that came from a credit note is reversed | Accounting consequence |
|---|---|---|
| **A. Void-first** | A reversal of credit-note credit is only permitted after the credit note itself is voided. The note is voided first (which requires its credit to be entirely unused), then the credit returns to the receipt. | Cleanest document trail: the note is explicitly voided, and the credit entry and its reversal sit under a voided note. Requires voiding before reversal, so a partially consumed note can never have its unused part returned. |
| **B. Linked adjustment** | The reversal is allowed, and a linked adjustment is recorded against the credit note. The note stays `issued`, and its remaining credit is reduced by the reversal amount. | The note remains a live document; reporting must show `note total − linked adjustments = remaining credit`. The note can no longer be voided for its full amount, so the adjustment is the permanent record. |
| **C. No linkage (current behaviour)** | The reversal reduces the customer-credit ledger only. The note stays `issued` at its full amount, and the reversal is traceable only through the ledger entry's `reverses_entry_id`. | Simplest, but reporting is inconsistent: the note and the ledger disagree, and the note becomes unvoidable (void requires the full credit to be unused). No double-spend is possible because the invoice's remaining creditable amount stays conservatively reduced. |

### 3.4 Consequences of each choice, side by side

| Dimension | A. Void-first | B. Linked adjustment | C. No linkage (today) |
|---|---|---|---|
| **Invoice balance** | Unchanged by the reversal itself; the note is voided, so no credit remains applied | Unchanged; the note remains issued, and only the adjustment is recorded | Unchanged; the note remains issued at full value |
| **Customer credit** | Reduced by the reversed amount (the credit is returned to the receipt) | Reduced by the reversed amount | Reduced by the reversed amount |
| **Refund eligibility** | The receipt's uncommitted funds increase by the reversed amount, so the amount becomes refundable | Same | Same |
| **Partially applied note** | Cannot be reversed while any of its credit is consumed (void requires full unused credit) | Can be reversed for the unconsumed remainder; the adjustment records the rest | Can be reversed for the unconsumed remainder, with no document-level trace |
| **Voiding the note afterwards** | Not applicable — it was voided first | Not possible for the full amount; the adjustment is the record | Not possible; `void` fails because the credit is no longer fully unused |
| **Audit history** | `commercial_document_histories` void entry + reversal ledger entry | Reversal ledger entry + a linked adjustment record on the note | Reversal ledger entry only |
| **Code change needed** | Yes — block reversal of credit-note credit unless the note is voided | Yes — persist the adjustment and surface it in the note's history | No — current behaviour |

### 3.5 What stays restricted until D10 is approved

- **Authorisation is already enforced:** a reversal cannot be approved by the person who requested it, and the
  authorizer is snapshotted on the ledger entry.
- **Credit-note-derived reversals are restricted to super-admin** and require independent approval. They are
  allowed today under choice **C** (no linkage), which is why the reporting inconsistency is documented as an
  open finding rather than treated as settled behaviour.
- **Voiding a credit note whose credit was partially reversed fails** with a clear conflict message. This is a
  consequence of choice C and will change if you choose A or B.
- **No new provider or automatic operation is enabled by D10.** Whatever you choose, provider refunds remain
  disabled (D12) until separately approved.

**Owner action for D10:** approve the authorisation rule (recommended: yes), and choose A, B or C for the
credit-note linkage. Choosing A or B requires a small, scoped implementation change; choosing C means accepting
the documented reporting inconsistency.

---

## 4. What remains disabled or restricted overall

| Area | State without an owner change |
|---|---|
| Provider-side refund execution | **Disabled.** `STAGE4C_PROVIDER_REFUNDS_ENABLED` is unset, so refunds use the manual evidence path only. |
| Receipt status rewrite on full refund | **Disabled.** Receipts stay `succeeded` (D12a). |
| Refund / credit-note / reversal mutations | **Super-admin only.** Finance roles must be granted the mutation permissions. |
| Partial refunds / partial credit notes | Enabled but bounded by the refundable and remaining-creditable amounts. |
| Separation of duties | Enabled for refund approval, credit-note issuance and reversal approval. |
| Credit-note auto-application | Enabled (implementation default; not a D-number). |

Changing any of these is an owner decision, not an implementation detail. Every change is a configuration or
permission change — no code edit is required except for the D10 linkage choice.
