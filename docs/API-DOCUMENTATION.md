# Special Delicacies - API Documentation

## 1. API Overview

- **Backend Framework:** Laravel 13
- **Architecture Style:** RESTful JSON API
- **API Version:** v1
- **Base URL:** `https://api.special-delicacies.local/api/v1`
- **Operating Currency:** Ghana Cedi (`GHS` / `GH₵`)

Recommended Headers:
- `Accept: application/json`
- `Content-Type: application/json`

---

## 2. Implemented Endpoints

### 2.1 Health Check (Phase 1)
- **Method:** `GET`
- **Path:** `/api/v1/health`
- **Auth:** Public
- **Response (200 OK):**
```json
{
  "success": true,
  "message": "Special Delicacies API is running.",
  "application": "Special Delicacies",
  "version": "1.0.0"
}
```

### 2.2 Authenticated User (Phase 1)
- **Method:** `GET`
- **Path:** `/api/v1/user`
- **Auth:** Required (`auth:sanctum`)
- **Response (200 OK):** Returns authenticated user record with `phone` and `is_active`.

---

### 2.3 Public Menu Listing (Phase 2)
- **Method:** `GET`
- **Path:** `/api/v1/menu`
- **Auth:** Public
- **Response (200 OK):** Returns active menu categories with available menu items and GHS pricing.
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Main Courses",
      "slug": "main-courses",
      "sort_order": 1,
      "is_active": true,
      "available_items": [
        {
          "id": 1,
          "category_id": 1,
          "name": "Grilled Tilapia",
          "slug": "grilled-tilapia",
          "price": "35.00",
          "is_available": true
        }
      ]
    }
  ]
}
```

---

### 2.4 Table QR Token Resolution (Phase 2)
- **Method:** `GET`
- **Path:** `/api/v1/table-lookup/{token}`
- **Auth:** Public
- **Description:** Resolves a scanned plain-text QR token to its active table. Database IDs and token hashes are never exposed.
- **Success Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "table_id": 1,
    "table_number": "T-01",
    "capacity": 4
  }
}
```
- **Error Response (404 Not Found):** If token is invalid, expired, revoked, or table is inactive:
```json
{
  "success": false,
  "message": "Invalid, expired, or deactivated table QR code."
}
```

---

### 2.5 Place Order (Phase 2)
- **Method:** `POST`
- **Path:** `/api/v1/orders`
- **Auth:** Public / Customer or Staff
- **Headers:** Optional `X-Idempotency-Key: <unique-string>` for duplicate-submission protection.
- **Request Body (Dine-in):**
```json
{
  "idempotency_key": "opt-uuid-or-unique-string",
  "order_type": "dine_in",
  "restaurant_table_id": 1,
  "customer_name": "Kofi Mensah",
  "customer_phone": "+233201234567",
  "customer_notes": "Extra pepper sauce",
  "items": [
    {
      "menu_item_id": 1,
      "quantity": 2,
      "special_instructions": "Well done"
    }
  ]
}
```
- **Request Body (Delivery):**
```json
{
  "idempotency_key": "opt-uuid-or-unique-string",
  "order_type": "delivery",
  "delivery_zone_id": 1,
  "delivery_address": "123 Independence Avenue, Accra",
  "customer_name": "Ama Serwaa",
  "customer_phone": "+233241234567",
  "items": [
    {
      "menu_item_id": 1,
      "quantity": 1
    }
  ]
}
```
- **Success Response (201 Created for new order; 200 OK for idempotent duplicate):**
```json
{
  "success": true,
  "message": "Order placed successfully.",
  "data": {
    "id": 1,
    "order_number": "SD-20261122-A1B2C3",
    "idempotency_key": "opt-uuid-or-unique-string",
    "order_type": "dine_in",
    "restaurant_table_id": 1,
    "status": "pending",
    "subtotal": "70.00",
    "delivery_fee": "0.00",
    "total": "70.00",
    "items": [
      {
        "id": 1,
        "item_name": "Grilled Tilapia",
        "unit_price": "35.00",
        "quantity": 2,
        "line_total": "70.00"
      }
    ]
  }
}
```

---

### 2.6 View Order Details (Phase 2)
- **Method:** `GET`
- **Path:** `/api/v1/orders/{id}`
- **Auth:** Required (`auth:sanctum`, `permission:orders.view`)
- **Response (200 OK):** Order details including items, audit histories, table, and zone.

---

### 2.7 Update Order Status (Phase 2)
- **Method:** `PATCH`
- **Path:** `/api/v1/orders/{id}/status`
- **Auth:** Required (`auth:sanctum`, `permission:orders.status.update`)
- **Request Body:**
```json
{
  "status": "confirmed",
  "note": "Order verified by staff"
}
```
- **Validation:** Only valid lifecycle transitions are permitted. Invalid transitions return 422 Unprocessable Entity.

---

### 2.8 Update Menu Item Price (Phase 2)
- **Method:** `PUT`
- **Path:** `/api/v1/admin/menu/items/{id}/price`
- **Auth:** Required (`auth:sanctum`, `permission:menu.price.edit`)
- **Access Rule:** Waiter/Waitress role is strictly denied access (403 Forbidden). Super Admin is permitted.
- **Request Body:**
```json
{
  "price": 38.00
}
```

---

### 2.9 Restaurant Settings Endpoints (Phase 2)
- **Method:** `GET` `/api/v1/admin/settings`
- **Auth:** Required (`auth:sanctum`, `permission:settings.view,settings.manage`)
- **Access Rule:** Kitchen Staff, Waiters, and Delivery Staff are strictly denied access (403 Forbidden).
- **Method:** `PUT` `/api/v1/admin/settings`
- **Auth:** Required (`auth:sanctum`, `permission:settings.manage`)
- **Request Body:**
```json
{
  "settings": [
    {
      "key": "operating_hours",
      "value": "10:00 - 23:00",
      "group": "operations"
    }
  ]
}
```

---

### 2.10 Contact Inquiry Submission (Phase 3)
- **Method:** `POST`
- **Path:** `/api/v1/contact`
- **Auth:** Public
- **Description:** Submits a customer inquiry, reservation question, or general message from the public contact page.
- **Headers:**
  - `Content-Type: application/json`
  - `Accept: application/json`
- **Request Body:**
```json
{
  "name": "Kofi Mensah",
  "email": "kofi@example.com",
  "phone": "+233 24 123 4567",
  "subject": "Table Reservation Inquiry",
  "message": "Hello, I would like to inquire about reserving a private banquet for 12 guests next Saturday."
}
```
- **Validation Rules:**
  - `name`: Required, string, max 150 characters.
  - `email`: Required, valid email format, max 200 characters.
  - `phone`: Optional, string, max 30 characters.
  - `subject`: Required, string, max 200 characters.
  - `message`: Required, string, min 10 characters, max 3,000 characters.
- **Success Response (200 OK):**
```json
{
  "success": true,
  "message": "Thank you for your message. We will be in touch shortly."
}
```
- **Validation Error Response (422 Unprocessable Entity):**
```json
{
  "success": false,
  "message": "Please check the highlighted fields.",
  "errors": {
    "email": [
      "The email field must be a valid email address."
    ],
    "message": [
      "The message field must be at least 10 characters."
    ]
  }
}
```

---

---

### 2.10 Admin Authentication (Phase 4)
- **POST** `/api/v1/auth/login` (Public): Authenticates administrative staff using email and password, issuing a Sanctum Bearer token.
- **GET** `/api/v1/auth/me` (`auth:sanctum`): Returns authenticated profile, assigned roles, and permissions.
- **POST** `/api/v1/auth/logout` (`auth:sanctum`): Revokes the current access token.

---

### 2.11 Menu Overview Analytics (Phase 4)
- **GET** `/api/v1/admin/menu/overview`
- **Auth:** `auth:sanctum` + `permission:menu.categories.view,menu.items.view,menu.manage`
- **Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "total_categories": 6,
    "active_categories": 5,
    "inactive_categories": 1,
    "total_items": 24,
    "available_items": 22,
    "unavailable_items": 2,
    "recent_items": [...]
  }
}
```

---

### 2.12 Menu Category Management (Phase 4)
- **GET** `/api/v1/admin/menu/categories` — List all categories with associated dish counts. Supports `?search=` and `?is_active=`.
- **POST** `/api/v1/admin/menu/categories` — Create a category. Validates unique slug, sort order, and name.
- **GET** `/api/v1/admin/menu/categories/{id}` — Retrieve specific category.
- **PUT** `/api/v1/admin/menu/categories/{id}` — Update category fields.
- **PATCH** `/api/v1/admin/menu/categories/{id}/status` — Toggle active status.
- **DELETE** `/api/v1/admin/menu/categories/{id}` — Safe delete. If dishes exist in category, returns `422 Unprocessable Entity`.

---

### 2.13 Menu Item Management (Phase 4)
- **GET** `/api/v1/admin/menu/items` — Paginated item list (`?page=`, `?per_page=`, `?category_id=`, `?is_available=`, `?search=`, `?sort_by=`, `?sort_dir=`).
- **POST** `/api/v1/admin/menu/items` — Create dish with multipart photograph upload. Enforces non-negative Ghana Cedi decimal pricing and valid image format (JPEG, PNG, WebP max 5MB).
- **GET** `/api/v1/admin/menu/items/{id}` — Retrieve specific dish.
- **POST / PUT** `/api/v1/admin/menu/items/{id}` — Update dish details, price, category, and replace photograph.
- **PATCH** `/api/v1/admin/menu/items/{id}/availability` — Fast toggle of availability status.
- **DELETE** `/api/v1/admin/menu/items/{id}/image` — Remove dish photograph from disk and database.
- **DELETE** `/api/v1/admin/menu/items/{id}` — Delete dish and cleans up photograph from filesystem.

---

## 3. Current Public Ordering and Payment Endpoints

The API below is mounted at `/api/v1`. Public frontend routes are described in [FEATURES-AND-OPERATIONS.md](FEATURES-AND-OPERATIONS.md).

### Public customer routes

- **GET** `/menu` — Public menu categories and available items.
- **GET** `/table-lookup/{token}` — Resolve an active table QR token.
- **POST** `/orders` — Create a dine-in or delivery order. Provide `order_type`, customer fields, and `items` (`menu_item_id`, `quantity`, optional `special_instructions`). Dine-in requires `restaurant_table_id` or `qr_token`; delivery requires `delivery_zone_id` and `delivery_address`.
  - `payment_method` accepts `cash` or `gateway` (cash is the default). Gateway checkout requires `customer_email` and returns `data.payment_checkout_url` after successful initialization.
  - Delivery may include `include_delivery_fee` as a boolean. The API computes the total and zone fee on the server.
- **GET** `/delivery-zones` — Active delivery zones for public checkout.
- **GET** `/content` — Saved public website and restaurant content, including branding URLs.
- **GET** `/payment-options` — Current online gateway availability and display name.
- **GET** `/payments/verify/{reference}` — Verify a provider payment and return the order number/payment status.
- **GET** `/orders/track/{orderNumber}` — Public order progress, item snapshots, payment summary, fulfillment point, and status history. The order number is an unguessable tracking capability; the response excludes customer contact data and internal IDs.
- **POST** `/chat/conversations` — Start or restore a visitor chat. Requires visitor token, name, and email; the browser receives a random capability token.
- **GET / POST** `/chat/conversations/{publicId}/messages` — Retrieve recent messages or send text/media. Send `X-Visitor-Token`; image/voice limit 5 MB, PDF limit 10 MB, video limit 20 MB.
- **POST** `/chat/conversations/{publicId}/typing` — Publish a short-lived typing indicator for the authorized visitor session.
- **GET** `/chat/conversations/{publicId}/messages/{messageId}/attachment` — Download an attachment after visitor-capability validation.
- **POST** `/payments/paystack/webhook` — Paystack signed webhook receiver. Configure this complete URL in the merchant dashboard: `https://<api-host>/api/v1/payments/paystack/webhook`.

### Authenticated administration routes

- **GET / PUT** `/admin/payment-gateway` — Read and update Paystack public/secret keys, enabled state, and default state. The response only reports whether a secret is configured; it never includes the secret.
- **GET / POST** `/admin/roles` — List roles or create a custom role.
- **PUT / DELETE** `/admin/roles/{id}` — Update/delete a role. Deletion is rejected for protected roles or roles still assigned to staff.
- **PUT** `/admin/roles/{id}/permissions` — Assign the role's permission set.
- **GET** `/admin/permissions` — List assignable permissions.
- **GET / PUT** `/admin/content` — Read/update website content and restaurant settings.
- **POST** `/admin/content/logo` — Upload the public restaurant logo (multipart field `logo`).
- **POST** `/admin/content/favicon` — Upload the public favicon (multipart field `favicon`).
- **POST** `/admin/content/gallery-image` — Upload a public gallery image.
- **DELETE** `/admin/tables/qr/{id}` — Delete a revoked QR record only; active QR records must be revoked first.
- **POST** `/auth/profile` — Update the signed-in user's profile details and/or password, subject to request validation.
- **GET** `/admin/chat/conversations` — Paginated chat inbox; result scope follows `chat.view_assigned` or `chat.view_all`.
- **GET** `/admin/chat/conversations/{id}` — Retrieve an authorized conversation and recent messages.
- **POST** `/admin/chat/conversations/{id}/messages` — Reply with text or an attachment when assigned and permitted.
- **PATCH** `/admin/chat/conversations/{id}/assignment` — Assign/reassign to an active user with `chat.receive`.
- **PATCH** `/admin/chat/conversations/{id}/close` and `/reopen` — Change conversation availability while preserving history.
- **DELETE** `/admin/chat/messages/{messageId}` — Soft-delete to a visible tombstone and create an audit record.
- **GET** `/admin/chat/conversations/{id}/audit` — Read chat audit entries with `chat.view_audit_logs`.

These admin routes are protected by Sanctum and route-specific permissions. For payment semantics, deployment settings, and verification steps, see [FEATURES-AND-OPERATIONS.md](FEATURES-AND-OPERATIONS.md).

## 4. Historical Phase Notes

Older phase documents describe planned functionality at the time they were written. For current routes and behavior, this endpoint reference and the route table are authoritative. Verify the deployed API with `php artisan route:list --path=api`.
