# Special Delicacies — Phase 6 Documentation: Customer Dine-In Ordering & Nginx Routing Repair

## 1. Phase 6 Scope
Phase 6 implements the complete digital customer dine-in ordering workflow for **Special Delicacies**, enabling restaurant diners to scan a physical table QR code, verify their table, explore the active culinary menu, configure items with special preparation instructions, manage a client-side dining cart, and submit authoritative dine-in orders with immediate kitchen confirmation.

In addition, Priority 1 resolved the direct-route 404 errors on Nginx for administrative routes (`/admin/tables`, `/admin/qr-codes`).

### Explicit Phase Boundaries (Strictly Observed)
- **Implemented:**
  - Nginx virtual host SPA routing repair and static fallback generation.
  - Digital dine-in ordering journey from QR scan (`/table/:token`) through order submission.
  - Server-side price calculation and item snapshotting inside transactional boundaries.
  - Idempotency key deduplication preventing duplicate submissions.
  - Interactive mobile-first customer ordering UI with category navigation, dish customizations, cart review, and celebratory order confirmation.
- **Excluded (Reserved for Phases 7–11):**
  - Waiter or Kitchen operational management dashboards (Phase 7).
  - Real-time WebSocket order tracking with Laravel Reverb / Echo (Phase 7).
  - Home delivery ordering and zone delivery fee calculations (Phase 8).
  - Online payment gateways or card processing.
  - Customer accounts or registration.

---

## 2. Priority 1: Nginx Routing Repair Audit

### 2.1 Root Cause Analysis
1. **Missing Route Fallbacks in Build Output:** In Phases 3 and 4, `scripts/postbuild.js` was introduced to generate static fallback directories in `dist/` (e.g., `dist/menu/index.html`, `dist/admin/items/index.html`) to ensure clean URL resolution under ServBay's Nginx configuration. When Phase 5 added `/admin/tables` and `/admin/qr-codes`, these routes were not added to `postbuild.js`.
2. **In-Memory Nginx Worker State:** The active Nginx master process on ServBay was initialized on 11/22/2026 under elevated Administrator privileges. When `try_files` was later added to `special-delicacies.local.conf` on disk, the in-memory process had not loaded the new configuration. Because un-elevated CLI processes cannot signal the elevated Nginx process (`Global\ngx_reload_*` Access Denied), requests for non-existent directories fell through to Nginx's static file handler, returning `404 Not Found`.

### 2.2 Implemented Corrections
1. **Virtual Host Configuration Hardening:**
   - Updated `C:\ServBay\etc\nginx\vhosts\special-delicacies.local.conf`.
   - Backed up original to `C:\ServBay\etc\nginx\vhosts\special-delicacies.local.conf.bak`.
   - Added static asset block ensuring non-existent JS/CSS/image assets return a genuine **HTTP 404** rather than falling back to HTML:
     ```nginx
     location ~* \.(?:css|js|jpg|jpeg|gif|png|ico|svg|woff|woff2|ttf|eot)$ {
         try_files $uri =404;
     }

     location / {
         try_files $uri $uri/ /index.html;
     }
     ```
   - Validated configuration syntax (`nginx -t` passed).
2. **Postbuild Defense-in-Depth:**
   - Updated `frontend/scripts/postbuild.js` to include `admin/tables`, `admin/qr-codes`, and `table`.
   - Executed `npm run build`, generating static fallback directories:
     - `dist/admin/tables/index.html`
     - `dist/admin/qr-codes/index.html`
     - `dist/table/index.html`
     - `dist/404.html`
3. **Verification Results:**
   - `https://special-delicacies.local/admin/tables` $\rightarrow$ **HTTP 200 OK** (serves application)
   - `https://special-delicacies.local/admin/qr-codes` $\rightarrow$ **HTTP 200 OK** (serves application)
   - `https://special-delicacies.local/assets/nonexistent.js` $\rightarrow$ **HTTP 404 Not Found** (genuine asset 404 preserved)
   - `https://api.special-delicacies.local/api/v1/health` $\rightarrow$ **HTTP 200 OK** (backend API routing completely untouched)

---

## 3. Customer Dine-In Ordering Architecture

### 3.1 Backend Endpoints & Authoritative Rules
- **Public Menu:** `GET /api/v1/menu`
  - Returns active categories and available menu items.
- **Table Lookup:** `GET /api/v1/table-lookup/{token}`
  - Validates 64-character hex token against SHA-256 hash. Returns `{ table_id, table_number, capacity }`.
- **Order Placement:** `POST /api/v1/orders`
  - Validates `qr_token` via `QrCodeService::resolveTableFromToken()`.
  - Rejects invalid, revoked, or deactivated QR tokens with HTTP 422.
  - Automatically associates order with `restaurant_table_id`.
  - Validates that every menu item is active and available.
  - **Ignores all client-submitted prices.** Calculates subtotal, delivery fee (`0.00` for dine-in), and total entirely server-side using authoritative database prices.
  - Snapshots `item_name`, `unit_price`, `quantity`, and `special_instructions` into `order_items`.
  - Records initial order state `status = 'pending'` and writes an audit record to `order_status_histories`.
  - Reuses atomic mutex locks and `idempotency_key` (via header `X-Idempotency-Key` or payload) to prevent duplicate submissions from double-clicks or retries.

### 3.2 Frontend Mobile Experience (`/table/:token`)
- **Step 1: Welcome & Table Identified:** Displays confirmed Table Number and seating capacity badge.
- **Step 2: Interactive Menu Browsing:** Category pill selector ("All Dishes", "Main Dishes", etc.), real-time dish search, responsive food cards with images and price in Ghana Cedis (`GH₵`), and quick "Add" / stepper buttons.
- **Step 3: Item Customization Modal:** Allows entering custom preparation instructions (e.g. "Mild pepper please", "No onions") per item.
- **Step 4: Floating Cart & Review:** Floating bottom bar displaying item count and subtotal. Comprehensive review sheet allowing quantity adjustments, removal, guest name entry, and kitchen notes.
- **Step 5: Order Confirmation:** Celebratory view displaying Order Number (`SD-YYYYMMDD-XXXX`), Table identification, preparation status, and itemized receipt.
- **Ergonomics & Themes:** Full Light, Dark, and System theme support with touch targets $\ge 44\text{px}$ and mobile safe-area padding.

---

## 4. Automated Verification Results

### 4.1 Backend Test Suite (`php artisan test`)
- **Total Tests Passed:** **122 passed** (451 assertions) in 27.40s.
- **Phase 6 Feature Tests (`Phase6DineInOrderTest`):** 15 passed (44 assertions) in 11.43s.
  - `test_valid_qr_token_resolves_table`
  - `test_invalid_qr_token_is_rejected`
  - `test_revoked_qr_token_is_rejected`
  - `test_inactive_table_qr_token_is_rejected`
  - `test_customer_can_submit_valid_dine_in_order_with_qr_token`
  - `test_server_calculates_correct_totals_and_ignores_client_prices`
  - `test_menu_item_snapshots_and_special_instructions_are_stored`
  - `test_initial_order_status_and_history_are_correct`
  - `test_unavailable_menu_item_is_rejected`
  - `test_invalid_quantities_are_rejected`
  - `test_empty_items_order_is_rejected`
  - `test_order_submission_with_invalid_qr_token_is_rejected`
  - `test_order_submission_with_revoked_qr_token_is_rejected`
  - `test_idempotency_prevents_duplicate_orders`
  - `test_failed_transaction_leaves_no_partial_records`

### 4.2 Frontend Verification
- **TypeScript (`npx tsc --noEmit`):** **0 errors**.
- **Linter (`npm run lint` / `oxlint`):** **0 errors** across all 39 source files.
- **Production Build (`npm run build`):** Built successfully in 3.55s.

### 4.3 Database Safety
- `special_delicacies_db` remains the only database accessed.
- `erm_db` was not modified.
- No destructive database operations or schema drops were executed.
- No secrets or credentials were exposed.
- `php artisan serve` was not used.
