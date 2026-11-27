# Stage 4C — Refunds, Credit Notes and Customer Credit Reversals: Implementation and Acceptance Report

**Status:** Implementation complete, automated and browser acceptance performed.
**Classification:** **CONDITIONALLY READY** — core workflows are implemented and tested; provider-side refund execution and the D1–D13 policy sign-off remain owner dependencies, and the affected operations are safely restricted.
**Report date:** 2026-11-27
**Design reference:** `docs/STAGE-4C-REFUNDS-CREDIT-NOTES-AND-REVERSALS-DESIGN.md`
**Initial discovery reference:** `docs/STAGE-4C-INITIAL-REPORT.md`

---

## 1. Repository and branch state

| Item | Verified value |
|---|---|
| Repository | `https://github.com/sgilberts/special-delicacies.git` |
| Session working branch | `refunds-credit-notes` |
| Base HEAD at start | `95d651721aa17cbfaa52cc27209c4858d0970518` (`Add Reverb broadcasting, local CORS and Vite build updates, and payment/delivery refinements`) |
| Working tree at start | Clean |
| PR #1 | **OPEN**, `MERGEABLE`, head `audit-deposits-credit` (`6d29dd7`), base `main` |
| Stage 4C design branch | `stage-4c-refunds-credit-notes` (`e4f83a5`), stacked on the PR #1 lineage |
| Target database engine | MySQL **8.4.13** (verified in the running `mysql:8.4` container) |
| Development database | `special_delicacies_db` |
| Disposable MySQL test database | `special_delicacies_stage4c_test` |

**Branch reconciliation note.** The execution brief names `stage-4c-refunds-credit-notes`; this session was created on the platform branch `refunds-credit-notes`, which is based on the current `main`. `main` already contains the Stage 4A payment-allocation foundation (`2026_11_25_000010_create_payment_allocation_tables.php`) and the Stage 4B credit/deposit foundation (`2026_11_26_000003_create_stage4b_credit_and_deposit_tables.php`), so Stage 4C was implemented on the provided branch without rewriting history or merging PR #1. The design documents were copied from `stage-4c-refunds-credit-notes` into `docs/` as historical records; their content was not modified.

**Commits.** The platform commits and pushes this branch at the end of the turn. The exact implementation commit hash is therefore assigned by the platform after this report is written; the base commit is `95d6517`. No commit, merge, force-push, reset or branch switch was performed manually.

---

## 2. Stage 4B dependency verification

Verified present on the working branch:

- `payment_transactions` (canonical receipt ledger, unique `transaction_reference`, `amount decimal(12,2)`, `currency char(3)`).
- `payment_allocation_operations`, `payment_allocations`, `payment_allocation_reversals`.
- `payment_receipt_intents` (deposit/advance classification).
- `customer_credit_ledger_entries` (append-only designation/application ledger with `credit_ledger_single_payer` and `credit_ledger_entry_shape` CHECK constraints on MySQL).
- `commercial_documents`, `commercial_document_lines`, `commercial_document_histories`.
- `staff_deposit_records` separation-of-duties pattern.
- `financials.*` permission slugs and `permission:` route middleware.

Stage 4A/4B regression on MySQL 8.4.13: `PaymentAllocationTest`, `Stage4bCreditAndSecurityTest`, `CommercialDocumentsTest`, `FinancialManagementTest` — **37 tests, 231 assertions, 0 failures**.

---

## 3. Implemented database changes (additive only)

All migrations are additive; no existing financial row is updated or deleted and no existing CHECK constraint is weakened.

| Migration | Change |
|---|---|
| `2026_11_27_000001_create_refund_requests_and_events_tables` | New `refund_requests` (lifecycle, payer, amount, currency, actor snapshots, provider/verification references, reasons, metadata) and append-only `refund_events`. Unique `refund_reference`; FKs restrict on the payment, payer, request and actor. |
| `2026_11_27_000002_add_credit_note_link_to_commercial_documents` | Nullable self-FK `related_document_id` (restrict on delete) + `(related_document_id, document_type)` index. Existing rows untouched. |
| `2026_11_27_000003_create_credit_reversal_requests_table` | New `credit_reversal_requests` to support initiate → independent approval without mutating the append-only ledger. Unique `operation_reference`. |
| `2026_11_27_000004_extend_customer_credit_ledger_for_reversals` | Adds `reverses_entry_id` self-FK, `authorized_by_user_id`, `authorized_by_name_snapshot`, `authorized_at`; replaces `credit_ledger_entry_shape` on MySQL with a shape that additionally allows `credit_note_credit` and `reversal` (positive amount, `reverses_entry_id` required). `credit_ledger_single_payer` unchanged. |
| `2026_11_27_000005_add_stage4c_financial_permissions` | Nine `financials.*` permission rows; view permissions granted to roles that already hold the matching area view permission; mutation permissions granted to `super-admin` only. |

**Rollback considerations.** Down migrations drop only the new columns/tables and restore the original `credit_ledger_entry_shape`. All new columns are nullable and all new tables are independent, so rollback does not silently destroy pre-existing financial data. Data created after the migration (refunds, reversals, credit notes) is removed with the new tables, which is expected for a schema rollback and must be backed up first in production.

**Append-only enforcement.** `CustomerCreditLedgerEntry` now throws on `updating`/`deleting`. Corrections are new compensating entries only.

---

## 4. Backend architecture

New services (domain logic lives here, not in controllers):

- `App\Services\Stage4cPolicy` — typed access to `config/stage4c.php`, mapping D1–D13 to configurable policy.
- `App\Services\RefundService` — refund lifecycle, eligibility/refundable calculation, idempotency, separation of duties, provider submission/verification, manual confirmation, audit events.
- `App\Services\CreditNoteService` — credit-note creation, issuance, auto-application, voiding, remaining-creditable calculation, commercial-document history.
- `App\Services\CustomerCreditReversalService` — eligible-credit listing, FIFO consumed-credit attribution, initiate/approve/reject, append-only reversal posting.
- `App\Services\GatewayPaymentService` — added `supportsRefunds`, `refundGatewayFor`, `initiateRefund`, `verifyRefund` (Paystack only; no new gateway introduced).
- `App\Services\CustomerCreditService` — balance derivation extended to include `credit_note_credit` and `reversal`; ledger views expose reversal/authorizer metadata.

New models: `RefundRequest`, `RefundEvent`, `CreditReversalRequest`. Updated: `CommercialDocument` (`TYPE_CREDIT_NOTE`, `relatedDocument`, `creditNotes`), `CustomerCreditLedgerEntry` (new types, reversal/authorizer fields, append-only guard).

**Configuration.** `backend/config/stage4c.php` holds the conservative defaults: separation of duties on; partial refunds/credit notes allowed; provider refund submission **off** (`STAGE4C_PROVIDER_REFUNDS_ENABLED=false`); receipt status not rewritten (`STAGE4C_MARK_REFUNDED=false`); credit-note auto-apply on; independent approval required for credit notes and reversals.

---

## 5. API endpoints

All endpoints are under the existing `auth:sanctum` + `EnsureStaffAccount` group and are protected by `permission:` middleware.

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/admin/financials/refunds` | `financials.refunds.view/initiate/approve` |
| GET | `/api/v1/admin/financials/refunds/eligible-payments` | view/initiate |
| POST | `/api/v1/admin/financials/refunds` | `financials.refunds.initiate` |
| GET | `/api/v1/admin/financials/refunds/{refund}` | view/initiate/approve |
| POST | `/api/v1/admin/financials/refunds/{refund}/approve` | `financials.refunds.approve` |
| POST | `/api/v1/admin/financials/refunds/{refund}/reject` | approve |
| POST | `/api/v1/admin/financials/refunds/{refund}/submit` | approve |
| POST | `/api/v1/admin/financials/refunds/{refund}/confirm` | approve |
| POST | `/api/v1/admin/financials/refunds/{refund}/fail` | approve |
| GET | `/api/v1/admin/financials/credit-notes` | `financials.credit_notes.view/create/approve` |
| GET | `/api/v1/admin/financials/credit-notes/eligible-invoices` | view/create |
| POST | `/api/v1/admin/financials/credit-notes` | `financials.credit_notes.create` |
| GET | `/api/v1/admin/financials/credit-notes/{creditNote}` | view/create/approve |
| POST | `/api/v1/admin/financials/credit-notes/{creditNote}/issue` | `financials.credit_notes.approve` |
| POST | `/api/v1/admin/financials/credit-notes/{creditNote}/void` | approve |
| GET | `/api/v1/admin/financials/credit-reversals` | `financials.credit_reversals.view/initiate/approve` |
| GET | `/api/v1/admin/financials/credit-reversals/eligible` | view/initiate |
| GET | `/api/v1/admin/financials/credit-reversals/{ledgerEntry}/preview` | view/initiate/approve |
| POST | `/api/v1/admin/financials/credit-reversals` | `financials.credit_reversals.initiate` |
| POST | `/api/v1/admin/financials/credit-reversals/{creditReversal}/approve` | `financials.credit_reversals.approve` |
| POST | `/api/v1/admin/financials/credit-reversals/{creditReversal}/reject` | approve |

Responses follow the existing `{success, data, meta}` convention; domain conflicts return HTTP 409 with a human-readable message; validation errors return 422; unauthorised requests return 403.

---

## 6. Frontend interfaces

New admin pages, added to the existing navigation under **Finance**:

- **Refunds** (`/admin/refunds`) — eligible-payment list, request form, refund table with status, detail with audit events, approve/reject/submit/confirm/mark-failed actions with confirmation and success/error feedback.
- **Credit Notes** (`/admin/credit-notes`) — eligible-invoice list, draft creation, issue/void actions, detail with line snapshots, applied-to-invoice amount, remaining reversible credit and document history.
- **Credit Reversals** (`/admin/credit-reversals`) — eligible credit entries with remaining reversible amount, initiation form, request list with approve/reject.

The customer credit panel now labels `credit_note_credit` and `reversal` ledger entries correctly. All pages use the existing global color tokens and responsive table/card patterns; light, dark and system themes are inherited from the existing shell.

---

## 7. Workflows implemented

### Refunds
`requested → approved → submitted → confirmed`, with `rejected` and `failed` terminal states.

- Refundable = receipt amount − active allocations − credit designations − all live refunds (requested/approved/submitted/confirmed).
- Allocated or designated funds must be released (allocation reversal or credit reversal) before they can be refunded.
- Unique `refund_reference` provides idempotency; identical replay returns the existing request, a different payload is rejected.
- Initiator and approver must differ; the confirmer must differ from the initiator.
- Provider submission is attempted only when `stage4c.refunds.provider_submission_enabled` is true and the provider is Paystack. On provider failure the refund stays `approved`, the error is recorded as a `provider_error` event, and the operation can be retried.
- Confirmation requires either server-side provider verification of the exact amount and currency, or manual evidence plus an independent reference. A browser return is never accepted as proof.
- `payment_transactions.status` is left as `succeeded` by default (D12a).

### Credit notes
- Created as `draft` against an issued, non-voided invoice of the same payer and currency.
- Total cannot exceed the invoice total minus prior issued credit notes.
- Issued by a different staff member (`created_by ≠ issued_by`).
- Issuing writes an append-only `credit_note_credit` ledger entry and, when auto-apply is enabled, a single `application` entry against the originating invoice for the outstanding portion; any excess remains available customer credit.
- The original invoice total, status and lines are never mutated; history is recorded in `commercial_document_histories`.
- Voiding is allowed only while the credit-note credit is unused; voiding posts a compensating reversal entry.

### Credit reversals
- Only `designation` and `credit_note_credit` entries are reversible.
- Applications are attributed FIFO across each entry's un-reversed amount, so consumed credit cannot be reversed.
- Remaining reversible = entry amount − reversed amount − applications consumed from it.
- Initiation creates a pending request; approval by a different staff member posts a new `reversal` ledger entry referencing the original. The original entry is never updated or deleted.
- Duplicate `operation_reference` is idempotent; over-reversal and concurrent over-commit are rejected under a payer lock.

---

## 8. RBAC and audit controls

Nine new permissions, following `financials.<area>.<action>`:

`financials.refunds.view|initiate|approve`, `financials.credit_notes.view|create|approve`, `financials.credit_reversals.view|initiate|approve`.

- View permissions are granted to roles that already hold the matching area view permission; mutation permissions are granted to `super-admin` only by default.
- Every endpoint is backend-authorised; customer tokens are rejected by the staff middleware.
- Initiator/approver separation is enforced in the services, not the UI.
- Every transition writes a domain history record (`refund_events`, `commercial_document_histories`, ledger entries) and an `admin_audit_logs` entry via `AuditService`.
- Provider credentials and raw provider responses are never exposed in API responses.

---

## 9. D1–D13 approval register

| # | Decision | Recommended default (implemented) | Implementation status | Owner approval still required | Features affected if unresolved |
|---|---|---|---|---|---|
| D1 | Who can initiate a refund | `financials.refunds.initiate`, super-admin | Implemented, configurable via role grants | **Yes** (assign to finance roles) | Refund initiation |
| D2 | Who can approve a refund | `financials.refunds.approve`, super-admin | Implemented | **Yes** | Refund approval/confirmation |
| D3 | Initiator ≠ approver | Yes | Enforced (`separation_of_duties`) | **Yes** (confirm) | Refund approval |
| D4 | Partial refunds | Yes, up to uncommitted funds | Implemented (`partial_allowed`) | **Yes** | Refund amounts |
| D5 | Refunds after allocation | No — reverse allocation first | Enforced | **Yes** | Refund eligibility |
| D6 | Refund to different method/payer | No — same payer/currency | Enforced; `allow_different_method=false` | **Yes** | Refund payout |
| D7 | Documents eligible for credit notes | Issued, non-voided invoices only | Enforced | **Yes** | Credit-note creation |
| D8 | Who issues/approves credit notes | Creator ≠ issuer; super-admin | Enforced | **Yes** | Credit-note issuance |
| D9 | Partial credit notes | Yes, up to remaining creditable | Implemented (`partial_allowed`) | **Yes** | Credit-note amounts |
| D10 | Reversal needs independent approval | Yes — different authorizer | Enforced | **Yes** | Credit reversal |
| D11 | Fees/discounts/taxes | Refund receipt amount; credit note mirrors invoice line totals | Implemented (line snapshots) | **Yes** | Refund/credit-note amounts |
| D12 | Provider unavailable | Stay pending, record error, retry, never auto-confirm | Implemented | **Yes** (enable provider submission) | Provider refunds |
| D12a | Mark fully refunded transaction `refunded` | No — keep `succeeded` | Implemented (`mark_transaction_refunded=false`) | **Yes** | Reporting |
| D13 | Already-designated/applied credit | Reverse only unconsumed credit (FIFO) | Implemented | **Yes** | Credit reversal |

No irreversible financial policy was silently activated. The provider-refund path is disabled by default; manual refunds require independent evidence and confirmation.

---

## 10. Automated test evidence

### SQLite (in-memory) — fast feedback

Command (inside the `api` container):

```
php vendor/bin/phpunit
```

- **405 tests, 1963 assertions, 2 failures.**
- The two failures are environment-driven URL assertions (`LocalFrontendCorsTest`, `Phase5TableManagementTest::test_existing_qr_urls_are_migrated_to_query_token_format`) that hardcode `https://special-delicacies.local` while the container sets the preview URL. Re-running them with `APP_URL`, `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS=https://special-delicacies.local` gives **2 tests, 8 assertions, 0 failures**. They are not Stage 4C regressions.

Stage 4C suite only: **29 tests, 122 assertions, 0 failures** (`Stage4cCreditReversalTest`, `Stage4cCreditNoteTest`, `Stage4cRefundTest`, `Stage4cPermissionTest`).

### MySQL 8.4.13 — disposable database `special_delicacies_stage4c_test`

```
BASE44_MYSQL_TEST_DATABASE=special_delicacies_stage4c_test php vendor/bin/phpunit --filter="Stage4c"
BASE44_MYSQL_TEST_DATABASE=special_delicacies_stage4c_test php vendor/bin/phpunit --filter="PaymentAllocationTest|Stage4bCreditAndSecurityTest|CommercialDocumentsTest|FinancialManagementTest"
```

- Stage 4C on MySQL: **29 tests, 122 assertions, 0 failures**.
- Stage 4A/4B regression on MySQL: **37 tests, 231 assertions, 0 failures**.

### Database safety guard

`backend/tests/TestCase.php` now forces in-memory SQLite before the framework boots and fails fast if the resolved connection is not `sqlite :memory:` or an explicitly disposable `special_delicacies_*_test` MySQL database. Protected names (`special_delicacies`, `special_delicacies_db`, `special_delicacies_stage4a_test`, `special_delicacies_stage4a_acceptance_test`, `special_delicacies_stage4b_test`) are always refused.

### Frontend

- `npm run lint` — **0 errors**, 55 warnings (React Compiler `set-state-in-effect` advisories consistent with existing pages).
- `npm run build` — **success** (TypeScript project build + Vite production build + postbuild route fallbacks).

---

## 11. MySQL compatibility results

| Check | Result |
|---|---|
| Engine | MySQL **8.4.13** (`mysql:8.4`) |
| Additive migrations applied | Yes (all five) |
| `credit_ledger_entry_shape` replaced, `credit_ledger_single_payer` retained | Yes |
| Stage 4C suite on MySQL | 29 tests / 122 assertions / 0 failures |
| Stage 4A/4B regression on MySQL | 37 tests / 231 assertions / 0 failures |

No claim is made about MySQL 8.4.11 or any other version.

---

## 12. Authenticated browser acceptance

Performed in the platform-managed authenticated preview against disposable records created for this exercise, then cleaned up.

| Scenario | Result |
|---|---|
| Admin sign-in and Finance navigation (Refunds, Credit Notes, Credit Reversals) | **Passing** — pages render, nav links present |
| Create refund request via UI (payment selection, amount, reason) | **Passing** — request appears as `requested` |
| Initiator attempts to approve own refund | **Passing** — blocked with “A different staff member must approve this refund.” |
| Independent approver approves → submits → confirms | **Passing** — persisted status `confirmed`, verification reference recorded |
| Create draft credit note via UI | **Passing** — draft `CN-2026-00000033` created |
| Issue credit note as independent approver | **Passing** — status `issued`; applied GHS 50.00 to the invoice |
| Credit note effect on invoice | **Passing** — invoice total 200.00, credit applied 50.00, outstanding 150.00 |
| Initiate credit reversal via UI | **Passing** — request `requested` for GHS 40.00 |
| Approve credit reversal as independent approver | **Passing** — status `approved`; compensating ledger entry posted |
| Responsive layouts | Tables use existing responsive patterns; captured at desktop. Mobile/tablet not separately captured. |

**Provider execution remains unverified.** No real Paystack/Hubtel/Korapay refund was executed. Provider paths were exercised only with simulated HTTP responses in automated tests and are disabled by default in the running configuration. Real merchant refund capability must be confirmed before enabling `STAGE4C_PROVIDER_REFUNDS_ENABLED`.

---

## 13. Known limitations and provider dependencies

- **Paystack refund API** is implemented against the documented endpoint and verified server-side, but has not been exercised against a live merchant account. Hubtel and Korapay have no refund endpoint implemented and use the manual path.
- **Manual refund confirmation** requires an independent staff member and recorded evidence; this is deliberate, not a fallback that pretends success.
- **Credit-note voiding** is blocked once any of the credit has been applied; the applied credit must be reversed first.
- **Credit reversal approval** uses a dedicated `credit_reversal_requests` table (an additive addition beyond the design’s ledger-only fields) because an append-only ledger cannot hold a pending state without affecting derived balances.
- **Mobile/tablet browser acceptance** was not separately captured; the interfaces reuse the existing responsive admin patterns.

---

## 14. Migration and deployment considerations

1. Back up the database before running migrations; the new tables hold financial history.
2. Run `php artisan migrate --force` then `php artisan db:seed --force` (the seeder now includes the Stage 4C permissions so fresh installs grant them to `super-admin`).
3. Review D1–D13 and assign mutation permissions to finance roles deliberately; they are super-admin-only by default.
4. Keep `STAGE4C_PROVIDER_REFUNDS_ENABLED=false` until the merchant refund capability is confirmed for each provider.
5. The `credit_ledger_entry_shape` replacement was tested on MySQL 8.4.13; validate on the production engine version before release.
6. Do not merge PR #1 or Stage 4C, and do not deploy, as part of this work.

---

## 15. Environment note

During acceptance the running Laravel server resolved `DB_CONNECTION=sqlite` from `backend/.env` instead of the container’s MySQL environment, which broke all DB-backed requests. The runtime `.env` was corrected to the container’s MySQL settings and the API/proxy were restarted; the app then served DB-backed pages and login correctly. The environment repository’s setup script should write the DB settings into `.env` so this survives a cold start.

---

## 16. Final acceptance classification

**CONDITIONALLY READY**

- Core refund, credit-note and credit-reversal workflows are implemented, integrated, tested on SQLite and MySQL 8.4.13, and accepted in the authenticated browser.
- No blocking financial-integrity defect is known.
- Remaining dependencies: owner sign-off of D1–D13, live provider refund capability, and mobile/tablet visual capture.
- Affected operations are safely restricted: provider refunds are disabled by default; mutations are super-admin-only; separation of duties and independent evidence are enforced.

**Recommended next action:** owner reviews D1–D13, confirms or adjusts `config/stage4c.php`, assigns mutation permissions to the intended finance roles, and verifies merchant refund capability before enabling provider submission.
