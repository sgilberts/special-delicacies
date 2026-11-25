# Payment Result and Order Tracking

The React application serves `/payment/result` and `/track-order`. Production
builds create static route directories and an Apache fallback for SPA paths;
the direct financial page route is also included at `/admin/financials`.

Payment returns accept `reference`, `trxref`, and `transaction_reference`.
When more than one is present, the client checks unique values in order and
displays only a result returned by the API. `GET /api/v1/payments/verify/{reference}`
locates the existing order and uses the configured gateway verifier. The
gateway verifier compares provider status, reference, GHS currency, and amount
before the transaction service marks an order paid. The browser does not write
payment state.

Customers can track an order at `GET /api/v1/orders/track/{orderNumber}`. The
response includes item snapshots, total, payment summary, table or delivery
zone, creation date, and status history. It omits customer contact information
and internal order IDs. Requests are rate limited. New order references use a
16-character random hexadecimal suffix and remain within the existing 32-byte
database column, so no migration is needed.

The frontend shares fresh public reads for menu, content, delivery zones, and
payment options for 30 seconds. Identical concurrent requests share the same
promise. Successful non-GET API responses clear the in-memory cache. The cache
is scoped by the current auth token and does not persist private data in browser
storage.

## Verification

Run the standard checks from `frontend/` (`npm run build`, `npm run lint`) and
`backend/` (`php artisan test`). The order feature tests cover public tracking
history, exposed fields, and the longer generated reference format.
