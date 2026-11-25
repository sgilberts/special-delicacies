# Current Features and Operations Guide

This guide records the latest public ordering, payment, branding, admin, theme, and broadcast behavior implemented in the application. Use it with the [installation guide](INSTALLATION-GUIDE.md), [API reference](API-DOCUMENTATION.md), [database schema](DATABASE-SCHEMA.md), [Paystack setup guide](PAYMENT-GATEWAY-SETUP.md), and [broadcast deployment guide](broadcasting-deployment.md).

## Public ordering

### Table QR ordering

- New table QR codes point to the frontend's canonical route: `/table/?token=<64-character-token>`.
- The frontend reads the `token` query parameter, resolves it through `GET /api/v1/table-lookup/{token}`, and uses the active table details to create a dine-in order.
- Deploy the frontend at the origin configured by backend `FRONTEND_URL`. Existing QR printouts keep their original URL; regenerate/reprint codes when the frontend origin changes.
- Orders are created through `POST /api/v1/orders`. The backend validates the token, table, menu items, quantities, and server-side prices.

### Home delivery checkout

- The public checkout gets active delivery zones from `GET /api/v1/delivery-zones`. Configure and activate zones in Admin → Delivery Zones; inactive zones are not offered to customers.
- The customer selects a zone and address. `include_delivery_fee` determines whether the zone fee is added to the order total. The backend calculates the fee and total; it does not trust client-submitted prices.
- Cash on delivery is available without a gateway. To pay online, the customer selects the configured gateway and provides an email address.

## Online payments

The payment gateway manager supports Paystack, Hubtel, and Kora (Korapay), plus multiple records and create, update, enable, default, and delete actions. An enabled default gateway with valid credentials is selected for online payments on both public delivery and table orders. Cash remains available. The customer is redirected to provider-hosted checkout for online payment.

### Configure the gateway

Follow [PAYMENT-GATEWAY-SETUP.md](PAYMENT-GATEWAY-SETUP.md) for merchant key setup, webhook configuration, deployment requirements, and troubleshooting.

The backend initializes checkout with the order's server-calculated GHS total, stores the provider reference and checkout URL, and verifies the transaction before marking the order paid. Each order remains associated with its selected gateway so a later default change does not redirect verification. Secret keys are encrypted at rest and never returned to the browser. Keep Laravel `APP_KEY` stable across deploys; changing it without re-encrypting stored credentials prevents Laravel from decrypting saved keys.

Payment endpoints under `/api/v1`:

| Method | Path | Access | Purpose |
|---|---|---|---|
| GET | `/payment-options` | Public | Reports whether the default online gateway is available |
| GET | `/payments/verify/{reference}` | Public, throttled | Verifies the gateway associated with the order |
| POST | `/payments/{provider}/webhook` | Public provider callback, throttled | Validates the provider callback and confirms payment |
| GET | `/admin/payment-gateways` | Authenticated, settings permission | Lists gateways and supported providers without secrets |
| POST | `/admin/payment-gateways` | Authenticated, settings permission | Creates a gateway record |
| PUT | `/admin/payment-gateways/{id}` | Authenticated, settings permission | Updates a gateway record |
| PUT | `/admin/payment-gateways/{id}/default` | Authenticated, settings permission | Sets the enabled default gateway |
| DELETE | `/admin/payment-gateways/{id}` | Authenticated, settings permission | Deletes a gateway not used by pending payments |

Public order creation returns `data.payment_checkout_url` when the selected `payment_method` is `gateway`. Gateway checkout requires `customer_email`. A pending online order must not be prepared/fulfilled until payment verification marks it paid; cash orders use the restaurant's existing cash-on-delivery workflow.

## Saved website content and media

- Public website content is read from the database through `GET /api/v1/content`; restaurant settings and website content edited by admins are reflected in public views.
- Logo, favicon, gallery, and menu uploads are stored on Laravel's public disk. Ensure `backend/storage/app/public` is web-accessible using `php artisan storage:link` and that the backend host serves `/storage/...` URLs.
- Logo and favicon are saved as website settings. The public header/footer and ordering views use the saved restaurant name/logo; the client updates the document favicon after content loads.
- Frontend asset URL resolution uses `VITE_API_BASE_URL`'s host for relative storage paths. Set that variable correctly before building the frontend.
- Logo uploads accept JPEG, PNG, JPG, WebP, or SVG (maximum 2 MB); favicon uploads accept ICO, PNG, or SVG (maximum 1 MB). Upload forms should send multipart field names `logo` and `favicon` respectively.

## Staff profiles, roles, and destructive actions

- Admin and staff users can edit their own profile name and details, upload a profile photo, and change their password from their profile page. Password input controls include visibility toggles.
- Restaurant admins with `roles.manage` can create, update, and delete custom roles and assign permissions. A role assigned to staff cannot be deleted; the built-in Super Admin role (`super-admin`) is protected and intrinsically has every system permission.
- The QR management screen allows a revoked QR record to be permanently deleted. Active QR credentials should be revoked before removal.
- Delete and logout actions use confirmation dialogs. These dialogs prevent accidental submission; only confirm after reviewing the target record or action.

## Themes and responsive behavior

Public pages consume the system's global theme tokens for backgrounds, text, borders, and button accents. Public controls use readable foreground/background combinations in light and dark modes. Keep new UI styles on the `--color-*` tokens and verify at narrow/mobile and desktop widths; avoid hardcoded brand colors that bypass the configured theme.

## Release verification checklist

Run from the repository root:

```powershell
cd backend
php artisan migrate:status
php artisan test --compact
php artisan route:list --path=api

cd ..\frontend
npm run build
```

Then verify in a browser/staging environment:

1. Scan a newly generated QR code and check that its path is `/table/` with a `token` query parameter; submit a cash dine-in order.
2. Load public delivery checkout, select an active delivery zone, toggle delivery-fee inclusion, and submit a cash order.
3. With test credentials for the configured default provider, create an online delivery order and table order, complete hosted checkout, and confirm status by callback/webhook. Never use live keys for test orders.
4. Upload a logo and favicon and confirm them on the public home page and ordering pages after refresh.
5. Create/edit a custom role, update permissions, revoke a QR code, and verify its delete confirmation.
6. Connect Admin → Reverb and send a test ping; ensure the client is connected and the ping appears in the live stream. Use the [broadcast deployment guide](broadcasting-deployment.md) for connection troubleshooting.

Automated tests and a successful frontend build do not substitute for live gateway credentials, a reachable webhook URL, or an externally reachable WebSocket service. Those depend on deployment configuration and provider accounts.
