# Special Delicacies — Phase 5 Documentation: Tables and QR Code Management

## 1. Phase 5 Scope
Phase 5 implements the complete restaurant table and QR code management system for **Special Delicacies**, enabling restaurant administrators to configure physical dining tables, securely issue cryptographically random QR codes, preview, download, and print table QR codes, and provide a minimal public QR verification landing page for diners.

Features strictly implemented:
- Administrative Table Management (CRUD, status toggling, safe deletion guard).
- Administrative QR Code Management (generation, regeneration, revocation, image preview, download, print layout).
- Public QR Resolution endpoint and standalone frontend verification landing page (`/table/:token`).
- Modern collapsible sidebar navigation layout for the administrative interface (`AdminLayout`).
- Granular Role-Based Access Control (RBAC) protecting all table and QR administrative endpoints.

Features explicitly excluded (strictly reserved for Phase 6+):
- Customer food browsing via QR.
- Customer shopping cart, order placement, or checkout.
- Waiter order management or kitchen display system.
- Customer payment gateway processing.

---

## 2. Database Tables and Relationships
Phase 5 reuses and builds upon the existing database schema without destructive changes:

### `restaurant_tables`
- `id` (bigint, unsigned, primary key)
- `table_number` (string 20, unique)
- `capacity` (unsigned smallint, default 4)
- `is_active` (boolean, default true)
- `created_at`, `updated_at` (timestamps)
- Index on `['is_active', 'table_number']`

### `table_qr_codes`
- `id` (bigint, unsigned, primary key)
- `restaurant_table_id` (foreign key to `restaurant_tables`, restrict on delete per Phase 2 migration `2026_11_22_233001_update_foreign_key_deletion_rules.php`)
- `token_hash` (string 64, unique, stores SHA-256 hash of plain token)
- `is_active` (boolean, default true)
- `generated_by` (foreign key to `users`, nullable, null on delete)
- `revoked_at` (timestamp, nullable)
- `created_at`, `updated_at` (timestamps)
- Index on `['restaurant_table_id', 'is_active']`

### Entity Relationships
- `RestaurantTable` has many `TableQrCode` (`qrCodes()`).
- `RestaurantTable` has one active `TableQrCode` (`activeQrCode()`).
- `RestaurantTable` has many `Order` (`orders()`).
- Safe deletion prevents dropping a table if `$table->orders()->exists() || $table->qrCodes()->exists()`, returning HTTP 422 to protect historical orders and QR credentials without relying on uncaught database restriction exceptions.
- MySQL foreign key constraint `DELETE_RULE = RESTRICT` on both `orders_restaurant_table_id_foreign` and `table_qr_codes_restaurant_table_id_foreign` guarantees database-level integrity against orphaned records.

---

## 3. Table Management Functionality
Administrators can:
1. **List Tables**: Paginated view (15/page) with summary statistics (Total, Active, Inactive, With QR).
2. **Search & Filter**: Real-time search by `table_number` and filter by active/inactive status.
3. **Create Table**: Modal dialog with validation (`table_number` unique, `capacity` 1–50, `is_active`).
4. **Edit Table**: Modify table number and seating capacity with route-aware uniqueness validation.
5. **Toggle Status**: Instant activation/deactivation via `PATCH /api/v1/admin/tables/{id}/status`.
6. **Safe Deletion**: Deletion blocked with HTTP 422 if orders are attached to the table.

---

## 4. QR Generation and Token Security
- **Cryptographically Random Tokens**: Generated using `bin2hex(random_bytes(32))` (64 hexadecimal characters, 256 bits of entropy).
- **One-Way Hash Storage**: Raw tokens are **never stored** in the database. Only the SHA-256 hash (`hash('sha256', $plainToken)`) is persisted in `table_qr_codes.token_hash`.
- **Transactional Atomic Regeneration**: When generating or regenerating a QR code, existing active QR codes for that table are atomically revoked (`is_active = false, revoked_at = now()`) inside a DB transaction before the new code is committed.
- **Revocation**: Marks active QR code as inactive and sets `revoked_at`. Revoked codes cannot be resolved.
- **Frontend QR Rendering**: The plain token is returned to the administrator's browser upon generation/regeneration, enabling immediate high-contrast SVG/PNG QR generation (`api.qrserver.com`) for downloading and printing.

---

## 5. Public QR Resolution
- **Endpoint**: `GET /api/v1/table-lookup/{token}`
- **Security**:
  - Validates format (must be 64-char hex string).
  - Computes SHA-256 of the token.
  - Queries active, non-revoked record matching `token_hash`.
  - Verifies that associated `restaurant_table` is active (`is_active = true`).
  - Returns minimal payload: `{ table_id, table_number, capacity }`.
  - Never leaks `token_hash`, admin metadata, customer info, or order state.
  - Returns HTTP 404 for invalid, expired, revoked, or inactive tables.
- **Frontend Landing Page**: `/table/:token`
  - Minimalist, responsive mobile-first landing view.
  - Validates token against backend API.
  - Welcomes customer and confirms identified table number and capacity.
  - Clear error state instructing diner to ask restaurant staff if invalid or revoked.

---

## 6. API Endpoints

### Administrative Table Management (Sanctum Protected)
| Method | Endpoint | Permission | Description |
|---|---|---|---|
| `GET` | `/api/v1/admin/tables` | `tables.view,tables.manage` | List tables with stats & pagination |
| `POST` | `/api/v1/admin/tables` | `tables.create,tables.manage` | Create a new table |
| `GET` | `/api/v1/admin/tables/{id}` | `tables.view,tables.manage` | View table details |
| `PUT` | `/api/v1/admin/tables/{id}` | `tables.update,tables.manage` | Update table details |
| `PATCH` | `/api/v1/admin/tables/{id}/status` | `tables.update,tables.manage` | Toggle table active status |
| `DELETE` | `/api/v1/admin/tables/{id}` | `tables.delete,tables.manage` | Safely delete an empty table |

### Administrative QR Code Management (Sanctum Protected)
| Method | Endpoint | Permission | Description |
|---|---|---|---|
| `GET` | `/api/v1/admin/tables/qr` | `tables.qr.view,tables.manage` | List QR records (hash excluded) |
| `GET` | `/api/v1/admin/tables/{tableId}/qr` | `tables.qr.view,tables.manage` | Get active QR metadata for table |
| `POST` | `/api/v1/admin/tables/{tableId}/qr/generate` | `tables.qr.generate,tables.manage` | Generate new QR (returns plain token) |
| `POST` | `/api/v1/admin/tables/{tableId}/qr/regenerate` | `tables.qr.generate,tables.manage` | Regenerate QR (revokes old code) |
| `DELETE` | `/api/v1/admin/tables/{tableId}/qr/revoke` | `tables.qr.revoke,tables.manage` | Revoke active QR code |

### Public Resolution
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/table-lookup/{token}` | Public | Resolve plain QR token to table info |

---

## 7. Role-Based Access Control (RBAC)
Granular permissions added to `RoleAndPermissionSeeder`:
- `tables.create`
- `tables.update`
- `tables.delete`
- `tables.qr.view`
- `tables.qr.generate`
- `tables.qr.revoke`

### Role Assignments
- **Administrator**: Full access to all table and QR operations.
- **Waiter/Waitress**: Only has `tables.view` (can view seating layout for service). Cannot create, update, delete tables, or generate/revoke QR codes.
- **Kitchen Staff**: No table or QR permissions.
- **Delivery Staff**: No table or QR permissions.

---

## 8. Admin Sidebar Navigation and Responsive Behaviour
The administrative interface now features a modern, responsive collapsible sidebar layout:
- **Expanded Desktop State (256px)**: Displays brand header, structured navigation sections (*Main*, *Menu Management*, *Restaurant Management*), icon + label items, and footer profile/logout.
- **Collapsed Desktop State (64px)**: Compact icon-only view with interactive tooltips and active indicator pills.
- **Persistence**: User preference stored in `localStorage` under `sd_admin_sidebar_collapsed`.
- **Mobile Drawer**: Slide-out navigation drawer with smooth animations and backdrop overlay. Automatically closes upon link navigation or backdrop tap. Meets min 44px touch targets.
- **Theme Support**: Seamless compatibility with Light and Dark themes according to the approved Special Delicacies design tokens (Espresso, Copper, Gold, Sage, Cream).

---

## 9. Testing Performed
- **Backend Tests**: 24 feature tests in `Phase5TableManagementTest.php` passing 104 assertions.
- **Full Backend Suite**: 107 tests passing 407 assertions with 0 failures across the entire system.
- **Frontend Verification**:
  - `npx tsc --noEmit`: 0 errors.
  - `npm run build`: Production build succeeded.
  - `npm run lint`: 0 errors.
- **Responsive Viewport Verification**:
  - Mobile (360×800, 390×844, 430×932): Slide-out drawer, card view for tables, responsive QR modal.
  - Tablet (768×1024, 1024×768): Fluid drawer / sidebar adaptation.
  - Desktop (1440×900): Collapsible sidebar (256px / 64px) with persistent layout transitions.

---

## 10. Known Limitations
- QR codes cannot be re-rendered from the database at arbitrary future dates because the plain token is never stored. Administrators can view QR status and metadata at any time, or click "Regenerate" to produce a fresh scannable QR code and download/print it.
- Public resolution exposes only `table_id`, `table_number`, and `capacity`. Dine-in ordering is not yet enabled.

---

## 11. Security Considerations
- SHA-256 hashed token lookup eliminates token enumeration or database leakage risks.
- Route collision between `/admin/tables/qr` and `/admin/tables/{id}` is avoided by defining the literal `/qr` route first.
- Foreign key constraints prevent orphaned QR codes, and application-level checks protect tables with active or historical orders from accidental deletion.

---

## 12. Phase 6 Integration Notes
- Phase 6 (Customer Dine-in Ordering) will extend `/table/:token` to mount the live interactive menu and table cart.
- Orders submitted by customers will attach `restaurant_table_id` validated through the active QR token session.
