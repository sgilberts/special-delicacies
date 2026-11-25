# Online Payment Gateway Setup

The payment settings page supports multiple gateway records for Paystack, Hubtel, and Kora (Korapay). An admin can add, edit, enable, set a default, or delete a gateway. Only an enabled default gateway with its required credentials appears as the online option on delivery and table ordering checkouts. Cash ordering remains available.

## Checkout flow

1. The frontend loads `GET /api/v1/payment-options` to show cash and, when configured, the active online provider.
2. The customer selects online payment and provides an email where required. The order API calculates prices and totals server-side.
3. `POST /api/v1/orders` creates the order and initializes the chosen default provider. The response includes `data.payment_checkout_url` for hosted checkout.
4. The return page asks the API to verify the transaction. Provider webhooks can also confirm payment. A matching reference, successful provider status, GHS currency, and exact amount are required before an order is marked paid.
5. An order retains its selected gateway record so verification continues against the provider used at checkout, even if the admin later changes the default.

## Configure a gateway

Open **Admin → Settings → Payment Gateways**, add or edit a provider, enter the merchant credentials, enable it, and set it as default. The secret key is encrypted at rest and is never returned to the browser. On edits, leave the secret field blank to retain the existing key. Preserve Laravel `APP_KEY` across deployments and restores.

| Provider | Required settings | Webhook route |
|---|---|---|
| Paystack | Public key and secret key | `/api/v1/payments/paystack/webhook` |
| Hubtel | API ID in public key, API key in secret key, collection account number in provider configuration | `/api/v1/payments/hubtel/webhook` |
| Kora (Korapay) | Public key and secret key | `/api/v1/payments/korapay/webhook` |

Register the webhook URL using the public HTTPS API origin in each merchant dashboard. Set `FRONTEND_URL` to the public website origin for payment return links and `APP_URL` to the API origin for uploaded media. Payment providers must be able to reach the webhook. Some Hubtel status checks also require the server IP to be allowlisted in the merchant account.

## API routes

| Method | Route | Description |
|---|---|---|
| GET | `/api/v1/payment-options` | Public online-payment availability and default provider |
| GET | `/api/v1/payments/verify/{reference}` | Server-side verification; throttled |
| POST | `/api/v1/payments/paystack/webhook` | Paystack signed callback |
| POST | `/api/v1/payments/hubtel/webhook` | Hubtel callback followed by provider status verification |
| POST | `/api/v1/payments/korapay/webhook` | Kora signed callback |
| GET | `/api/v1/admin/payment-gateways` | Admin gateway records and supported provider metadata |
| POST | `/api/v1/admin/payment-gateways` | Create gateway record |
| PUT | `/api/v1/admin/payment-gateways/{id}` | Update gateway record |
| PUT | `/api/v1/admin/payment-gateways/{id}/default` | Set enabled record as default |
| DELETE | `/api/v1/admin/payment-gateways/{id}` | Delete record when no pending orders depend on it |

Older singular `/admin/payment-gateway` read/update routes remain for compatibility.

## Local and shared hosting

- Use provider test credentials in local development and staging.
- Localhost cannot receive provider callbacks. Use an HTTPS tunnel or a reachable staging host for webhook tests.
- On shared hosting, run `php artisan migrate --force`, keep PHP outbound HTTPS enabled, configure the provider's webhook with the public API URL, and ensure the hosting firewall permits callback and API traffic.
- Keep secret credentials in the admin gateway form; never put them in frontend environment variables, browser code, logs, or source control.
- Keep `APP_KEY` stable. If it has been changed, restore the previous key or re-enter the gateway secrets.

## Troubleshooting

| Symptom | Check |
|---|---|
| Online option is missing | An enabled default gateway exists and has the required credentials; inspect `/api/v1/payment-options`. |
| Gateway form fails to save | Check validation feedback and required fields for that provider. A blank secret on edit keeps the stored secret. |
| Checkout URL is missing | Inspect the order response and Laravel logs; confirm API outbound HTTPS and merchant credentials. |
| Return page remains pending | Check the provider reference, callback URL, API reachability, and the provider's transaction status. |
| Webhook is rejected | Verify the registered API URL, matching provider secret, raw request signature, and proxy configuration. |
| Hubtel status cannot be verified | Check the merchant account's server IP allowlist and transaction-status API access. |

Automated tests mock provider calls. Validate live transactions with provider test accounts before enabling live credentials. Project checks are documented in [FEATURES-AND-OPERATIONS.md](FEATURES-AND-OPERATIONS.md).
