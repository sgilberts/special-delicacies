# Stage 4D-PAYSTACK — Implementation Report

Branch `stage-4d-paystack`, stacked on `org-support-upgrade` (PR #4, Stage 4D foundation).
Baseline commit `61f807b`. Provider: Paystack (Ghana, GHS). Mode: **read-only**.

## A. Scope and outcome

Implemented an automated, read-only Paystack settlement integration on top of the existing Stage 4D
kernel: configuration, a GET-only provider client with pagination/export/retries/structured errors, a
mapper that keeps integer minor units and verbatim provider statuses, a read-only reconciliation
preview, six admin endpoints under the existing reconciliation permissions, a native Paystack section
in the reconciliation workbench, 23 feature tests, and four documents.

No settlement or statement table, no migration, no fee posting, no manual matching, no scheduled job,
no ledger mutation and no reconciliation-run closure was added.

### Files added

| File | Purpose |
| --- | --- |
| `backend/app/Exceptions/PaystackSettlementException.php` | Structured, secret-free failure with a stable state |
| `backend/app/Services/Paystack/PaystackSettlementClient.php` | GET-only provider access, pagination, CSV export, retries |
| `backend/app/Services/Paystack/PaystackSettlementMapper.php` | Provider payload → stable shape, minor units, verbatim statuses |
| `backend/app/Services/Paystack/PaystackSettlementService.php` | Connection state, listing/detail/transactions, audit, cache |
| `backend/app/Services/Paystack/PaystackSettlementReconciliationPreviewService.php` | Read-only comparison and classification |
| `backend/app/Http/Controllers/Api/V1/Admin/PaystackSettlementController.php` | Admin endpoints |
| `backend/tests/Feature/Stage4dPaystackSettlementTest.php` | 23 feature tests |
| `frontend/src/components/financial/PaystackSettlementPanel.tsx` | Workbench section |
| `docs/STAGE-4D-PAYSTACK-*.md` | Plan, API, guide, this report |

### Files changed

| File | Change |
| --- | --- |
| `backend/config/services.php` | `paystack` configuration block |
| `backend/.env.example` | `PAYSTACK_*` entries (blank key, feature flag off) |
| `backend/routes/api.php` | Six routes under `admin/reconciliation/paystack` |
| `backend/phpunit.xml` | Forces `PAYSTACK_SECRET_KEY=""` and `PAYSTACK_SETTLEMENT_ENABLED=false` so a real key in a developer's `.env` can never switch provider retrieval on inside the suite |
| `backend/app/Services/Reporting/ReconciliationService.php` | Settlement availability now reports `provider_read_only` when configured; `D4` removed from the pending list (the feed now exists, read-only) |
| `frontend/src/pages/admin/financial/FinancialReconciliationPage.tsx` | Renders the panel; copy updated |

## B. Configuration

`config/services.php → paystack`: `base_url` (default `https://api.paystack.co`), `secret_key`,
`public_key`, `settlement_enabled`, `timeout`, `retries`, `retry_sleep_ms`. There is **no live/test
fallback**: the configured key is the only key used, and `isConfigured()` requires both the flag and a
non-empty key. `.env.example` ships with an empty key and `PAYSTACK_SETTLEMENT_ENABLED=false`, so a
fresh checkout reports `not_configured` and makes no network call.

No secret value is written to the repository. In this sandbox no real Paystack credentials exist, so
the integration was exercised against a fake provider only.

## C. Provider client

`PaystackSettlementClient` issues only GET requests:

- `GET /settlement` (list, filters and pagination forwarded)
- `GET /settlement/{id}`
- `GET /settlement/{id}/transactions` (paginated walk, cap 200 pages)
- `GET /transaction/export?settlement=ID` → signed `data.path` used immediately, never stored
- `GET /transaction?settlement=ID&perPage=100&page=N` (fallback)

Retries: one retry for connection failures, 429 and 5xx; a numeric `Retry-After` is honoured up to
2 s. 401/403 → `authentication_failed`; 429 → `rate_limited`; 5xx → `provider_unavailable`; other
non-2xx → `request_rejected`; unreadable JSON or `status: false` → `invalid_response`. Log lines
contain only path, HTTP status and state — never the key or a provider body.

## D. Mapping and money

`PaystackSettlementMapper` produces a stable shape for settlements and transactions. Amounts stay
integer minor units; a decimal string (CSV export) goes through `Money::minor()`, which uses integer
maths. `provider_amounts` contains only fields the provider actually returned — a missing
`total_fees`/`deductions` is absent, never calculated. Currency is upper-cased; anything other than
`GHS` is marked `currency_supported: false` and quarantined. Provider statuses are preserved verbatim
with `status_is_terminal` true only for `success`/`failed`.

## E. Retrieval, pagination and export expiry

Settlement listing forwards `perPage`/`page`/`status`/`from`/`to` and returns the provider `meta`
(`page`, `pageCount`, `total`, `perPage`). Transaction retrieval tries the CSV export first; on
`403`/`404`/`410` it requests one fresh export and retries the download, and on any other export
problem (5xx, empty or unusable CSV without a `reference` column) it falls back to the paginated
endpoint. The response reports `source: export | paginated`.

## F. Read-only reconciliation preview

`PaystackSettlementReconciliationPreviewService` compares the selected settlement's provider
transactions with local `payment_transactions`. Matching order: local `provider_reference`, then
local `transaction_reference`, then — only when no reference matches — equal amount and payment date.
Classifications: `exact_match`, `possible_match`, `amount_mismatch`, `currency_mismatch`,
`provider_only`, `local_only`, `duplicate_provider_transaction`, `other_settlement`,
`refund_or_reversal`, `undetermined`. Every row carries provider amount, local amount, difference,
matched reference and an explanation.

The service performs SELECTs only. The single write on the path is the audit entry
`financials.paystack.preview_generated` (settlement id and counts). It cannot close a reconciliation
run and touches no receipt, allocation, status, credit, refund, credit note or ledger row.

## G. Admin API and permissions

`GET /status`, `GET /settlements`, `GET /settlements/{id}`, `GET /settlements/{id}/transactions`,
`GET /settlements/{id}/preview` require `financials.reports.settlements.view`.
`POST /connection-test` requires `financials.reconciliation.manage` and is throttled (10/min); the
preview is throttled at 30/min. No new permission was introduced. Error mapping: 409 `not_configured`,
502 `authentication_failed`/`invalid_response`, 503 `rate_limited`/`provider_unavailable`,
422 `request_rejected`.

`ReconciliationService::settlementAvailability()` now reports `available: true`,
`tier: provider_read_only`, `provider: paystack`, `read_only: true` when configured, and keeps the
existing "not configured" explanation otherwise. `D4` is no longer listed as pending; `D5`, `D12` and
`D13` remain.

## H. Frontend workbench

`PaystackSettlementPanel` is a native section of `FinancialReconciliationPage`, built on the existing
design tokens (light/dark/system, responsive). It shows the connection badge, last success/failure,
the last error, restrictions, an audited **Test provider connection** button, filters (provider
status, date range, page search), pagination, loading/empty/error states with retry, the settlement
table with provider-reported gross/fee/net in GHS, a detail view with its transactions and retrieval
source, and the read-only preview with three separate state tiles (**provider data retrieved**,
**preview generated**, **financially reconciled: No — read-only preview**), classification counts,
totals and the comparison table. Retrieval success never uses a green success indicator.

## I. Audit and secret handling

`AuditService` records `financials.paystack.connection_tested` (success and failure) and
`financials.paystack.preview_generated`. Diagnostics (last success/failure, last error) live in the
application cache for a day — operational metadata only, safe to flush, not a settlement store.

## J. Decision restrictions honoured

- **D5** (gateway fees): provider fee fields are displayed only as reported; nothing is posted,
  derived or edited.
- **D12** (approval separation): no manual matching, match confirmation, adjustment or second
  approver exists in this stage.
- **D13** (statement retention): no settlement/statement table, migration or cached payload; the
  preview is in-memory and disposable.
- No webhooks were implemented as a source of truth (documented as an option in the plan, §10).

## K. Automated tests (SQLite, default suite)

`tests/Feature/Stage4dPaystackSettlementTest.php` — **23 tests, 116 assertions, all passing**:

| Area | Tests |
| --- | --- |
| Config / auth | not configured (no HTTP call), status shape, audited connection test, 401 and 403 as `authentication_failed`, unauthenticated 401 |
| Listing | verbatim `success`/`processing`/`pending`/`failed` with `status_is_terminal`, GHS minor units and decimals, absent `deductions` not invented, filters and pagination meta forwarded, empty page |
| Transactions | multi-page merge, export used immediately and the temporary URL absent from the response, expired link → one fresh export, export 500 → paginated fallback, unreadable CSV → paginated fallback |
| Failures | timeout retried once, 429 retried, 5xx retried, invalid JSON, settlement without identifier |
| Secrets | key absent from every response and every log line during a failure |
| Preview | all ten classifications including duplicate, other settlement, refund/reversal, local-only, amount mismatch difference, NGN quarantine excluded from totals, amount+date possible match |
| Read-only | table counts, first receipt status/amount/updated_at and reconciliation-run count unchanged after a preview |
| Permissions | settlement view required for reads and status, manage required for the connection test |

The suite is hermetic with respect to the provider: `phpunit.xml` forces an empty
`PAYSTACK_SECRET_KEY` and `PAYSTACK_SETTLEMENT_ENABLED=false`, and tests that need the integration
set `services.paystack` config explicitly. This also keeps the existing Stage 4D assertions about an
unavailable settlement feed valid on any developer machine.

Full backend suite: **572 passed, 3394 assertions** (PHP 8.4 + GD). Without GD in the throwaway
container, 7 pre-existing image-upload tests fail with `LogicException: GD extension is not
installed` — the known GD finding (item 16); they pass as soon as GD is available, and no
Dockerfile/GD configuration was touched.

## L. MySQL verification

The Stage 4D suite (reporting, reconciliation and the new Paystack tests) was run against **MySQL
8.4** with a dedicated database: **47 tests, 324 assertions, all passing**. This exercised the
migrations, the `payment_transactions` queries used by the preview and the audit writes on MySQL
rather than SQLite.

## M. Browser verification

The repository has no compose file and the configuration root is absent in this sandbox
(`configure_environment` unavailable), so a **temporary local stack outside the repository** was used:
MySQL 8.4, `php artisan serve` on :8000, the Vite dev server on :3000, and a fake Paystack provider on
:8001 (JSON + CSV export). Three seeded local receipts and four local-only receipts were inserted into
a sandbox-only preview database; no repository file describes this stack.

Verified in the running app as the seeded Super Admin on `/admin/financials/reconciliation`:

- the panel renders with the **READ-ONLY** badge and the connection card;
- **Test provider connection** moves the badge to **Connected** and records the audit entry;
- the settlement list renders both batches with `GHS 500.00 / GHS 15.00 / GHS 485.00`, provider
  statuses labelled "provider-reported, final" and "provider-reported, not completed";
- **Inspect** loads the settlement and its three provider transactions (`PSTK-1..3`) via the CSV
  export;
- **Generate read-only preview** renders the three state tiles (including
  **"No — read-only preview"**), the totals (provider `GHS 200.00`, matched `GHS 150.00`, difference
  `GHS 50.00`), all classification chips and four comparison rows: exact match, amount mismatch
  (`GHS 50.00` vs `GHS 45.00`, difference `GHS 5.00`), possible match (amount + date only) and local
  only;
- desktop and mobile captures show no horizontal page scroll.

Not visually verified: the `not_configured` state (the sandbox stack is configured) — it is covered by
automated tests and by the component's own branch. A real Paystack account was never contacted.

## N. Deliberately not implemented

Settlement/statement persistence and migrations (D13); fee posting, derivation or editing (D5); manual
matching, match approval, adjustment entries or a second-approver threshold (D12); scheduled import
jobs; webhook ingestion as a source of truth; any ledger, receipt, allocation, credit, refund or
credit-note mutation; closing a reconciliation run from the provider preview; exports of provider
settlements; Stage 5.

## O. Blocked / not verifiable in this sandbox

- **No managed environment.** There is no `docker-compose.base44.yml`, the configuration root is
  absent and `configure_environment` is unavailable, so the app cannot be provisioned through the
  platform here. Verification used the temporary stack described in §M; the preview will be blank
  again once those containers are gone.
- **No real Paystack merchant account.** Every provider response in the automated tests is faked, and
  the browser run used a local fake provider. Real-world behaviours that depend on Paystack's actual
  payloads (for example a settlement without `transactions`, or an export whose CSV columns differ
  from the documented set) are handled defensively but were not observed live.
- **Cross-settlement history** ("already in another settlement" over time) needs persistent storage.
  Until D13 is decided, the flag is only raised when the provider payload itself names another
  settlement id.
- **Decision needed if persistence becomes a requirement:** *May Stage 4D-PAYSTACK store normalised
  settlement headers and lines (with raw payload JSON) so previews can be reproduced, audited and
  compared across batches — and if so, for how long and with which retention/redaction rules?*
  That is D13; it requires an owner answer before any table or migration is written.

## P. Follow-up decisions

| Decision | Why it is still open |
| --- | --- |
| D5 — gateway fee handling | Fees are visible from the provider but nothing may be posted until the owner decides how a fee is recorded and who may edit it. |
| D12 — reconciliation approval separation | The preview deliberately cannot confirm a match; a second-approver threshold is still undecided. |
| D13 — statement retention | Blocks persisted settlements, historical previews and durable cross-settlement detection. |

Recommended next step after the owner answers D13: a follow-up stage that stores the normalised
settlement header and lines, adds a reproducible preview snapshot and only then considers fee posting
(D5) and match approval (D12).
