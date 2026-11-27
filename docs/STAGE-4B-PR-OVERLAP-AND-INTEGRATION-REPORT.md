# Stage 4B — PR #1 / PR #2 Overlap and Integration Report

**Document type:** Cross-pull-request analysis
**Date:** 2026-11-27
**PR #1:** `audit-deposits-credit` @ `6d29dd7` → `main` (Stage 4B)
**PR #2:** `refunds-credit-notes` @ `ceb533b` → `main` (Stage 4C)
**Method:** Read-only diff comparison. No pull request was merged, retargeted or closed, and
no Stage 4C code was imported into the Stage 4B remediation branch.

---

## 1. Overlapping files

The two pull requests touch **nine** files in common. The three named in the closure scope are
analysed in depth; the remaining six are summarised in §5.

```
backend/app/Models/CustomerCreditLedgerEntry.php
backend/app/Services/CustomerCreditService.php
backend/app/Services/GatewayPaymentService.php
backend/routes/api.php
backend/tests/TestCase.php
docs/README.md
docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md
frontend/src/App.tsx
frontend/src/components/account/CustomerCreditPanel.tsx
```

---

## 2. `backend/app/Services/GatewayPaymentService.php`

| Version | Purpose |
| --- | --- |
| `main` | Order payment initialization/verification (Paystack, Hubtel, Korapay), webhook consumption, charge fetching. |
| PR #1 | Adds standalone **deposit** support: `availableGateways`, `depositGateway`, `gatewayForDeposit`, `initializeDepositCharge`, `verifyDepositCharge`, a deposit branch in `consumeWebhook`, `completeDeposit`, `payerName`, `payerReference`, `depositTransaction`, `minorAmountFor`, `majorAmountMatches`, `emptyCharge`; refactors shared fetch/verify helpers. |
| PR #2 | Adds **refund** support: `supportsRefunds`, `refundGatewayFor`, `initiateRefund`, `findRefundByReference`, `verifyRefund`. Diff is **purely additive** (no deletions). |

- **Overlap:** same class and shared helpers (`hasCredentials`, gateway resolution,
  `fetchCharge`). No method-name collision between the two additions.
- **Correct final behavior:** the class supports order payments, deposits and refunds; the
  shared credential/gateway helpers serve all three.
- **Integration strategy:** merge PR #1 first, then rebase PR #2 onto it. Keep both method
  blocks; confirm PR #2's refund resolution uses the shared `hasCredentials`/gateway helpers
  and that the deposit `consumeWebhook` branch and refund paths do not both claim the same
  provider event. Run both the Stage 4B and Stage 4C suites. **No risky change made now.**

---

## 3. `backend/routes/api.php`

| Version | Purpose |
| --- | --- |
| `main` | Base public, customer and staff routes. |
| PR #1 | Customer verification routes (`customer/verification/*`), staff verification routes (`auth/verification/*`), the both-contact gate group, and deposit routes (`customer/deposits*`, `admin/deposits*`). |
| PR #2 | Refund routes (`admin/financials/refunds*`), credit-note routes (`admin/financials/credit-notes*`), credit-reversal routes (`admin/financials/credit-reversals*`). Diff is **purely additive** (no deletions). |

- **Overlap:** both insert into the `auth:sanctum` + `EnsureStaffAccount` group; different
  path prefixes, so semantically compatible, but a textual conflict is possible at the same
  insertion anchor.
- **Correct final behavior:** both route sets present, each behind its intended granular
  permission, with no duplicate path and all controllers imported.
- **Integration strategy:** merge PR #1 then rebase PR #2; keep both blocks; run
  `php artisan route:list --path=api` to confirm no duplicate/missing route; run both suites.

---

## 4. `backend/tests/TestCase.php` (mandatory)

| Version | Guard |
| --- | --- |
| `main` | No guard. |
| PR #1 | Weak: denylist of only `special_delicacies` / `special_delicacies_db`, accepts any `*_test` name. |
| PR #2 | Corrected: forces the isolated environment, denies the protected list, requires a disposable `special_delicacies_*_test` pattern, validates on boot. |

- **Correct final behavior:** the corrected guard.
- **Integration:** **already reconciled** into the Stage 4B remediation branch and hardened
  with a separate, unit-tested guard class plus regression tests. See
  `STAGE-4B-DATABASE-GUARD-REMEDIATION.md`. This is the only mandatory cross-PR correction.

---

## 5. Additional overlaps

### 5.1 `CustomerCreditService.php`

- PR #1 adds a receipt-intent `operation_reference` uniqueness pre-check in `classifyReceipt`.
- PR #2 changes the derived-balance query to subtract reversal entries, introduces
  `CREDIT_TYPES`/`DEBIT_TYPES`, and adds `currentBalanceMinor()`.
- **Compatibility:** different methods, but PR #2's balance query references reversal columns
  and entry types that exist only in PR #2's migration. On the Stage 4B branch the PR #1
  version is correct and must not be replaced by PR #2's until PR #2's migration is present.
- **Integration:** take both; order the merge so PR #2's migration exists before its balance
  query runs; keep PR #1's uniqueness pre-check.

### 5.2 `CustomerCreditLedgerEntry.php`

- PR #1 adds a `booted()` immutability guard (`updating`/`deleting` throw).
- PR #2 adds fillable fields (`reverses_entry_id`, `authorized_by_*`), new entry types
  (`credit_note_credit`, `reversal`), `CREDIT_TYPES`/`DEBIT_TYPES`, casts, and its own
  immutability guard.
- **Hazard:** both add a `booted()` method — a naive merge can produce a duplicate method and
  a PHP fatal error. PR #2's reversal entries must be creatable while remaining immutable
  after creation.
- **Integration:** merge the two `booted()` implementations into one; preserve append-only
  semantics; run the credit tests.

### 5.3 Frontend and docs

- `frontend/src/App.tsx`: PR #1 adds the customer deposits route; PR #2 adds refund/credit-note
  routes. Additive; keep both.
- `frontend/src/components/account/CustomerCreditPanel.tsx`: both modify; merge carefully and
  re-run `tsc` and the production build.
- `docs/README.md`: both add index entries; keep both.
- `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md`: PR #1 carries the design
  document; resolve to the latest Stage 4C design (documentation only, no code impact).

---

## 6. Cross-stage functional note

PR #1 creates verified invoice receipts. PR #2's refund eligibility selects any
`payment_transactions` row with `status = 'succeeded'` and non-null `verified_at`/`received_at`,
so once PR #1 lands, deposits become refund-eligible automatically. This is not a Stage 4B
blocker, but it is a deliberate Stage 4C acceptance point.

---

## 7. Conclusion

- `TestCase`: **reconciled** (mandatory) on the Stage 4B remediation branch.
- `GatewayPaymentService` and `routes/api.php`: **additive and compatible**; a safe
  merge-then-rebase strategy is documented, and no Stage 4C code was imported into Stage 4B.
- Additional overlaps (notably the `CustomerCreditLedgerEntry` `booted()` duplication) are
  documented for the merge.
- No pull request was merged, and no risky change was made in this pass.
