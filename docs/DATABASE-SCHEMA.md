# Special Delicacies - Database Documentation

## 1. Database Information

- **Database Engine:** MySQL 8.4
- **Database Name:** `special_delicacies_db`
- **Character Set:** `utf8mb4`
- **Collation:** `utf8mb4_0900_ai_ci`
- **Isolation:** Dedicated schema (`erm_db` remains separate and untouched)
- **Approved Operating Currency:** Ghana Cedi (`GHS` / `GH₵`)

---

## 2. Current Implementation Status

Phase 2 — Database and Backend Foundation — has been implemented, migrated, seeded, audited, and verified.
The application schema contains **15 tables** (the extended Laravel `users` table plus 14 business and relational tables):

1. `users` (extended)
2. `roles`
3. `permissions`
4. `role_user` (pivot)
5. `permission_role` (pivot)
6. `menu_categories`
7. `menu_items`
8. `restaurant_tables`
9. `table_qr_codes`
10. `delivery_zones`
11. `orders`
12. `order_items`
13. `order_status_histories`
14. `restaurant_settings`
15. `payment_gateways`

Framework support tables (`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `sessions`, `personal_access_tokens`, and `migrations`) remain intact.

---

## 3. Schema Definitions

### 3.1 Users Table (`users`)
Laravel authentication table extended with staff phone and active-status controls.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Unique user identifier |
| `name` | VARCHAR(255) | NOT NULL | User / staff full name |
| `email` | VARCHAR(255) | UNIQUE, NOT NULL | Login email address |
| `phone` | VARCHAR(30) | UNIQUE, NULLABLE | Contact telephone number |
| `is_active` | BOOLEAN | NOT NULL, DEFAULT true | Account active/deactivated flag |
| `email_verified_at` | TIMESTAMP | NULLABLE | Verification timestamp |
| `password` | VARCHAR(255) | NOT NULL | Hashed password |
| `remember_token` | VARCHAR(100) | NULLABLE | Remember-me token |
| `created_at` | TIMESTAMP | NULLABLE | Creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

### 3.2 Roles Table (`roles`)
Approved staff roles for role-based access control.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Role identifier |
| `name` | VARCHAR(100) | NOT NULL | Human-readable role name |
| `slug` | VARCHAR(100) | UNIQUE, NOT NULL | Machine-readable role identifier |
| `description` | TEXT | NULLABLE | Role responsibility summary |
| `created_at` | TIMESTAMP | NULLABLE | Creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Seeded roles: `super-admin`, `waiter-waitress`, `kitchen-staff`, `delivery-staff`. Super Admin is the primary full-control role; its permission access is intrinsic and cannot be removed through role editing.

### 3.3 Permissions Table (`permissions`)
Granular permissions governing restaurant capabilities.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Permission identifier |
| `name` | VARCHAR(100) | NOT NULL | Human-readable permission name |
| `slug` | VARCHAR(100) | UNIQUE, NOT NULL | Machine-readable permission slug |
| `description` | TEXT | NULLABLE | Permission description |
| `created_at` | TIMESTAMP | NULLABLE | Creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

### 3.4 Role User Pivot Table (`role_user`)
Associates users with roles.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Pivot record identifier |
| `user_id` | BIGINT UNSIGNED | FOREIGN KEY -> `users(id)`, CASCADE | Assigned user |
| `role_id` | BIGINT UNSIGNED | FOREIGN KEY -> `roles(id)`, CASCADE | Assigned role |
| `created_at` | TIMESTAMP | NULLABLE | Assignment timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Unique constraint: `(user_id, role_id)`.

### 3.5 Permission Role Pivot Table (`permission_role`)
Associates permissions with roles.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Pivot record identifier |
| `role_id` | BIGINT UNSIGNED | FOREIGN KEY -> `roles(id)`, CASCADE | Target role |
| `permission_id` | BIGINT UNSIGNED | FOREIGN KEY -> `permissions(id)`, CASCADE | Assigned permission |
| `created_at` | TIMESTAMP | NULLABLE | Assignment timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Unique constraint: `(permission_id, role_id)`.

### 3.6 Menu Categories Table (`menu_categories`)
Logical categorisation for food and beverage offerings.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Category identifier |
| `name` | VARCHAR(100) | NOT NULL | Category name |
| `slug` | VARCHAR(100) | UNIQUE, NOT NULL | URL-friendly slug |
| `description` | TEXT | NULLABLE | Category description |
| `sort_order` | INT | NOT NULL, DEFAULT 0 | Display sequence order |
| `is_active` | BOOLEAN | NOT NULL, DEFAULT true | Category display flag |
| `created_at` | TIMESTAMP | NULLABLE | Creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Index: `(is_active, sort_order)`.

### 3.7 Menu Items Table (`menu_items`)
Individual menu items offered by the restaurant.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Menu item identifier |
| `category_id` | BIGINT UNSIGNED | FOREIGN KEY -> `menu_categories(id)`, RESTRICT | Associated category |
| `name` | VARCHAR(150) | NOT NULL | Item name |
| `slug` | VARCHAR(150) | UNIQUE, NOT NULL | URL-friendly slug |
| `description` | TEXT | NULLABLE | Item description |
| `price` | DECIMAL(10, 2) | NOT NULL | Current active price (GHS) |
| `image_path` | VARCHAR(255) | NULLABLE | Storage path for item photo |
| `is_available` | BOOLEAN | NOT NULL, DEFAULT true | Live ordering availability |
| `sort_order` | INT | NOT NULL, DEFAULT 0 | Display sequence order |
| `created_at` | TIMESTAMP | NULLABLE | Creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Indexes: `(category_id, is_available)`, `(is_available, sort_order)`.  
Constraint: Foreign key constraint `RESTRICT` on category delete prevents accidental destruction of menu items.

### 3.8 Restaurant Tables Table (`restaurant_tables`)
Physical dining tables located in the restaurant.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Internal table identifier |
| `table_number` | VARCHAR(20) | UNIQUE, NOT NULL | Public table identifier (e.g. 'T-01') |
| `capacity` | SMALLINT UNSIGNED | NOT NULL, DEFAULT 4 | Seating capacity |
| `is_active` | BOOLEAN | NOT NULL, DEFAULT true | Table operational flag |
| `created_at` | TIMESTAMP | NULLABLE | Creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Index: `(is_active, table_number)`.

### 3.9 Table QR Codes Table (`table_qr_codes`)
Cryptographically secure QR identity mappings for physical tables.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | QR record identifier |
| `restaurant_table_id` | BIGINT UNSIGNED | FOREIGN KEY -> `restaurant_tables(id)`, RESTRICT | Physical table reference |
| `token_hash` | VARCHAR(64) | UNIQUE, NOT NULL | SHA-256 hash of plain QR token |
| `is_active` | BOOLEAN | NOT NULL, DEFAULT true | Current validity status |
| `generated_by` | BIGINT UNSIGNED | FOREIGN KEY -> `users(id)`, NULLABLE, SET NULL | Staff member who generated token |
| `revoked_at` | TIMESTAMP | NULLABLE | Deactivation / revocation timestamp |
| `created_at` | TIMESTAMP | NULLABLE | Creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Index: `(restaurant_table_id, is_active)`.  
Security guarantee: Only SHA-256 token hashes are stored in the database. Raw tokens are never persisted. Transactional generation ensures only one active token exists per table.  
Deletion protection: `RESTRICT` constraint prevents deleting physical tables that have historical QR token credentials.

### 3.10 Delivery Zones Table (`delivery_zones`)
Configured geographical delivery areas and delivery pricing.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Delivery zone identifier |
| `name` | VARCHAR(100) | UNIQUE, NOT NULL | Zone or neighborhood name |
| `delivery_fee` | DECIMAL(10, 2) | NOT NULL, DEFAULT 0.00 | Fee charged for delivery to zone (GHS) |
| `is_active` | BOOLEAN | NOT NULL, DEFAULT true | Zone servicing status |
| `created_at` | TIMESTAMP | NULLABLE | Creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Index: `(is_active, name)`.

### 3.11 Orders Table (`orders`)
Master records for dine-in and home-delivery orders with duplicate-submission protection.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Internal order ID |
| `order_number` | VARCHAR(32) | UNIQUE, NOT NULL | Customer & tracking order reference |
| `idempotency_key` | VARCHAR(64) | UNIQUE, NULLABLE | Idempotency token preventing duplicate submissions |
| `order_type` | ENUM | NOT NULL (`dine_in`, `delivery`) | Order fulfillment method |
| `restaurant_table_id` | BIGINT UNSIGNED | FOREIGN KEY -> `restaurant_tables(id)`, NULLABLE, RESTRICT | Associated dine-in table |
| `delivery_zone_id` | BIGINT UNSIGNED | FOREIGN KEY -> `delivery_zones(id)`, NULLABLE, RESTRICT | Associated delivery zone |
| `customer_name` | VARCHAR(100) | NOT NULL | Customer name |
| `customer_phone` | VARCHAR(30) | NOT NULL | Contact telephone number |
| `delivery_address` | TEXT | NULLABLE | Delivery destination address |
| `customer_notes` | TEXT | NULLABLE | Order notes or dietary requests |
| `status` | VARCHAR(30) | NOT NULL, DEFAULT 'pending' | Operational lifecycle status |
| `subtotal` | DECIMAL(10, 2) | NOT NULL | Sum of item line totals (GHS) |
| `delivery_fee` | DECIMAL(10, 2) | NOT NULL, DEFAULT 0.00 | Delivery fee snapshot (GHS) |
| `total` | DECIMAL(10, 2) | NOT NULL | Total order amount (`subtotal + delivery_fee`) |
| `waiter_id` | BIGINT UNSIGNED | FOREIGN KEY -> `users(id)`, NULLABLE, SET NULL | Assigned waiter/waitress |
| `kitchen_staff_id` | BIGINT UNSIGNED | FOREIGN KEY -> `users(id)`, NULLABLE, SET NULL | Assigned kitchen staff |
| `delivery_staff_id` | BIGINT UNSIGNED | FOREIGN KEY -> `users(id)`, NULLABLE, SET NULL | Assigned delivery rider |
| `created_at` | TIMESTAMP | NULLABLE | Order placement timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Indexes: `(order_type, status)`, `(status, created_at)`.

Later migrations add nullable `customer_email`, unique nullable `payment_reference`, and nullable `gateway_checkout_url`, as well as `payment_method` and `payment_status` (see the payment fields migration). The reference and checkout URL are hidden from ordinary serialized order responses. Online payment state is updated only after server-side provider verification or a valid signed webhook.

### 3.12 Order Items Table (`order_items`)
Line items preserving historical order item details and pricing snapshots.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Order item identifier |
| `order_id` | BIGINT UNSIGNED | FOREIGN KEY -> `orders(id)`, RESTRICT | Parent order (protected against cascade deletion) |
| `menu_item_id` | BIGINT UNSIGNED | FOREIGN KEY -> `menu_items(id)`, NULLABLE, SET NULL | Menu item reference |
| `item_name` | VARCHAR(150) | NOT NULL | Snapshot of item name at purchase |
| `unit_price` | DECIMAL(10, 2) | NOT NULL | Snapshot of unit price at purchase (GHS) |
| `quantity` | INT UNSIGNED | NOT NULL | Ordered item quantity |
| `special_instructions` | TEXT | NULLABLE | Line-item preparation note |
| `line_total` | DECIMAL(10, 2) | NOT NULL | Snapshot line total (`quantity * unit_price`) |
| `created_at` | TIMESTAMP | NULLABLE | Item record creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Index: `(order_id)`.  
Deletion protection: `RESTRICT` constraint prevents deleting an order record while item snapshots exist.

### 3.13 Order Status Histories Table (`order_status_histories`)
Immutable operational audit trail of order lifecycle transitions.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Audit entry identifier |
| `order_id` | BIGINT UNSIGNED | FOREIGN KEY -> `orders(id)`, RESTRICT | Target order (protected against cascade deletion) |
| `previous_status` | VARCHAR(30) | NULLABLE | Status before transition |
| `new_status` | VARCHAR(30) | NOT NULL | Status after transition |
| `changed_by` | BIGINT UNSIGNED | FOREIGN KEY -> `users(id)`, NULLABLE, SET NULL | Staff member executing change |
| `note` | TEXT | NULLABLE | Operational change note |
| `created_at` | TIMESTAMP | NULLABLE | Transition timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Index: `(order_id, created_at)`.  
Deletion protection: `RESTRICT` constraint prevents deleting an order record while status history audit trails exist.

### 3.14 Restaurant Settings Table (`restaurant_settings`)
System-wide configuration key-value storage.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Setting entry identifier |
| `key` | VARCHAR(100) | UNIQUE, NOT NULL | Setting key name |
| `value` | TEXT | NULLABLE | Configured value |
| `group` | VARCHAR(50) | NOT NULL, DEFAULT 'general' | Setting grouping namespace |
| `created_at` | TIMESTAMP | NULLABLE | Setting creation timestamp |
| `updated_at` | TIMESTAMP | NULLABLE | Last update timestamp |

Index: `(group, key)`.  
Configured defaults: Currency is `GHS`, Currency symbol is `GH₵`.

### 3.15 Payment Gateways Table (`payment_gateways`)
Stores provider configuration for online checkout. Supported providers are Paystack, Hubtel, and Kora (Korapay); admins can manage multiple gateway records from Settings → Payment Gateways.

| Column | Type | Attributes | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Gateway record identifier |
| `provider` | VARCHAR | NOT NULL, INDEXED | Provider slug (`paystack`, `hubtel`, or `korapay`); multiple records are allowed |
| `display_name` | VARCHAR | NOT NULL | Admin-facing provider name |
| `public_key` | VARCHAR | NULLABLE | Provider public key or public identifier |
| `secret_key` | TEXT | NULLABLE | Encrypted Laravel model cast; never returned through the API |
| `configuration` | JSON | NULLABLE | Provider-specific non-secret settings |
| `is_enabled` | BOOLEAN | DEFAULT false | Allows new online checkout sessions |
| `is_default` | BOOLEAN | DEFAULT false | Selects this enabled online checkout provider |
| timestamps | TIMESTAMP | NULLABLE | Record creation and update times |

Keep `APP_KEY` unchanged across deployments and retain a secure backup: Laravel uses it to decrypt the saved secret key. Orders reference the gateway used for checkout through nullable `orders.payment_gateway_id`; existing checkouts remain verifiable if the gateway is disabled after checkout begins. Gateway deletion is blocked while pending online orders depend on that record.

---

## 4. Key Relationships and Restrictive Deletion Strategy

- **User <-> Role:** Many-to-many through `role_user`.
- **Role <-> Permission:** Many-to-many through `permission_role`.
- **MenuCategory -> MenuItem:** One-to-many (`menu_categories.id -> menu_items.category_id`). `RESTRICT` prevents deleting categories with existing items.
- **RestaurantTable -> TableQrCode:** One-to-many. `RESTRICT` prevents deleting physical tables with existing QR history.
- **RestaurantTable -> Order:** One-to-many. `RESTRICT` prevents deleting physical tables with existing order history.
- **DeliveryZone -> Order:** One-to-many. `RESTRICT` prevents deleting delivery zones with existing order history.
- **Order -> OrderItem:** One-to-many. `RESTRICT` prevents deleting orders with existing item snapshots.
- **Order -> OrderStatusHistory:** One-to-many. `RESTRICT` prevents deleting orders with existing status audit history.
- **User -> Order (Staff Assignments):** Waiter, Kitchen Staff, Delivery Staff assigned via foreign keys with `nullOnDelete`.

---

## 5. Data Integrity and Security Principles

1. **Decimal Monetary Storage:** All financial amounts (`price`, `subtotal`, `delivery_fee`, `total`, `unit_price`, `line_total`) use `DECIMAL(10, 2)`.
2. **Approved Currency Standards:** Currency is standard Ghana Cedi (`GHS` / `GH₵`).
3. **Server Price Authority:** Client-submitted prices are ignored. Prices are resolved directly from `menu_items` in the database.
4. **Historical Snapshots:** Order items freeze `item_name`, `unit_price`, and `line_total` at the time of order submission.
5. **Separate Delivery Fees:** Subtotal and delivery fee are recorded separately.
6. **QR Token Cryptography:** Tokens are generated using 64-character cryptographic hex strings (`bin2hex(random_bytes(32))`). Only the SHA-256 hash is stored in the database.
7. **Idempotency Protection:** Double-clicking, network retries, and rapid resubmissions are deduplicated via explicit/derived idempotency keys and cache mutex locks.
8. **Controlled Status Transitions:** Orders strictly follow defined state machines and log every change to `order_status_histories`.
9. **Safe Rollback:** All migration `down()` methods safely revert changes in exact reverse dependency order.
