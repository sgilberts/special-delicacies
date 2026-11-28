# Stage 4D-PAYSTACK — Reconciliation Guide

For finance and support staff using **Financial Management → Reconciliation → Paystack settlements**.

This section lets you look at what Paystack reports and compare it with the receipts in the system.
It is a **read-only workbench**: nothing you do here changes a receipt, allocation, refund, credit
note or ledger entry, and it does not close a reconciliation.

---

## 1. Reading the connection card

| Badge | What it means | What to do |
| --- | --- | --- |
| **Not configured** | No Paystack key is configured for this deployment. | Ask an administrator to set `PAYSTACK_SECRET_KEY` and `PAYSTACK_SETTLEMENT_ENABLED=true`. No key is guessed or substituted. |
| **Configured — not yet verified** | A key exists but no provider call has been made recently. | Press **Test provider connection**. |
| **Connected** | The last provider call succeeded. | Continue. |
| **Authentication failed** | Paystack rejected the key (401/403). | Check whether the key belongs to the intended test or live account. |
| **Provider unavailable** | Paystack could not be reached or returned a server error. | Wait and retry; the call is retried once automatically. |
| **Rate limited by the provider** | Paystack returned 429. | Wait a minute, then retry. |

**Connected means the connection works — it does not mean anything is reconciled.** The connection
card never claims a financial result.

## 2. Reading a settlement row

- **Settlement** — the Paystack settlement id. Use it when talking to Paystack support.
- **Date** — the provider's settlement date.
- **Provider status** — shown exactly as Paystack reports it. `Processing` and `Pending` are **not**
  completed; only `Success` and `Failed` are final.
- **Gross / Fees / Net (provider)** — the provider's own figures, in GHS. A dash means Paystack did
  not return that field; the system never estimates it.
- **Txns** — the transaction count when the provider reports one.

All amounts are stored and compared in **minor units** (pesewas). `GHS 500.00` is 50 000 pesewas.

## 3. Inspecting a settlement

Press **Inspect**. The panel loads the settlement again plus every transaction in it, and tells you
whether the rows came from the provider's CSV export or from paginated retrieval. Both are the same
data; the export is simply fewer requests.

## 4. Generating the preview

Press **Generate read-only preview**. The system compares the provider's transactions with local
receipts and returns one row per transaction plus a row for every local receipt that the settlement
does not contain.

Three tiles summarise the state, and they are deliberately separate:

1. **Provider data retrieved** — how many transactions came back and when.
2. **Preview generated** — how many rows need attention. *Nothing was saved.*
3. **Financially reconciled: No — read-only preview** — no run was closed and no ledger entry written.

A successful retrieval is never presented as a successful reconciliation.

## 5. The classifications

| Classification | Meaning | Typical next step |
| --- | --- | --- |
| **Exact match** | The reference, currency, amount and local status all agree. | Nothing. |
| **Possible match** | Either the reference matches but the local receipt is not `succeeded`, or there is no shared reference and the amount + payment date match. | Check the local receipt by hand before relying on it. |
| **Amount mismatch** | Same reference, different amount. | Investigate the receipt; a partial capture or a manual edit is the usual cause. |
| **Currency mismatch** | The provider or the local record is not GHS, or they differ. | Nothing is converted. Escalate to finance; the row is excluded from the totals. |
| **Provider transaction without a local record** | Paystack reports money the system has no receipt for. | Check for a missed webhook or a receipt recorded under another reference. |
| **Local successful payment absent from the settlement** | A successful Paystack receipt dated on the settlement date is not in this batch. | Check the next settlement; if it never appears, escalate. |
| **Duplicate provider transaction** | The same reference or provider id appears twice in the batch. | Escalate; do not assume a double payment. |
| **Already included in another settlement** | The provider payload itself names a different settlement id. | Open that settlement. |
| **Refund or reversal requiring separate interpretation** | A refunded, reversed or negative transaction. | Handle through the existing refund/credit-note process. |
| **Unable to determine** | No identifier to match on. | Escalate with the settlement id. |

### Matching order

The system always tries the strongest key first:

1. local `provider_reference` = provider reference;
2. local `transaction_reference` = provider reference;
3. **only when no reference matches**: equal amount *and* the same payment date → *possible match*.

An amount-and-date match is never reported as exact.

## 6. Reading the totals

- **Provider total (GHS)** — provider amounts for this settlement in GHS. A duplicated reference is
  counted once; a transaction the provider attributes to another settlement is excluded.
- **Matched local total** — local amounts of the exact and possible matches.
- **Difference** — provider total minus matched local total. A non-zero difference is information,
  not an error: it is usually explained by the rows marked *needs attention*.

## 7. What this preview does not do

- It does not post provider fees anywhere. Fee fields are shown only as Paystack reports them
  (decision **D5** is still open).
- It does not approve, confirm or adjust a match. There is no second-approver threshold
  (decision **D12** is still open).
- It does not store the settlement or its lines. Nothing survives the page reload
  (decision **D13** is still open), so the preview is regenerated each time.
- It does not close a reconciliation run. Use the internal reconciliation section above for that.

## 8. Scope of the local comparison

Local receipts considered are: successful **Paystack** receipts in the settlement currency whose
`received_at` falls on the settlement date, plus any receipt whose reference the provider returned.
At most 500 local records are compared; if the list was truncated the preview says so — narrow the
period or inspect a smaller settlement.

Because the settlement date is the payout date rather than the original transaction date, a receipt
paid late on the previous day can legitimately appear as *local only*. Treat the classification as a
question to investigate, not a verdict.

## 9. When to escalate

Escalate to finance with the settlement id when you see: a duplicate provider transaction, an amount
mismatch you cannot explain from the receipt, a currency mismatch, or a successful local receipt that
is absent from two consecutive settlements.
