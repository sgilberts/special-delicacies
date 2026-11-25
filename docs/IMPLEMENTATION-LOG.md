# Special Delicacies - Implementation Log

## Project Development Record

This document records implementation work and verified outcomes. Planned features are never described as completed.

---

## Follow-up: Public Ordering, Payments, Branding, Roles, and Operations

**Status:** Implemented and verified  
**Completion Date:** 2026-11-24

### Scope and behavior

- Corrected table QR output to `/table/?token=<token>` and ensured public table ordering receives the token from the query string.
- Added Paystack hosted checkout configuration in Admin Settings. The server initializes and verifies GHS transactions, validates signed webhooks, and keeps the secret key encrypted in the database. Public ordering offers cash or online payment; delivery checkout exposes active zones and the delivery-fee inclusion choice.
- Made public restaurant content, restaurant logo, and favicon reflect saved database settings. Uploaded assets use public storage URLs resolved against the API host.
- Added custom role CRUD and permission assignment, deletion for revoked QR records, user profile editing/photo/password changes, password visibility controls, and modal confirmations for destructive/logout actions.
- Applied global theme tokens to public view colors and button contrast. Added/updated Reverb monitor guidance and kept broadcast configuration dependent on the selected server/hosted broadcaster.

### Verification

- Backend suite: **272 passed (1,033 assertions)**.
- Frontend production build: completed successfully. Vite reports that one generated JavaScript chunk exceeds its 500 kB advisory threshold.
- Live Paystack payment was not run; it requires merchant credentials and an externally reachable webhook. Reverb operation likewise depends on deployment WebSocket/TLS/process configuration. Follow [FEATURES-AND-OPERATIONS.md](FEATURES-AND-OPERATIONS.md), [PAYMENT-GATEWAY-SETUP.md](PAYMENT-GATEWAY-SETUP.md), and [broadcasting-deployment.md](broadcasting-deployment.md).

---

## Phase 1 - Project Foundation

**Status:** COMPLETED — Phase 1 successfully accepted.

### Summary of Phase 1 Deliverables
- Project directories (`frontend/`, `backend/`, `docs/`, `README.md`).
- ServBay local development environment with PHP 8.4, Composer 2.11, Node.js 26.13, npm 11.20, and MySQL 8.4.
- React 19 + TypeScript + Vite frontend with Tailwind CSS and universal theme switching (Light/Dark/System).
- Laravel 13 backend connected to `special_delicacies_db`.
- Laravel Sanctum authentication package installed.
- Versioned API prefix `/api/v1/` with verified health endpoint (`GET /api/v1/health`) and protected user endpoint (`GET /api/v1/user`).
- Frontend-to-backend HTTPS communication verified.

---

## Phase 2 - Database and Backend Foundation

**Status:** COMPLETED & AUDITED.

### 1. Database Implementation
The approved schema contains **14 tables in total**, including the pre-existing `users` table.

#### Completed Migrations
1. `2026_11_22_224329_add_phone_and_is_active_to_users_table`:
   - Added `phone` (VARCHAR 30, unique, nullable) and `is_active` (BOOLEAN, default true) to `users`.
2. `2026_11_22_230001_create_roles_and_permissions_tables`:
   - Created `roles`, `permissions`, `role_user` (pivot), and `permission_role` (pivot).
3. `2026_11_22_230002_create_menu_categories_and_items_tables`:
   - Created `menu_categories` and `menu_items` with `DECIMAL(10, 2)` pricing, availability flags, and sort orders.
4. `2026_11_22_230003_create_restaurant_tables_and_qr_codes_tables`:
   - Created `restaurant_tables` and `table_qr_codes` (with SHA-256 token hash storage, active flag, generator ID, and revocation timestamp).
5. `2026_11_22_230004_create_delivery_zones_table`:
   - Created `delivery_zones` with zone names and `DECIMAL(10, 2)` delivery fees.
6. `2026_11_22_230005_create_orders_tables`:
   - Created `orders`, `order_items` (preserving historical snapshots of item names, unit prices, and line totals), and `order_status_histories`.
7. `2026_11_22_230006_create_restaurant_settings_table`:
   - Created `restaurant_settings` for system key-value configuration.
8. `2026_11_22_233001_update_foreign_key_deletion_rules` (Audit Migration):
   - Converted `table_qr_codes -> restaurant_tables`, `order_items -> orders`, and `order_status_histories -> orders` from `CASCADE` to `RESTRICT` to prevent accidental destructive deletion of historical records and audit trails.
9. `2026_11_22_233002_add_idempotency_key_to_orders_table` (Audit Migration):
   - Added unique nullable `idempotency_key` (VARCHAR 64) to `orders` table.

All migration `down()` methods were verified with safe rollback testing.
Isolation verified: `erm_db` was never accessed or modified.

### 2. Eloquent Models and Relationships
Implemented the following models with fillable protections, relationship methods, scopes, and casts:
- `App\Models\User`: Added `HasApiTokens`, `roles()`, `hasRole()`, `hasPermission()`, `hasAnyPermission()`, eager loading protections.
- `App\Models\Role`: `users()`, `permissions()`, `givePermissionTo()`.
- `App\Models\Permission`: `roles()`.
- `App\Models\MenuCategory`: `items()`, `availableItems()`, `scopeActive()`, `scopeOrdered()`.
- `App\Models\MenuItem`: `category()`, `scopeAvailable()`, `scopeOrdered()`, cast `price` to `decimal:2`.
- `App\Models\RestaurantTable`: `qrCodes()`, `activeQrCode()`, `orders()`, `scopeActive()`.
- `App\Models\TableQrCode`: `table()`, `generator()`, `scopeActive()`.
- `App\Models\DeliveryZone`: `orders()`, `scopeActive()`, cast `delivery_fee` to `decimal:2`.
- `App\Models\Order`: `items()`, `statusHistories()`, `table()`, `deliveryZone()`, `waiter()`, `kitchenStaff()`, `deliveryStaff()`, `canTransitionTo()`, `idempotency_key`.
- `App\Models\OrderItem`: `order()`, `menuItem()`, casts for `unit_price` and `line_total`.
- `App\Models\OrderStatusHistory`: `order()`, `user()`.
- `App\Models\RestaurantSetting`: `getValue()`, `setValue()`, `scopeGroup()`.

### 3. Business Services and Security
- `App\Services\QrCodeService`:
  - Cryptographically secure 64-character token generation (`bin2hex(random_bytes(32))`).
  - SHA-256 hashing for storage; raw token is never persisted in database.
  - Enforces single-active-token rule per table transactionally.
  - Resolves table from plain token without exposing internal IDs.
  - Token revocation support.
- `App\Services\OrderService`:
  - Transactional order creation.
  - Server-side price authority: queries DB `MenuItem` prices, completely ignoring client-submitted prices.
  - Validates menu item availability (`is_available = true`).
  - Preserves historical snapshots: item name, unit price, quantity, and line total.
  - Keeps delivery fee separate from subtotal: `total = subtotal + delivery_fee`.
  - State machine lifecycle transition enforcement and audit logging in `order_status_histories`.
  - Duplicate-order protection: handles explicit idempotency keys (`idempotency_key` or `X-Idempotency-Key` header), computes deterministic fingerprints for payload deduplication, utilizes atomic cache locks (`Cache::lock`), and catches database-level unique constraint collisions gracefully.

### 4. RBAC and Middleware Enforcement
- Four approved roles seeded:
  - Administrator (`administrator`): Full access.
  - Waiter/Waitress (`waiter-waitress`): Orders and menu view; strictly restricted from editing menu prices and settings.
  - Kitchen Staff (`kitchen-staff`): Orders and menu view; strictly restricted from accessing administrative settings and price modification.
  - Delivery Staff (`delivery-staff`): Orders and delivery zones view.
- Middleware registered in `bootstrap/app.php`:
  - `EnsureUserHasPermission` (`permission:...`) with robust multi-argument / delimiter parsing.
  - `EnsureUserHasRole` (`role:...`) with robust multi-argument / delimiter parsing.
- Deactivated accounts (`is_active = false`) are automatically rejected with 403 Forbidden.

### 5. Corrective Audit Deliverables
1. **Currency Correction:**
   - Corrected `RestaurantSettingSeeder` currency from USD to Ghana Cedi: `GHS` and symbol `GH₵`.
   - Verified through database re-seeding and automated tests.
2. **Foreign-Key Deletion Rules:**
   - Replaced `cascadeOnDelete` with `restrictOnDelete` on `table_qr_codes -> restaurant_tables`, `order_items -> orders`, and `order_status_histories -> orders`.
   - Added comprehensive foreign-key deletion restriction tests in `Tests\Feature\ForeignKeyRestrictionTest`.
3. **Duplicate-Order Protection:**
   - Added `idempotency_key` unique column to `orders`.
   - Updated `OrderService` and `CreateOrderRequest` to prevent duplicates from double-clicking, network retries, and rapid resubmission.
   - Added automated tests in `Tests\Feature\DuplicateOrderTest`.
4. **Security & Route Enhancements:**
   - Hardened `User` model relationship loading (`loadMissing('roles.permissions')`).
   - Added tests ensuring Waiters and Delivery Staff cannot access `/admin/settings`.

### 6. Automated Testing Verification
Total test suite: **49 passed tests, 142 assertions** (Execution time: ~9.3s):
- `Tests\Feature\DeliveryZoneTest`: 3 tests passing.
- `Tests\Feature\DuplicateOrderTest`: 3 tests passing.
- `Tests\Feature\ForeignKeyRestrictionTest`: 5 tests passing.
- `Tests\Feature\MenuTest`: 3 tests passing.
- `Tests\Feature\OrderTest`: 8 tests passing.
- `Tests\Feature\RbacTest`: 6 tests passing.
- `Tests\Feature\RestaurantSettingTest`: 3 tests passing.
- `Tests\Feature\SecurityAndApiTest`: 10 tests passing.
- `Tests\Feature\TableQrTest`: 6 tests passing.
- Unit & example tests: 2 tests passing.

---

## Phase 3 - Public Restaurant Website

**Status:** COMPLETED & AUDITED.

### 1. Frontend Architecture & Package Setup
- **Dependencies Installed:**
  - `react-router-dom`: Client-side routing and layout management.
  - `lucide-react`: Semantic icon system.
  - `react-hook-form` & `zod` & `@hookform/resolvers`: Type-safe form validation.
  - `tailwindcss` & `@tailwindcss/vite`: Tailwind CSS v4 CSS-first styling.
- **Tailwind v4 Configuration:**
  - Configured `@tailwindcss/vite` in `vite.config.ts`.
  - Added `@import "tailwindcss";` at top of `src/index.css`.
  - Configured path aliases (`@/*` pointing to `./src/*`) in `vite.config.ts` and `tsconfig.app.json`.
- **Root Mounting & Routing:**
  - Configured `BrowserRouter` inside `ThemeProvider` in `src/main.tsx`.
  - Configured layout routes in `src/App.tsx`:
    - Parent route `/` rendered by `PublicLayout`.
    - Child routes: `/` (`HomePage`), `/about` (`AboutPage`), `/menu` (`MenuPage`), `/gallery` (`GalleryPage`), `/contact` (`ContactPage`).
    - Catch-all route (`*`) redirects to `/`.

### 2. Layout Components & Responsive Mobile Ergonomics
- **`PublicLayout.tsx`:**
  - Wraps all public pages with `PublicHeader`, `<main className="public-main"><Outlet /></main>`, `PublicFooter`, and `MobileBottomNav`.
  - Bottom padding configuration in `src/index.css`:
    ```css
    .public-main {
      padding-bottom: calc(68px + env(safe-area-inset-bottom, 0px));
    }
    @media (min-width: 768px) {
      .public-main {
        padding-bottom: 0;
      }
    }
    ```
    Guarantees page content, forms, and footers are never obstructed by the fixed mobile bottom navigation bar on mobile viewports.
- **`MobileBottomNav.tsx`:**
  - Fixed mobile dock (`fixed bottom-0 left-0 right-0 md:hidden z-50`).
  - Implements viewport safe-area padding: `paddingBottom: "env(safe-area-inset-bottom, 0px)"`.
  - 5 primary navigation destinations: Home, Menu, Gallery, About, Contact.
  - Touch target accessibility: 60px height and equal flex width per destination (all well exceeding the 44px minimum touch target).
  - High-contrast visual active state (`var(--color-accent)`) and ARIA labels.
- **`PublicHeader.tsx`:**
  - Sticky header (`sticky top-0 z-40`).
  - Mobile view (`<768px`): Compact branding, `ThemeToggle`, and hamburger menu button.
  - Desktop view (`>=768px`): Full navigation links with active indicators and `ThemeToggle`.
- **`PublicFooter.tsx`:**
  - Responsive multi-column layout on desktop, stacked on mobile.
  - Contains branding, opening hours, contact details, site links, and copyright info.
- **Reusable Shared UI:**
  - `ThemeToggle.tsx`: Seamless Light/Dark toggle.
  - `SectionHeading.tsx`: Consistent eyebrow, heading, and subtitle styling.
  - `LoadingSpinner.tsx`, `EmptyState.tsx`, `ErrorState.tsx`: Accessible operational states.

### 3. Public Pages Implementation
- **`HomePage.tsx`:**
  - Hero banner with espresso gradient overlay, tagline, and dual action CTAs.
  - Feature highlights section (Crafted With Passion, Fresh Ingredients, Memorable Experiences).
  - Dynamic featured menu items preview loaded from `/api/v1/menu` with fallback states.
  - Document title set to "Special Delicacies | Fine Dining & Takeaway".
- **`AboutPage.tsx`:**
  - Hero banner with espresso background styling.
  - "Our Story" narrative with clearly marked `[PLACEHOLDER]` text blocks.
  - Mission & Values section: 3 core pillars (Quality Food, Community First, Excellence Always).
  - Meet the Team section with 3 placeholder leadership profiles.
  - Call-to-action banner driving users to the Menu and Contact pages.
  - Document title set to "About Us | Special Delicacies".
- **`MenuPage.tsx`:**
  - Header with copper brand accent bar.
  - Sticky category filter bar with horizontal scroll on mobile and `min-h-[44px]` touch targets.
  - Dynamic category grouping displaying item counts and descriptions.
  - Responsive grid: 1 column mobile, 2 columns tablet, 3 columns desktop.
  - Image fallback and currency formatting strictly in Ghana Cedi (`GH₵XX.XX`).
  - Robust loading, empty, and error recovery states with retry handler.
  - Document title set to "Menu | Special Delicacies".
- **`GalleryPage.tsx`:**
  - Category filter pills ("All", "Food", "Restaurant", "Events") with `min-h-[44px]` touch targets.
  - 12 placeholder items across 3 categories featuring distinct copper, gold, and sage brand tints.
  - Interactive Lightbox modal with close button, `Escape` key dismissal, and backdrop click dismissal.
  - Document title set to "Gallery | Special Delicacies".
- **`ContactPage.tsx`:**
  - Two-column desktop layout (`lg:grid-cols-12` -> 7-col form, 5-col info) stacked on mobile.
  - React Hook Form + Zod schema validation for Name, Email, Phone, Subject, and Message.
  - Real-time submission to backend `POST /api/v1/contact`.
  - Backend validation error handling (422) mapped to field errors.
  - Success confirmation view with option to send another message.
  - Contact cards (Address, Phone, Email) and Opening Hours table with `[Placeholder]` labels.
  - Document title set to "Contact Us | Special Delicacies".

### 4. Backend Contact API Endpoint
- **Request Validation (`App\Http\Requests\ContactRequest`):**
  - `name`: Required, string, max 150 characters.
  - `email`: Required, valid email format, max 200 characters.
  - `phone`: Optional, string, max 30 characters.
  - `subject`: Required, string, max 200 characters.
  - `message`: Required, string, min 10 characters, max 3,000 characters.
- **Controller (`App\Http\Controllers\Api\V1\ContactController`):**
  - Logs incoming contact submission with context.
  - Returns structured JSON response: `{"success": true, "message": "Thank you for your message. We will be in touch shortly."}`.
- **Route Registration:**
  - `POST /api/v1/contact` registered in `routes/api.php`.
- **Automated Feature Tests (`Tests\Feature\ContactTest`):**
  - 4 automated feature tests verifying valid submission, missing fields rejection, invalid email rejection, and optional phone support.

### 5. Verification & Audit Results
- **Frontend Build:** `npm run build` passed with zero errors (built in 1.37s).
- **Frontend Linting:** `npm run lint` (`oxlint`) passed with zero errors and zero warnings across 23 files (116 rules).
- **Backend Test Suite:** `php artisan test` passed with **55 tests and 167 assertions** (Duration: 6.91s).
- **Live Local API Endpoints (via curl):**
  - `GET https://api.special-delicacies.local/api/v1/health` -> 200 OK
  - `GET https://api.special-delicacies.local/api/v1/menu` -> 200 OK
  - `POST https://api.special-delicacies.local/api/v1/contact` -> 200 OK
- **Ergonomics & Design Compliance:**
  - Safe-area-inset bottom padding verified (`env(safe-area-inset-bottom, 0px)`).
  - Minimum touch targets (>= 44px) verified on all buttons, links, inputs, and tabs.
  - Content obstruction avoided via `.public-main` dynamic padding.
  - Brand colors strictly applied: Espresso (`#302019`), Copper (`#BD7048`), Gold (`#D8AA65`), Sage (`#78866C`), Cream (`#F5EEE5`).
  - Currency strictly formatted in Ghana Cedi (`GH₵`) across all pages.

---

## Future Phases (Planned)

The following phases are planned and not yet started:
- Phase 4: Menu management
- Phase 5: Tables and QR codes
- Phase 6: Customer dine-in ordering
- Phase 7: Real-time operations (Laravel Reverb / Echo)
- Phase 8: Home delivery
- Phase 9: Administration
- Phase 10: Integration and quality assurance
- Phase 11: Documentation finalisation and deployment


---

## Phase 5: Tables and QR Code Management

**Status:** Completed  
**Completion Date:** 2026-11-23

### 1. Scope & Accomplishments
- **Administrative Table Management:** Full CRUD operations for restaurant dining tables (`RestaurantTableController`), including capacity constraints, uniqueness verification, active/inactive toggling, and relation-safe deletion guards preventing removal of tables with existing orders.
- **QR Code Management & Cryptographic Security:** Implemented `AdminTableQrController` utilizing `QrCodeService`. Enforces 256-bit cryptographically secure random tokens (`bin2hex(random_bytes(32))`), one-way SHA-256 storage (`table_qr_codes.token_hash`), atomic transaction-based regeneration (revoking previous active QR codes automatically), and instant revocation.
- **Admin Collapsible Sidebar Layout:** Replaced top-nav `AdminLayout.tsx` with a modern collapsible sidebar supporting 256px expanded desktop state, 64px compact icon-only desktop state with tooltips, slide-out mobile drawer with backdrop overlay, localStorage persistence (`sd_admin_sidebar_collapsed`), and full Light/Dark/System theme support using approved Special Delicacies design tokens.
- **Public QR Resolution & Landing Page:** Created minimal public customer verification endpoint `GET /api/v1/table-lookup/{token}` and dedicated frontend landing page (`/table/:token`) confirming identified table number and capacity while strictly barring food ordering, cart, or payment features.
- **Granular RBAC Permissions:** Added 6 granular permissions (`tables.create`, `tables.update`, `tables.delete`, `tables.qr.view`, `tables.qr.generate`, `tables.qr.revoke`) assigned exclusively to Administrator role.

### 2. Verification & Automated Test Results
- **Backend Test Suite:** 107 passed (407 assertions) in 26.44s (`php artisan test`).
- **Phase 5 Feature Tests (`Phase5TableManagementTest`):** 24 passed (104 assertions) in 11.19s.
- **Frontend TypeScript (`npx tsc --noEmit`):** 0 errors.
- **Frontend Production Build (`npm run build`):** Built successfully with zero errors.
- **Frontend Linting (`npm run lint`):** 0 errors across all 39 source files.
- **Safety Checks:** `erm_db` untouched; `special_delicacies_db` preserved without destructive schema resets.


---

## Phase 6: Customer Dine-In Ordering & Nginx Routing Repair

**Status:** Completed  
**Completion Date:** 2026-11-23

### 1. Scope & Accomplishments
- **Priority 1: Nginx Direct-Route 404 Repair:**
  - Diagnosed root cause: missing fallback route directory generation in `frontend/scripts/postbuild.js` for Phase 5 routes, combined with Nginx running prior configuration in memory.
  - Hardened `C:\ServBay\etc\nginx\vhosts\special-delicacies.local.conf` with static asset 404 block (`try_files $uri =404`) and SPA fallback (`try_files $uri $uri/ /index.html`).
  - Backed up configuration to `special-delicacies.local.conf.bak`.
  - Updated `scripts/postbuild.js` to generate fallback directories for `admin/tables`, `admin/qr-codes`, and `table`.
  - Verified that direct URL navigation and page refresh on `/admin/tables` and `/admin/qr-codes` return HTTP 200 without Nginx 404s, while missing static assets return genuine HTTP 404s.
- **Priority 2: Customer Dine-In Ordering:**
  - Extended `/table/:token` route into a full-featured, mobile-first dine-in ordering application.
  - Implemented category navigation, real-time search, dish customization modal with special preparation instructions, and floating bottom cart bar.
  - Implemented comprehensive cart review with quantity steppers, item removal, and price calculation in Ghana Cedis (`GH₵`).
  - Extended `CreateOrderRequest` and `OrderService` to accept `qr_token`, validate it via `QrCodeService`, confirm table status, ignore client-submitted prices, snapshot item data, and record initial `pending` order status.
  - Reused atomic mutex locks and idempotency keys to eliminate double-click or network retry duplicates.
  - Created celebratory order confirmation view displaying order number, table identification, preparation status, and itemized receipt.
- **Automated Testing:**
  - Added 15 new feature tests in `Phase6DineInOrderTest.php`.
  - Full backend test suite passing at **122 tests (451 assertions)** in 27.40s.
  - Frontend TypeScript checking (`npx tsc --noEmit`), linting (`oxlint`), and production build passing with 0 errors.


---

## Phase 9: Administration

**Status:** Completed  
**Completion Date:** 2026-11-23

### 1. Scope & Accomplishments
- **Central Admin Dashboard:** Real-time statistics aggregated from database queries covering orders by lifecycle stage, verified revenue, cash awaiting confirmation, resource counts, and period filtering.
- **Staff & User Management:** Complete staff lifecycle management with CRUD, account activation/deactivation, token invalidation upon deactivation/password reset, privilege escalation protection, and last-admin safety guards.
- **Role & Permission Management:** Granular inspection and update interface for the 4 operational roles, protecting critical administrator permissions against accidental stripping.
- **Administrative Order Oversight:** Omnichannel order monitoring (dine-in and delivery), status inspection, audit timeline, and cash-on-delivery payment collection confirmation.
- **Restaurant Settings & Website Content:** Tabbed administrative settings interface and dynamic CMS for public-facing homepage, about story, contact details, and gallery items.
- **Append-Only Audit Trail:** Dedicated dmin_audit_logs table and AuditService that automatically sanitizes sensitive fields into [REDACTED].
- **Frontend Quality:** Fully responsive desktop sidebar (256px/64px) and mobile drawer matching brand design tokens. TypeScript and build verified with 0 errors.

### 2. Verification & Automated Test Results
- **Backend Test Suite:** 261 passed (972 assertions) with 0 failures (php artisan test).
- **Phase 9 Feature Tests (Phase9AdministrationTest):** 59 passed (328 assertions) with 0 failures.
- **Frontend TypeScript (
px tsc --noEmit & 	sc -b):** 0 errors.
- **Frontend Production Build (
pm run build):** Built successfully with SPA postbuild fallback directories.
- **Frontend Linting (
pm run lint):** 0 errors.
- **Database Safety:** erm_db completely untouched; only special_delicacies_db used.

---

## Follow-up: Gateway Management, Branding, and Super Admin

**Status:** Completed  
**Completion Date:** 2026-11-24

- Added and applied the payment-gateway schema migration, including provider configuration and an order-to-gateway reference. Existing administrator role assignments are preserved while the role becomes **Super Admin** (`super-admin`).
- Added admin gateway record management for Paystack, Hubtel, and Kora, including enabled/default selection, encrypted secrets, deletion safeguards, public checkout discovery, hosted checkout, verification, and provider callbacks.
- Fixed restaurant-settings loading isolation, uploaded logo/favicon URL handling and live refresh, and admin/staff branding surfaces.
- Updated the current feature, setup, architecture, database, and overview documents for gateway and role behavior.
- Verification: `php artisan test --compact` passed 275 tests (1,060 assertions); `npm run build` passed. The build reports the existing large JavaScript chunk advisory.
