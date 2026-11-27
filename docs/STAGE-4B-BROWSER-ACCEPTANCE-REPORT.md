# Stage 4B — Browser Acceptance Report

**Document type:** Authenticated browser acceptance
**Date:** 2026-11-27
**Environment:** Sandbox compose stack — Laravel API (`:8000`), Vite frontend (`:3000`), MySQL 8.4, Reverb, queue worker — all healthy.
**Branch under test:** `stage-4b-remediation` (base: PR #1 head `6d29dd7`)

---

## 1. Accounts

- **Super-admin:** the repository's seeded super-admin development account.
- **Independent verifier:** a temporary staff account with the super-admin role, created for this run.
- **Low-privilege staff:** a temporary waiter account (`waiter-waitress` role) with no financial permissions, created for this run.
- **Customers:** two temporary, fully verified customers (A and B), created for this run.

All temporary accounts and financial records were deleted after the run (see §6). No
credentials are reproduced in this report, logs, commits or responses.

---

## 2. Super-admin acceptance (passed)

| Check | Result |
| --- | --- |
| Authentication | Signed in; landed on `/admin` |
| Financial navigation | Financial Management, Payment Allocations, Deposits & Credit present |
| Customer credit visibility | "Available balances" showed Acceptance Customer A **GHS 40.00**; ledger showed the designation by the verifier |
| Deposit listing | Staff-recorded deposit queue rendered the pending record with payer, method, external reference, evidence, recorder and status |
| Deposit recording | Recorded a GHS 25.00 bank-transfer deposit through the UI; confirmation message shown; record appeared as pending |
| Self-verification denial | The recorder's own "Verify receipt" was refused: "A deposit must be verified by a different staff member from the one who recorded it." |
| No unintended permission expansion | Super-admin retained only its normal broad role; no new grants were made |

Evidence:

- Super-admin dashboard — ![super-admin signed in](https://media.base44.com/images/public/preview_lens/6b0978b9b527bbbe7370a364/2f8ebe45c1a882d847172262ec10d763_preview-lens-268e6d07-9736-4f89-8fc9-afcdd01d7f56.png)
- Deposit recorded and self-verification refused — ![self-verify denied](https://media.base44.com/images/public/preview_lens/6b0978b9b527bbbe7370a364/837f4a4854f518e2bb7b7a2d01f3ad8c_preview-lens-c2859b76-d4b6-4129-8b56-0357bd54602c.png)

---

## 3. Independent deposit verification (passed)

Signed in as the verifier, opened Deposits & Credit, saw the pending `ACC4B-STAFF-1` record,
entered a decision reason and clicked **Verify receipt**. The UI confirmed the deposit was
"verified and eligible for credit designation". A subsequent API read showed the resulting
receipt (GHS 25.00) among eligible unallocated payments.

Evidence: ![independent verifier approved deposit](https://media.base44.com/images/public/preview_lens/6b0978b9b527bbbe7370a364/1cee3f8cf5188548404c501a453a58a2_preview-lens-d63c0ab9-02ee-4626-90a9-f9f0e8c20759.png)

---

## 4. Invoice-application (payment allocation) acceptance (passed)

Signed in as super-admin, opened Payment Allocations.

| Check | Result |
| --- | --- |
| Eligible receipt visibility | `ACC4B-PAY-A-1` (GHS 150.00) and `ACC4B-PAY-B-1` (GHS 80.00) listed |
| No double counting | `ACC4B-PAY-A-2` (GHS 40.00, fully designated as credit) was **not** listed as eligible |
| Available amount | Receipt panel showed "GHS 150.00 available"; invoice `ACC4B-INV-A-1` showed Balance GHS 100.00 |
| Over-application prevention | Allocating 150.00 to a 100.00 invoice was rejected by the server preview; no confirmation dialog opened |
| Valid allocation | Allocating 60.00 prepared a review dialog; **Confirm Allocation** succeeded |
| Balances after allocation | Receipt unallocated **GHS 90.00**; invoice balance **GHS 40.00** |
| Audit attribution | History showed the operation allocated by **Restaurant Super Admin** |

Evidence: ![allocation recorded and balances updated](https://media.base44.com/images/public/preview_lens/6b0978b9b527bbbe7370a364/a14a52b8c4cfee19196eff874be4bec6_preview-lens-40a564b0-b20c-4c1a-a91b-c02a4f9d50c1.png)

### 4.1 API confirmation of financial boundaries

| Check | Result |
| --- | --- |
| Payer isolation (Customer B receipt → Customer A invoice) | `409` "The payment payer does not match the invoice payer." |
| Credit over-application (GHS 5000) | `409` "Credit application exceeds the available credit balance." |
| Customer self-scope | Customer A own credit `200`, available balance **40.00** |
| Eligible payments after allocation | `ACC4B-PAY-A-1` 90.00, `ACC4B-PAY-B-1` 80.00, verified staff deposit 25.00; designated-credit receipt absent |

---

## 5. Unauthorized-operation denial (passed)

Signed in as the low-privilege waiter:

- Navigated to `/admin/customer-credit`; the app redirected to `/admin` and did not render the
  deposits/credit page (`waiterDenied = true`, path `/admin`).
- The waiter navigation tree contained **no** "Deposits & Credit", "Payment Allocations" or
  "Financial Management" entries.
- API-level checks returned `403` for balances, allocations and deposit creation.

Note: the waiter screenshot captured a logout confirmation overlay rather than the denial
view; the denial verdict rests on the path, navigation-tree and page-content assertions and on
the `403` API results.

---

## 6. Data hygiene

All records created for this acceptance run were deleted afterwards. The development database
was returned to its seeded baseline:

```
customers 0 · users 4 (seeded) · commercial_documents 0 · payment_transactions 0
staff_deposit_records 0 · customer_credit_ledger_entries 0 · payment_allocations 0
```

No pre-existing record was modified or deleted.

---

## 7. HMR and frontend loading

- `/@vite/client` was served `200` and loaded by the browser.
- No console errors and no WebSocket/HMR errors were recorded.
- The `vite-hmr` WebSocket **connected and received `{"type":"connected"}`** both directly
  (`ws://localhost:3000/`) and through the public preview proxy
  (`wss://3000-<public-host>/`), confirming HMR is wired through the proxy.
- The production build succeeded (`BUILD_EXIT=0`), so normal loading does not depend on HMR.

**Limitation:** a live hot-swap was not captured because the preview tooling reloads the page
on every source edit; the HMR verdict rests on the WebSocket connection evidence above rather
than on an observed in-place component update.

---

## 8. Limitations

- Native `window.confirm` confirmation steps in the customer-credit page (receipt
  classification, credit designation, credit application) were not driven through the UI;
  credit designation visibility was verified in the browser, and credit over-application was
  verified through the authenticated API.
- No live SMS or merchant gateway was exercised (see `STAGE-4B-FINAL-CLOSURE-REPORT.md` §8).
