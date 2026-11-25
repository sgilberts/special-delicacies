# Special Delicacies - System Architecture

## 1. Architecture Principle

Special Delicacies uses a separate frontend and backend architecture.

The React application and Laravel application are independent projects. React communicates with Laravel through versioned REST API endpoints.

Laravel is the authoritative source for business logic, validation, permissions, order processing, and database operations.

The project will not use an Inertia-based architecture or embed the React frontend inside Laravel.

---

## 2. Application Structure

Project root:

`special-delicacies/`
- `frontend/` — React 19 + TypeScript + Vite frontend
- `backend/` — Laravel 13 backend
- `docs/` — Project documentation
- `README.md` — Project summary

---

## 3. Technology Stack

### Frontend
- React 19
- TypeScript
- Vite
- Tailwind CSS
- React Router
- Axios
- React Hook Form
- Zod
- shadcn/ui
- Lucide React
- Laravel Echo
- pusher-js

### Backend
- Laravel 13
- PHP 8.4
- REST API (`/api/v1/`)
- Laravel Sanctum
- Laravel Reverb (planned for real-time operations)
- Laravel Echo

### Database
- MySQL 8.4
- Database name: `special_delicacies_db`
- Character set: `utf8mb4`
- Collation: `utf8mb4_0900_ai_ci`

---

## 4. Current Implementation Status by Phase

- **Phase 1 — Project Foundation:** COMPLETED & ACCEPTED
- **Phase 2 — Database and Backend Foundation:** COMPLETED & VERIFIED
- **Phase 3 — Public Website:** COMPLETED & VERIFIED
- **Phase 4 — Menu Management:** COMPLETED & READY FOR ACCEPTANCE
- **Phase 5 — Tables and QR Codes:** Planned (Not started)
- **Phase 6 — Customer Dine-In Ordering:** Planned (Not started)
- **Phase 7 — Real-Time Operations:** Planned (Not started)
- **Phase 8 — Home Delivery:** Planned (Not started)
- **Phase 9 — Administration:** Planned (Not started)
- **Phase 10 — Integration and Quality Assurance:** Planned (Not started)
- **Phase 11 — Documentation Finalisation and Deployment:** Planned (Not started)

---

## 5. Database Foundation (Implemented in Phase 2)

The current database schema comprises **15 application tables**. This count includes the online `payment_gateways` configuration table; framework support tables are documented separately in [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md).

1. `users` — Extended with `phone` (unique) and `is_active` (boolean).
2. `roles` — Staff roles (`super-admin`, `waiter-waitress`, `kitchen-staff`, `delivery-staff`). Super Admin has intrinsic full system access.
3. `permissions` — Granular capabilities.
4. `role_user` — Pivot connecting users to roles.
5. `permission_role` — Pivot connecting roles to permissions.
6. `menu_categories` — Active menu categorization.
7. `menu_items` — Offerings with `DECIMAL(10, 2)` pricing and availability.
8. `restaurant_tables` — Physical dining tables with capacity.
9. `table_qr_codes` — SHA-256 hashed QR tokens with transactional single-active rule.
10. `delivery_zones` — Configured geographical areas with delivery fees.
11. `orders` — Master orders supporting both dine-in and home delivery.
12. `order_items` — Line items freezing item name, unit price, and line total snapshots.
13. `order_status_histories` — Immutable operational audit log of status changes.
14. `restaurant_settings` — Key-value configuration store.
15. `payment_gateways` — Provider credentials, provider-specific configuration, and enabled/default checkout state for Paystack, Hubtel, and Kora.

---

## 6. Authentication and Authorisation Foundation

- **Authentication:** Laravel Sanctum.
- **RBAC Model:** Users have roles; roles have permissions; Super Admin (`super-admin`) intrinsically has every system permission across management, operations, settings, and audit logs.
- **Middleware Enforcement:**
  - `EnsureUserHasPermission` (`permission:...`)
  - `EnsureUserHasRole` (`role:...`)
- **Key Access Restrictions:**
  - Waiters cannot edit menu prices (`menu.price.edit` denied).
  - Kitchen Staff cannot access administrative settings (`settings.manage` denied).
  - Deactivated accounts are blocked at the middleware level.

---

## 7. Order Lifecycle and Integrity Architecture

### Lifecycle State Machine
- **Dine-In Orders:**
  `pending -> confirmed -> preparing -> ready -> delivered -> completed`
  *(Cancellation allowed from `pending` or `confirmed`)*
- **Delivery Orders:**
  `pending -> confirmed -> preparing -> ready -> out_for_delivery -> delivered -> completed`
  *(Cancellation allowed from `pending` or `confirmed`)*

### Integrity Controls
- **Zero Frontend Price Trust:** The frontend cannot submit or alter prices. Prices are queried directly from `menu_items` in the database.
- **Availability Enforcement:** Items with `is_available = false` cannot be ordered.
- **Snapshot Preservation:** If a menu item's price or name changes later, existing orders retain historical snapshots in `order_items`.
- **Delivery Fee Separation:** Food subtotal and delivery fee are kept distinct; `total = subtotal + delivery_fee`.
- **Transactional State Changes:** Every transition records an entry in `order_status_histories`.

---

## 8. QR Code Security Architecture

- **Token Generation:** 64-character cryptographic hex strings (`bin2hex(random_bytes(32))`).
- **Database Storage:** Only the SHA-256 hash (`hash('sha256', $plainToken)`) is stored in `table_qr_codes.token_hash`.
- **Zero Exposure of IDs:** Customers scan the plain token. The backend resolves the token hash to the physical table without exposing internal table or QR database IDs.
- **One-Active-QR Rule:** Generating a new QR code for a table transactionally revokes any existing active QR code for that table (`is_active = false`, `revoked_at = now()`).

---

## 9. Real-Time Architecture (Planned for Phase 7)

Laravel Reverb and Laravel Echo will provide WebSocket broadcasting for operational events:
- `OrderPlaced`
- `OrderConfirmed`
- `OrderPreparing`
- `OrderReady`
- `OrderDelivered`
- `OrderCancelled`
- `DeliveryOrderPlaced`

Broadcasting remains planned for Phase 7 and is not yet implemented.

---

## 10. Public Website Frontend Architecture (Implemented in Phase 3)

The public restaurant website is built using a component-driven, responsive architecture in React 19 + TypeScript + Tailwind CSS v4.

### 10.1 Routing & Layout Hierarchy
- **Router Configuration:** React Router v7 (`react-router-dom`) with HTML5 history via `BrowserRouter` mounted at root.
- **Route Definitions (`App.tsx`):**
  - `/` — `PublicLayout` wrapper:
    - `/` (index) — `HomePage`: Hero banner, core values, signature dishes preview, dynamic menu fetch, call-to-action sections.
    - `/menu` — `MenuPage`: Category navigation bar (sticky on desktop, horizontal scroll on mobile), category item groupings, dynamic live API fetch, fallback states, GH₵ currency formatting.
    - `/gallery` — `GalleryPage`: Filterable gallery (Food, Restaurant, Events), accessible lightbox modal with keyboard (`Escape`) and backdrop dismissal.
    - `/about` — `AboutPage`: Restaurant narrative with explicit `[PLACEHOLDER]` tags, mission and core values, leadership team preview, visit CTA.
    - `/contact` — `ContactPage`: Responsive two-column layout, React Hook Form + Zod validation, direct API submission to `POST /api/v1/contact`, inline validation error feedback, opening hours, contact details.
    - `*` — Catch-all redirect to `/`.

### 10.2 Layout and Mobile Ergonomics Architecture
- **Header (`PublicHeader.tsx`):**
  - Sticky navigation bar (`sticky top-0 z-40`).
  - Mobile breakpoint (`<768px`): Compact branding, `ThemeToggle`, and mobile menu button.
  - Tablet and Desktop breakpoint (`>=768px`): Full navigation menu links with active indicators and `ThemeToggle`.
- **Main Layout (`PublicLayout.tsx` & `index.css`):**
  - Container `.public-main` dynamically allocates bottom padding on mobile screens (`<768px`):
    `padding-bottom: calc(68px + env(safe-area-inset-bottom, 0px))`
  - On tablet and desktop (`>=768px`), padding resets to `0`, ensuring that the fixed mobile bottom navigation bar never obscures content, footers, or action buttons.
- **Mobile Bottom Navigation (`MobileBottomNav.tsx`):**
  - Fixed dock pinned to the bottom of the viewport (`fixed bottom-0 left-0 right-0 md:hidden z-50`).
  - Employs Apple/Android viewport safe-area-inset padding:
    `paddingBottom: "env(safe-area-inset-bottom, 0px)"`
  - Touch targets strictly satisfy mobile usability guidelines (`h-[60px]`, full flex width per tab, well exceeding the 44px minimum requirement).
  - High-contrast visual active states (`var(--color-accent)`) and accessibility labels (`aria-label`).
- **Footer (`PublicFooter.tsx`):**
  - Multi-column footer on desktop, structured stacked cards on mobile.
  - Displays business credentials, opening hours, navigation links, and copyright notices.

### 10.3 Design System and Styling Architecture
- **CSS-First Tailwind v4:** Integrated via `@tailwindcss/vite` and `@import "tailwindcss";` in `src/index.css`.
- **Universal Design Tokens (CSS Custom Properties):**
  - Brand Palette:
    - Espresso: `#302019` (`--brand-espresso`)
    - Copper: `#BD7048` (`--brand-copper`)
    - Gold: `#D8AA65` (`--brand-gold`)
    - Sage: `#78866C` (`--brand-sage`)
    - Cream: `#F5EEE5` (`--brand-cream`)
  - Semantic Tokens: `--color-background`, `--color-surface`, `--color-surface-secondary`, `--color-text`, `--color-text-secondary`, `--color-border`, `--color-accent`, `--color-primary`.
  - Universal Theme Switching: Context-driven `ThemeProvider` supporting Light, Dark, and System modes with instant OS preference synchronization and zero flash.
- **Component Classes:** Standardized utility classes defined in `index.css` (`pub-card`, `pub-input`, `pub-label`, `pub-field-error`, `btn-accent`, `btn-ghost`, `section-container`, `section-padding`).
- **Currency Standardization:** Universal formatting to Ghana Cedi (`GH₵XX.XX`) across all menu cards, lists, and order summaries.

---

## 11. Menu Management Architecture (Implemented in Phase 4)

### 11.1 Backend Engine
- **RESTful Endpoints:** Prefixed `/api/v1/admin/menu/` with granular permissions (`menu.categories.*`, `menu.items.*`, `menu.overview`).
- **Form Requests:** Strict server-side validation (`StoreMenuCategoryRequest`, `UpdateMenuCategoryRequest`, `StoreMenuItemRequest`, `UpdateMenuItemRequest`, `UpdateCategoryStatusRequest`, `UpdateItemAvailabilityRequest`, `LoginRequest`).
- **Safe Category Deletion:** Prohibits deletion of categories with associated dishes (HTTP 422).
- **Photograph Storage:** Public filesystem storage linked via `php artisan storage:link`, safe random filenames, automatic cleanup of obsolete or deleted photos, full URL serialization.

### 11.2 Management Frontend
- **Narrow Administrative Shell:** `AdminLayout` with Overview, Categories, Menu Items, Theme switcher, and logout.
- **Authentication:** `AuthContext` with Sanctum Bearer token persistence in localStorage and Axios interceptor.
- **Route Protection:** `ProtectedRoute` guarding `/admin/*`.
- **Forms & Validation:** React Hook Form and Zod resolvers.
- **Mobile Ergonomics:** Responsive tables on desktop, adaptive cards on mobile, touch targets exceeding 44px, zero horizontal overflow.

