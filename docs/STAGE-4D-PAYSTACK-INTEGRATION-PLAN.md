# Stage 4D-PAYSTACK — Integration Plan

**Status:** implemented on `stage-4d-paystack` (stacked on `org-support-upgrade` / PR #4)
**Provider:** Paystack, Ghana merchant account, `GHS`
**Mode:** read-only retrieval and comparison. No money moves and nothing is posted to the ledger.

---

## 1. Purpose

Stage 4D delivered internal reconciliation (receipts against invoices) and deliberately reported
provider settlement reconciliation as unavailable because no genuine settlement feed existed
(decision D4). This stage connects the real Paystack settlement feed so finance staff can:

1. see the provider's settlement batches and their provider-reported gross / fee / net figures;
2. inspect the transactions inside a batch;
3. compare one batch against local `payment_transactions` in a **read-only preview** before deciding
   anything.

It reuses the existing financial kernel (`App\Services\Reporting\*`, `Money`, `ReportPeriod`,
`AuditService`, the `payment_transactions` table, the reconciliation workbench and its permissions).
No second financial engine, no parallel ledger.

## 2. Non-goals and hard restrictions

| Restriction | Consequence in the code |
| --- | --- |
| D13 (statement retention) pending | No settlement/statement tables, no migrations, no cached settlement payloads. The preview is computed in memory per request. |
| D5 (gateway fee handling) pending | Provider fee fields are **displayed only as reported**. No fee is posted, derived, apportioned or edited. A missing fee is shown as `—`, never calculated. |
| D12 (reconciliation approval separation) pending | No manual matching, no match approval, no second-approver threshold, no adjustment entry. |
| No scheduled import | Nothing runs on a schedule; every call is a staff-initiated request. |
| No ledger mutation | No receipt, allocation, status, credit, refund, credit note or ledger row is written. No reconciliation run is created or closed. |
| No webhooks as source of truth | Documented in §10, not implemented. |

If persistent settlement storage is ever required (retention, cross-settlement history, "already in
another settlement" over time), the owner must first resolve **D13**; the exact question is recorded
in `STAGE-4D-PAYSTACK-IMPLEMENTATION-REPORT.md` §O.

## 3. Architecture

```
config/services.php  paystack.*
        │
        ▼
PaystackSettlementClient        raw GET-only provider access, pagination, export, retries, errors
        │
        ▼
PaystackSettlementMapper        provider payload → stable shape (minor units, verbatim status)
        │
        ▼
PaystackSettlementService       connection state, listing, detail, transactions, audit, cache
        │                       └──► PaystackSettlementReconciliationPreviewService (read-only compare)
        ▼
PaystackSettlementController    /api/v1/admin/reconciliation/paystack/*
        │
        ▼
PaystackSettlementPanel.tsx     section inside the existing FinancialReconciliationPage
```

Every class is resolved through the container; there is no static state and no service locator.

## 4. Configuration

`config/services.php` → `paystack`:

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `base_url` | `PAYSTACK_BASE_URL` | `https://api.paystack.co` | Provider API root. |
| `secret_key` | `PAYSTACK_SECRET_KEY` | `null` | Merchant secret key. No live/test fallback: the configured key is the only key used. |
| `public_key` | `PAYSTACK_PUBLIC_KEY` | `null` | Stored for completeness; never used server-side. |
| `settlement_enabled` | `PAYSTACK_SETTLEMENT_ENABLED` | `false` | Must be `true` **and** a secret key present, otherwise the integration reports `not_configured`. |
| `timeout` | `PAYSTACK_TIMEOUT` | `15` | Per-request timeout in seconds. |
| `retries` | `PAYSTACK_RETRIES` | `2` | Total attempts for a transient failure (one retry). |
| `retry_sleep_ms` | `PAYSTACK_RETRY_SLEEP_MS` | `250` | Base backoff; a numeric `Retry-After` is honoured up to 2 s. |

The key never reaches the browser: no endpoint returns it, and log lines contain only the request
path, HTTP status and a stable state code.

## 5. Status model

Preserved verbatim from the provider: `success`, `processing`, `pending`, `failed` (and any other
string Paystack sends). `status_is_terminal` is `true` only for `success` and `failed`;
`processing` and `pending` are **never** treated as completed, in totals or in matching.

Connection state (our own vocabulary, returned by `GET /status`):

| State | Meaning |
| --- | --- |
| `not_configured` | Feature flag off or no secret key. No provider call is attempted. |
| `unverified` | Configured, but no provider call has succeeded or failed in this cache window. |
| `connected` | The last provider call succeeded. |
| `authentication_failed` | Provider answered 401/403. |
| `provider_unavailable` | Connection failure, timeout or 5xx after the retry. |
| `rate_limited` | Provider answered 429 after the retry. |
| `invalid_response` | Unreadable JSON, `status !== true`, or a required identifier missing. |
| `request_rejected` | Any other non-2xx answer. |

The last success/failure timestamps and the last error live in the application cache
(`paystack.settlement.status`, one day). That is operational metadata, not financial data, and it is
safe to flush.

## 6. Pagination

- Settlements: `perPage` / `page` are forwarded; the provider `meta` (`page`, `pageCount`, `total`,
  `perPage`) is returned so the UI paginates server-side.
- Settlement transactions: the client walks `GET /transaction?settlement=ID&perPage=100&page=N`
  until `meta.pageCount` (hard cap 200 pages) and merges the rows.
- Search is not a Paystack parameter; the `search` filter is applied to the page just retrieved and
  the response marks `meta.filtered = true`.

## 7. Money model

- All provider amounts are **integer minor units** (pesewas). `1 GHS = 100 pesewas`.
- The mapper converts a value to minor units with integer maths; decimal strings from the CSV export
  go through the existing `Money::minor()` helper. No floats are summed.
- `Money::decimal()` produces the display string; the frontend only formats the decimal string.
- Currency is normalised to upper case. A transaction that is not `GHS` is **quarantined**
  (`currency_supported: false`): it is excluded from totals and classified as a currency mismatch.
  There is no conversion, ever.
- A monetary field is exposed only when the provider actually returned it. `total_fees` absent means
  no fee is shown; nothing is estimated.

## 8. Error handling and retries

| Situation | Retried | Reported state | HTTP to the caller |
| --- | --- | --- | --- |
| Connection failure / timeout | once | `provider_unavailable` | 503 |
| 429 | once (honours `Retry-After`) | `rate_limited` | 503 |
| 500/502/503/504 | once | `provider_unavailable` | 503 |
| 401 / 403 | no | `authentication_failed` | 502 |
| Other 4xx | no | `request_rejected` | 422 |
| Invalid JSON / `status: false` | no | `invalid_response` | 502 |
| Not configured | no call | `not_configured` | 409 |

## 9. Export link handling

`GET /transaction/export?settlement=ID` returns a signed `data.path` that expires in roughly 60
seconds.

1. The path is used **immediately** in the same operation and never stored, logged or returned.
2. A `403` / `404` / `410` on the download triggers **one** fresh export request and a second attempt.
3. Any other export problem (5xx, unreadable CSV, no `reference` column) falls back to the paginated
   `GET /transaction` path.
4. The response tells the caller which path was used (`provider_source: export | paginated`).

## 10. Webhook options (documented, deliberately not implemented)

Paystack can deliver events such as `charge.success`, `transfer.success` and settlement/transfer
notifications. They are **not** used as the source of truth here because:

- an event confirms a payment or a transfer, not the composition of a settlement batch;
- webhook delivery is at-least-once and can arrive late or out of order, which would need persistent
  de-duplication — blocked by D13;
- treating an event as a settlement would create a second, unreconciled financial record.

If a webhook tier is ever wanted, the prerequisite is a durable, idempotent event store (D13) and an
owner decision on whether webhook data may ever replace pull-based retrieval. Until then, retrieval
is pull-only and staff-initiated.

## 11. Security and permissions

| Endpoint | Permission |
| --- | --- |
| `GET /status`, `/settlements`, `/settlements/{id}`, `/settlements/{id}/transactions`, `/settlements/{id}/preview` | `financials.reports.settlements.view` |
| `POST /connection-test` | `financials.reconciliation.manage` (plus `throttle:10,1`) |

Preview generation is additionally throttled (`throttle:30,1`). The connection test and every
generated preview are written to the existing admin audit log through `AuditService`. The secret key
is never returned, logged or embedded in an error message; provider response bodies are never copied
into a message.

## 12. Frontend

`frontend/src/components/financial/PaystackSettlementPanel.tsx`, imported by
`FinancialReconciliationPage`, using the existing design tokens so light, dark and system themes and
the responsive layouts are inherited. It shows:

- the connection state and last success/failure, with a retry;
- filters (provider status, date range, page search), pagination and empty/error/loading states;
- the settlement list with provider-reported gross / fee / net in GHS;
- a settlement detail with its provider transactions and retrieval path;
- the read-only preview with three separate state tiles — **provider data retrieved**,
  **preview generated**, **financially reconciled: No** — plus per-classification counts, totals and
  the comparison table.

No green success indicator is used for retrieval. Green is reserved for the existing internal
reconciliation runs; provider retrieval uses the accent/neutral tone and reconciliation status is
explicitly stated as *not* achieved.

## 13. Testing strategy

Every provider response is faked with Laravel's HTTP client fake: no live merchant account, no real
transaction data. Coverage includes configuration states, listing, GHS minor units, multi-page
settlements and transactions, empty results, pending/failed/processing statuses, timeout, 401/403/429/5xx,
invalid JSON, missing identifiers, unexpected currency, duplicate references, export-link expiry and
fallback, each preview classification, the read-only guarantee, secret-key absence from responses and
logs, and permission enforcement. The suite runs on SQLite (default) and was additionally verified
against MySQL 8.4.

## 14. Known limitations

- Without persistent storage (D13) the preview cannot prove that a transaction was settled in a
  *different* batch over time. It only flags a transaction whose provider payload explicitly names
  another settlement id.
- "Local only" detection is scoped to successful Paystack receipts in the settlement currency dated
  on the settlement date (limit 500). The scope is returned in `local_scope` and shown in the UI.
- Paystack has no settlement search parameter, so search filters the retrieved page.
- The connection state is cached for a day; a provider outage is only reflected after the next call.
