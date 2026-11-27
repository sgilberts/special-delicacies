# Stage 4D — Owner Decision Register

**Status:** All decisions **PENDING OWNER APPROVAL**. No decision below has been implemented.
**Date:** 2026-11-27
**Rule:** No Stage 4D implementation begins until the decisions marked *Required before implementation* are answered.

---

## 1. Summary

| ID | Decision | Recommendation | Blocking? |
| --- | --- | --- | --- |
| D1 | Report permission granularity | Granular read permission per report | Yes |
| D2 | Default holders of reporting permissions | New optional finance roles; `admin` unchanged | Yes |
| D3 | What “refunded” means in reports | Confirmed only; pipeline shown separately | Yes |
| D4 | Settlement data source for v1 | Imported statement + manual, no automated claim | Yes |
| D5 | Gateway fee handling | Store gross, fee and net separately; finance-only edit | Yes |
| D6 | Credit-note remaining attribution | Derived FIFO (no schema change) | Yes |
| D7 | Default date basis per report | Receipt date for cash; request date for refunds; issue date for notes | Yes |
| D8 | Unallocated ageing definition | Days since receipt; 0–7/8–30/31–60/61–90/90+ | No |
| D9 | Gross receipts scope | All succeeded receipts, split by purpose; delivery fees shown as subset | Yes |
| D10 | Legacy/backfill treatment | Include with a flag; never rewrite history | No |
| D11 | Export formats | CSV in v1; Excel/PDF deferred | No |
| D12 | Reconciliation approval separation | Second approver above a threshold | No |
| D13 | Statement retention | Store normalised lines + raw row JSON; no file blobs | Yes |
| D14 | Multi-currency policy | Report per currency; refuse cross-currency totals | Yes |

---

## D1 — Report permission granularity

**Question:** Should each report have its own read permission, or should all reports share the existing `financials.reports.view`?

**Options**

1. Granular: one read permission per report (`financials.reports.refunds.view`, …).
2. Single: reuse `financials.reports.view` for every Stage 4D report.

**Recommendation: Option 1.** Refunds, credit notes and settlement data are more sensitive than the operational dashboard; granularity lets the owner grant a refund register without granting settlement access.

**Consequences of option 1:** eight new read permissions, a larger matrix, and explicit grants per role. **Of option 2:** faster rollout, but anyone who can see a dashboard can also see every refund and settlement detail.

**Status:** pending.

---

## D2 — Default holders of reporting permissions

**Question:** Who receives the new reporting permissions by default?

**Options**

1. New optional roles: `finance-manager`, `finance-officer`, `auditor`; `admin` and operational roles unchanged.
2. Grant reporting reads to the existing `admin` role.
3. No role changes; assign permissions to named users only.

**Recommendation: Option 1.** It matches the existing model (the `admin` role is deliberately seeded without any `financials.*` permission) and gives the owner a clean, auditable grant.

**Consequences of option 2:** every administrator gains access to refund, credit and settlement data — contrary to the existing least-privilege posture.

**Status:** pending.

---

## D3 — Meaning of “refunded” in reports

**Question:** Which refund states may be described as “refunded” in dashboards and exports?

**Options**

1. Only `confirmed`.
2. `submitted` + `confirmed`.
3. Every state, with a status column.

**Recommendation: Option 1**, with `submitted`, `submitting`, `requested` and `approved` shown in separate pipeline columns and an explicit unresolved-outcomes panel. Stage 4C never auto-confirms a provider outcome, so treating `submitted` as refunded would overstate returned cash.

**Status:** pending.

---

## D4 — Settlement data source for v1

**Question:** What is the reconciliation data source for the first release?

**Options**

1. **Imported statement + manual entry only.** No automated reconciliation claim. The module states its tier on every figure.
2. **Automated provider API** for any provider whose settlement endpoint the merchant account can access.
3. **Manual only** (no file import).

**Recommendation: Option 1.** The existing integrations expose no settlement, fee or payout data (finding G3), and provider settlement capability is unverified for this merchant account. Statement import plus manual entry delivers real reconciliation value without inventing data. Automated reconciliation can be added later per provider behind the same tier label.

**Consequences:** finance must export statements from each provider dashboard; matching is exact/fee/timing-based, not real-time. If the owner chooses option 2, each provider must be verified and documented before the label “automated” may appear.

**Status:** pending — **blocking for 4D-7**.

---

## D5 — Gateway fee handling

**Question:** How are gateway fees recorded and who may change them?

**Options**

1. Store `gross_amount`, `fee_amount`, `net_amount` per settlement line; fee is never netted into a receipt; edits require `financials.reconciliation.manage` and are audited.
2. Record only net settlement.
3. Derive fees from a configured percentage.

**Recommendation: Option 1.** Fees are a real cost but are not part of the receipt amount; storing them separately keeps gross receipts truthful and makes fee variance auditable. Option 3 would invent fees the provider did not report.

**Status:** pending.

---

## D6 — Credit-note remaining attribution

**Question:** How is “remaining credit-note amount” determined when credit is pooled per payer and consumed FIFO?

**Options**

1. **Derived FIFO** — reuse the 4C attribution algorithm in a batched read service, with parity tests.
2. Add an explicit attribution column/table written by a new process.
3. Report only the note's total and its auto-applied portion.

**Recommendation: Option 1.** It needs no schema change, matches the rule the 4C services already enforce, and is provable by the parity test (INV-6). Option 2 would create a second attribution record that can drift from the ledger.

**Status:** pending.

---

## D7 — Default date basis

**Question:** Which date drives each report by default?

**Recommendation**

| Report | Default | Alternative offered |
| --- | --- | --- |
| Receipts / dashboard | `received_at` | `reporting_date` |
| Allocation register | receipt date | allocation date |
| Unallocated / ageing | receipt date | — |
| Customer credit | ledger `created_at` | — |
| Refunds | `requested_at` | `confirmed_at` |
| Credit notes | `issued_at` | `created_at` |
| Reconciliation | settlement date | receipt date |

Every report displays its basis, and the export records it.

**Status:** pending.

---

## D8 — Unallocated ageing

**Question:** From which date and in which buckets is unallocated money aged?

**Recommendation:** from the receipt date (`received_at`), in buckets 0–7, 8–30, 31–60, 61–90, 90+ days, in `Africa/Accra`. Alternative considered: age from the last allocation date, which hides how long money has been held.

**Status:** pending.

---

## D9 — Gross receipts scope

**Question:** What belongs in “gross receipts”?

**Options**

1. All `succeeded` receipts, split by purpose (order / invoice) and by method, with delivery-fee receipts shown as a labelled subset.
2. Order receipts only.
3. Exclude delivery fees from gross receipts.

**Recommendation: Option 1.** Cash actually received is the honest gross figure; the split prevents double counting and lets management see restaurant sales separately from invoice settlement. Delivery fees are separate receipt rows (finding G11) and must be visible, not silently merged or dropped.

**Status:** pending.

---

## D10 — Legacy and backfill treatment

**Question:** How are legacy rows (backfilled `phase10a_backfill` receipts, succeeded rows without `received_at`, hard-coded GHS rows) reported?

**Recommendation:** include them, flag them (`legacy` badge, M-12 exception for missing `received_at`), and never rewrite history to normalise them. A one-time, separately approved data-repair exercise may follow if the owner wants it.

**Status:** pending.

---

## D11 — Export formats

**Question:** Which export formats ship in v1?

**Options**

1. CSV only, 366-day / 50,000-row caps.
2. CSV + Excel.
3. CSV + Excel + PDF.

**Recommendation: Option 1.** CSV is dependency-free, streams safely and matches the existing export. Excel/PDF add libraries and a rendering pipeline; they can follow once report layouts are stable.

**Status:** pending.

---

## D12 — Reconciliation approval separation

**Question:** Does a manual settlement or manual match need a second approver?

**Options**

1. No separation; `financials.reconciliation.manage` is sufficient, with audit.
2. Second approver required above a threshold (e.g. GHS 1,000), via `financials.reconciliation.approve`.
3. Second approver for every manual action.

**Recommendation: Option 2** if reconciliation is used for real settlement figures; option 1 if it is exploratory. This mirrors the separation-of-duties pattern already used for refunds and credit notes.

**Status:** pending.

---

## D13 — Statement retention

**Question:** What is stored from an imported statement?

**Options**

1. Normalised lines + the raw row as JSON; no uploaded file blob; checksum prevents duplicate import.
2. Store the uploaded file as well.
3. Store only matched lines.

**Recommendation: Option 1.** It preserves audit evidence and reproducibility without retaining payer-identifying documents or introducing file storage concerns. Option 3 would destroy the unmatched queue.

**Status:** pending.

---

## D14 — Multi-currency policy

**Question:** How is currency handled when only GHS exists today?

**Options**

1. Report strictly per currency; refuse to produce a cross-currency total; test with a synthetic second currency.
2. Assume GHS and add a currency filter later.

**Recommendation: Option 1.** Several code paths hard-code GHS (finding G10); making currency a partition now prevents a future silent mis-total and costs little.

**Status:** pending.

---

## 2. Decisions required before implementation

- **D1, D2, D3, D4, D5, D6, D7, D9, D13, D14** — required before 4D-1/4D-2 begin.
- **D4, D5, D12, D13** — required before 4D-7 (reconciliation) begins.
- **D8, D10, D11** — required before the relevant report ships, not before the kernel.

---

## 3. Approval record

| Decision | Owner answer | Date | Implemented in |
| --- | --- | --- | --- |
| D1 | — | — | — |
| D2 | — | — | — |
| D3 | — | — | — |
| D4 | — | — | — |
| D5 | — | — | — |
| D6 | — | — | — |
| D7 | — | — | — |
| D8 | — | — | — |
| D9 | — | — | — |
| D10 | — | — | — |
| D11 | — | — | — |
| D12 | — | — | — |
| D13 | — | — | — |
| D14 | — | — | — |
