# Special Delicacies — Phase 4: Menu Management Implementation Guide

## 1. Overview

Phase 4 implements a complete, secure, production-grade Menu Management system for Special Delicacies. It enables authorized restaurant administrators to manage food categories and dishes via an authenticated management interface while automatically serving accurate menu information to the public website.

- **Primary Currency:** Ghana Cedi (GHS / GH₵)
- **Administrative Interface:** `https://special-delicacies.local/admin`
- **Public Menu:** `https://special-delicacies.local/menu`
- **Backend API Base:** `https://api.special-delicacies.local/api/v1`

---

## 2. Implemented Features

### 2.1 Category Management
- Create, view, edit, reorder, and toggle active status of categories.
- Automatic or custom unique slug generation.
- **Safe Category Deletion:** Protects foreign-key constraints. If a category contains dishes, deletion requests are stopped with HTTP `422 Unprocessable Entity` ("Cannot delete category '...' because it contains associated menu item(s). Reassign or delete those items first.").

### 2.2 Menu Item Management
- Create, view, edit, and safely delete dishes.
- Set and adjust selling prices in Ghana Cedis (`GH₵`) with two-decimal precision.
- Filter by category, availability, and search by dish name or description.
- Pagination controls (12 items per page).
- Live availability toggles (`is_available` true/false). Unavailable dishes are automatically excluded from customer ordering on the public menu.

### 2.3 Food Photograph Storage & Management
- Secure image uploads accepting JPEG, PNG, and WebP (up to 5MB).
- Stored on the `public` disk under `storage/app/public/menu-items/` with unique cryptographically random names.
- Public storage symlink created via `php artisan storage:link`.
- Full URL serialization through model accessors (`https://api.special-delicacies.local/storage/menu-items/...`).
- Safe replacement: Old images are removed from disk only after a replacement upload succeeds.
- Safe deletion: Photo files are purged from disk when an item is deleted or its photo is removed.
- Visual fallbacks: Graceful placeholder icons displayed when no photo is provided or if network fails.

### 2.4 Role-Based Access Control (RBAC)
- Admin authentication via `POST /api/v1/auth/login` issuing Laravel Sanctum tokens.
- Granular permissions:
  - `menu.categories.view`, `menu.categories.create`, `menu.categories.update`, `menu.categories.delete`
  - `menu.items.view`, `menu.items.create`, `menu.items.update`, `menu.items.delete`
  - `menu.items.update_price`, `menu.items.update_availability`, `menu.items.upload_image`
- All management endpoints protected by `auth:sanctum` and `permission:...` middleware.
- Waiters/Waitresses, Kitchen Staff, and Delivery Staff are strictly prohibited from accessing management endpoints or altering menu prices.

### 2.5 Admin Menu Management Interface (React 19)
- **Menu Overview:** Live dashboard cards showing Total Categories, Total Dishes, Available Items, and Unavailable Items.
- **Category Management Page (`/admin/categories`):** Responsive table on desktop/tablet, touch-friendly cards on mobile, category creation/editing modal with React Hook Form + Zod, and safe deletion confirmation dialog.
- **Menu Item Management Page (`/admin/items`):** Comprehensive listing with dish thumbnails, GH₵ pricing, live availability switches, image upload and live preview, search, filters, pagination, and deletion modal.
- **Theme Support:** Full compatibility with Light (`#FAF7F2`), Dark (`#191512`), and System modes using semantic tokens.
- **Mobile Ergonomics:** App-like mobile experience with hamburger navigation, touch targets >44px, and zero horizontal table clipping.

### 2.6 Public Website Menu Integration
- The `/menu` page dynamically loads active categories and available dishes from `GET /api/v1/menu`.
- Featured dishes section on the Home page dynamically showcases up to 6 available dishes.
- Fallback states and empty-state illustrations displayed when menu items are loading or unavailable.

---

## 3. Database Status

- Database: `special_delicacies_db` (dedicated MySQL 8.4 database).
- External `erm_db`: Untouched and isolated.
- **Zero Migrations Required:** The existing Phase 2 schema for `menu_categories` and `menu_items` already provided exact fields, data types, and foreign key restrict rules.

---

## 4. Verification Summary

- **Backend Automated Tests:** 83 tests passed (303 assertions, 0 failures).
- **Frontend Production Build:** `tsc -b && vite build` exited with code 0.
- **Frontend Linter:** `oxlint` exited with 0 errors.
- **Live ServBay Domain Verification:** All endpoints and web routes verified live via browser and curl.
