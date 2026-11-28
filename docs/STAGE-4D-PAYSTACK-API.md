# Stage 4D-PAYSTACK — API Reference

Base path: `/api/v1/admin/reconciliation/paystack`
Authentication: Sanctum staff bearer token (or the staff cookie session), as for every other admin route.
All responses are JSON. Every endpoint is read-only except `POST /connection-test`, which performs an
authenticated provider probe and writes an audit entry.

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/status` | `financials.reports.settlements.view` | Connection state, last success/failure |
| POST | `/connection-test` | `financials.reconciliation.manage` | Audited credential probe |
| GET | `/settlements` | `financials.reports.settlements.view` | Provider settlement list |
| GET | `/settlements/{settlement}` | `financials.reports.settlements.view` | One provider settlement |
| GET | `/settlements/{settlement}/transactions` | `financials.reports.settlements.view` | All transactions in the settlement |
| GET | `/settlements/{settlement}/preview` | `financials.reports.settlements.view` | Read-only comparison with local receipts |

`{settlement}` is the numeric Paystack settlement id.

---

## GET /status

Always `200`, even when the integration is not configured — this is a state, not an error.

```json
{
  "success": true,
  "data": {
    "provider": "paystack",
    "configured": true,
    "settlement_enabled": true,
    "state": "connected",
    "state_label": "Connected",
    "currency": "GHS",
    "read_only": true,
    "last_success_at": "2026-11-28T09:22:08+00:00",
    "last_failure_at": "2026-11-28T09:18:49+00:00",
    "last_error": null,
    "restrictions": [
      "Provider data is retrieved and compared only; nothing is posted to the ledger.",
      "No settlement is marked financially reconciled by this integration.",
      "Owner decisions D5, D12 and D13 remain pending."
    ]
  }
}
```

States: `not_configured`, `unverified`, `connected`, `authentication_failed`,
`provider_unavailable`, `rate_limited`, `invalid_response`, `request_rejected`.
`last_error` has the shape `{ "state": string, "message": string, "http_status": number|null }`.

## POST /connection-test

Calls `GET /settlement?perPage=1&page=1`. Returns the same status object as `/status`; the audit
entry `financials.paystack.connection_tested` records the resulting state and HTTP status (never the
key). Throttled to 10 requests per minute.

## GET /settlements

Query parameters (all optional):

| Name | Type | Notes |
| --- | --- | --- |
| `per_page` | int 1–100 | Forwarded as `perPage`. Default 50. |
| `page` | int ≥ 1 | Forwarded as `page`. Default 1. |
| `status` | `success` \| `processing` \| `pending` \| `failed` | Forwarded as `status`. |
| `from` | `YYYY-MM-DD` | Forwarded as `from`. |
| `to` | `YYYY-MM-DD`, ≥ `from` | Forwarded as `to`. |
| `search` | string ≤ 100 | Applied to the retrieved page only (`meta.filtered = true`). |

```json
{
  "success": true,
  "state": "connected",
  "data": [
    {
      "provider": "paystack",
      "id": "1001",
      "status": "success",
      "status_is_terminal": true,
      "currency": "GHS",
      "currency_supported": true,
      "settlement_date": "2026-11-20",
      "provider_amounts": {
        "total_amount": { "minor": 50000, "decimal": "500.00" },
        "effective_amount": { "minor": 48500, "decimal": "485.00" },
        "total_fees": { "minor": 1500, "decimal": "15.00" },
        "total_processed": { "minor": 51000, "decimal": "510.00" }
      },
      "transactions_count": null,
      "created_at": "2026-11-20T09:00:00.000Z",
      "updated_at": "2026-11-20T09:05:00.000Z"
    }
  ],
  "meta": { "page": 1, "page_count": 1, "per_page": 50, "total": 2, "filtered": false }
}
```

`provider_amounts` only contains fields the provider returned. `deductions` is absent when Paystack
did not send it — it is never estimated.

## GET /settlements/{settlement}

Returns one mapped settlement as `data`, plus `read_only: true`. A response without an identifier is
rejected as `invalid_response` (502) rather than returned with an empty id.

## GET /settlements/{settlement}/transactions

```json
{
  "success": true,
  "state": "connected",
  "source": "export",
  "data": [
    {
      "provider": "paystack",
      "id": "9001",
      "reference": "PSTK-1",
      "status": "success",
      "currency": "GHS",
      "currency_supported": true,
      "amount": { "minor": 12000, "decimal": "120.00" },
      "fees": { "minor": 120, "decimal": "1.20" },
      "channel": "card",
      "paid_at": "2026-11-20T09:00:00.000Z",
      "settlement": "1001"
    }
  ],
  "meta": { "count": 3 }
}
```

`source` is `export` when the CSV export was used and `paginated` when the fallback ran. The temporary
download URL is never part of the response.

## GET /settlements/{settlement}/preview

The read-only comparison. Nothing is persisted; the only side effect is the audit entry
`financials.paystack.preview_generated` (settlement id and counts only).

```json
{
  "success": true,
  "data": {
    "settlement": { "...": "as in GET /settlements/{id}" },
    "currency": "GHS",
    "provider_source": "export",
    "summary": {
      "provider_transactions": 3,
      "local_records": 4,
      "classifications": {
        "exact_match": 1,
        "possible_match": 1,
        "amount_mismatch": 1,
        "currency_mismatch": 0,
        "provider_only": 0,
        "local_only": 1,
        "duplicate_provider_transaction": 0,
        "other_settlement": 0,
        "refund_or_reversal": 0,
        "undetermined": 0
      },
      "provider_total": { "minor": 20000, "decimal": "200.00" },
      "matched_local_total": { "minor": 15000, "decimal": "150.00" },
      "difference": { "minor": 5000, "decimal": "50.00" },
      "quarantined": 0,
      "needs_attention": 3
    },
    "local_scope": {
      "provider": "paystack",
      "status": "succeeded",
      "currency": "GHS",
      "date_basis": "received_at on the settlement date",
      "limit": 500,
      "truncated": false
    },
    "rows": [
      {
        "classification": "exact_match",
        "classification_label": "Exact match",
        "explanation": "Reference, currency, amount and local status all agree.",
        "reference": "PSTK-1",
        "provider": { "id": "9001", "reference": "PSTK-1", "status": "success", "currency": "GHS", "currency_supported": true, "amount": { "minor": 12000, "decimal": "120.00" }, "fees": { "minor": 120, "decimal": "1.20" }, "channel": "card", "paid_at": "2026-11-20T09:00:00.000Z", "settlement": "1001" },
        "local": { "id": 1, "transaction_reference": "LOCAL-1", "provider_reference": "PSTK-1", "status": "succeeded", "currency": "GHS", "amount": { "minor": 12000, "decimal": "120.00" }, "received_at": "2026-11-20T09:00:00+00:00" },
        "difference": { "minor": 0, "decimal": "0.00" }
      }
    ],
    "read_only": true,
    "mutations_applied": false,
    "restrictions": {
      "D5": "Provider fee fields are shown only as reported. No fee is posted to the ledger, derived or edited.",
      "D12": "No manual matching confirmation or second-approver threshold exists in this preview.",
      "D13": "Nothing in this preview is persisted: no settlement or statement rows are stored."
    },
    "generated_at": "2026-11-28T09:22:45+00:00"
  }
}
```

`provider_total` sums provider amounts for transactions belonging to this settlement in a supported
currency, counting a duplicated reference once. `matched_local_total` sums the local amounts of
`exact_match` and `possible_match` rows. `difference = provider_total − matched_local_total`.

## Errors

```json
{ "success": false, "state": "provider_unavailable", "message": "Paystack is temporarily unavailable. Try again shortly." }
```

| State | HTTP | Typical cause |
| --- | --- | --- |
| `not_configured` | 409 | Feature flag off or no secret key |
| `authentication_failed` | 502 | 401 / 403 from the provider |
| `invalid_response` | 502 | Unreadable JSON, `status: false`, missing identifier |
| `rate_limited` | 503 | 429 after the retry |
| `provider_unavailable` | 503 | Timeout, connection failure or 5xx after the retry |
| `request_rejected` | 422 | Any other non-2xx answer |

Validation failures use Laravel's standard `422` shape. Missing permission returns `403`; a missing or
expired staff session returns `401`.

## Permissions summary

- `financials.reports.settlements.view` — every read above.
- `financials.reconciliation.manage` — the connection test.
- The preview endpoint additionally carries `throttle:30,1`; the connection test `throttle:10,1`.

Super Admin holds both permissions through `RoleAndPermissionSeeder`. No new permission was invented
for this stage.
