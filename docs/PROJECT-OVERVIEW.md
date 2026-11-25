# Special Delicacies - Project Overview

## 1. Project Identity

**Application:** Special Delicacies  
**Project type:** Restaurant Website, Digital Ordering and Restaurant Operations Management System  
**Currency:** Ghana Cedi (GHS / GH₵)  
**Current development stage:** Phases 1–9 implemented, with follow-up work for payments, public ordering, branding, roles, themes, and production operations.

---

## 2. Project Purpose

Special Delicacies is being developed as an integrated restaurant platform that combines a public-facing restaurant website, digital food ordering, and internal restaurant operations.

The system is intended to support customers ordering food for dine-in or home delivery while enabling authorised restaurant staff to manage menus, tables, orders, kitchen operations, and deliveries.

---

## 3. Approved Functional Scope

### 3.1 Public Restaurant Website (Implemented)
- Home page.
- About the restaurant.
- Cuisine and food categories.
- Dynamic food and drinks menu.
- Gallery.
- Contact information.
- Restaurant location and operating hours.
- Home delivery ordering.

### 3.2 QR-Code Dine-In Ordering (Implemented)
- Each physical restaurant table has an associated unique QR token hash.
- Authorised staff can generate, revoke, and regenerate table QR codes.
- Scanning a QR code identifies the associated table securely.
- Customers browse the menu, select items and quantities, and add special instructions.
- Customers review prices and totals before submitting orders.
- Orders associate with the correct table automatically.
- Newly generated QR codes use `/table/?token=<token>` on the configured frontend origin.

### 3.3 Home Delivery (Implemented)
- Customers browse the menu and add items to cart.
- Customers provide their name, phone number, delivery address, and select a delivery zone.
- Subtotal and delivery fee are separated clearly.
- Customers can choose cash on delivery or configured Paystack hosted checkout.
- Customers can choose whether the delivery fee is included in the order total.
- Administrators configure delivery zones and delivery fees.

### 3.4 Restaurant Operations (Implemented)
- Orders routed to the appropriate operational roles.
- Kitchen updates preparation status (`preparing -> ready`).
- Waiters handle dine-in service (`ready -> delivered -> completed`).
- Delivery riders handle home delivery orders (`ready -> out_for_delivery -> delivered -> completed`).

### 3.5 Administration (Implemented)
- Restaurant settings and operational toggles.
- Delivery zones and fees.
- Menu categories, items, and pricing management.
- Table management and QR token generation.
- Staff role assignment and permission enforcement.
- Custom role create/update/delete and permission assignment, with protected-role and assigned-staff safeguards.
- Staff profile editing, profile pictures, password changes, website branding, and favicon management.
- Configurable Paystack gateway credentials and enabled/default state.

---

## 4. Staff Roles and RBAC

The four approved roles are:

1. **Super Admin (`super-admin`):** Primary full administrative access across management, operations, settings, and audit logs. Access is intrinsic and cannot be removed through permission editing.
2. **Waiter/Waitress (`waiter-waitress`):** Order viewing, creation, status updating, table viewing, and menu viewing. **Strictly prohibited from editing menu prices.**
3. **Kitchen Staff (`kitchen-staff`):** Order viewing, order status progression, and menu viewing. **Strictly prohibited from accessing administrative settings.**
4. **Delivery Staff (`delivery-staff`):** Order viewing, status updating (out for delivery / delivered), and delivery zone viewing.

---

## 5. Approved Technology Stack

| Layer | Technology | Status |
|---|---|---|
| Frontend | React 19 | Installed |
| Language | TypeScript | Configured |
| Build tool | Vite | Configured |
| Styling | Tailwind CSS | Configured |
| UI components | shadcn/ui | Configured |
| Icons | Lucide React | Installed |
| Routing | React Router | Configured |
| HTTP client | Axios | Configured |
| Forms & Validation | React Hook Form & Zod | Configured |
| Backend | Laravel 13 | Installed |
| Authentication | Laravel Sanctum | Configured & Tested |
| API | REST API (`/api/v1/`) | Implemented & Tested |
| Real-time | Laravel Reverb & Echo | Implemented; deployment depends on WebSocket/process support |
| Payments | Paystack hosted checkout | Implemented for GHS card/mobile money and supported provider methods |
| Database | MySQL 8.4 (`special_delicacies_db`) | 15 Application Tables |
| Local environment | ServBay | Operational |

---

## 6. Application Addresses

- **Frontend:** `https://special-delicacies.local`
- **Backend:** `https://api.special-delicacies.local`
- **API Base URL:** `https://api.special-delicacies.local/api/v1`

---

## 7. Universal Design System

All user interfaces share semantic design tokens across Light, Dark, and System appearance modes.

Public pages, buttons, and form controls consume global theme tokens for readable contrast. See [FEATURES-AND-OPERATIONS.md](FEATURES-AND-OPERATIONS.md) for current feature and release verification details. Phase documents below this overview retain historical phase context.

### Brand Palette
- **Espresso:** `#302019`
- **Copper:** `#BD7048`
- **Gold:** `#D8AA65`
- **Sage:** `#78866C`
- **Cream:** `#F5EEE5`

---

## 8. Approved Development Roadmap

1. **Phase 1 — Project Foundation:** COMPLETED & ACCEPTED
2. **Phase 2 — Database and Backend Foundation:** COMPLETED & ACCEPTED
3. **Phase 3 — Public Restaurant Website:** COMPLETED & ACCEPTED
4. **Phase 4 — Menu Management:** COMPLETED & READY FOR ACCEPTANCE
5. **Phase 5 — Tables and QR Codes:** Planned (Not started)
6. **Phase 6 — Customer Dine-In Ordering:** Planned (Not started)
7. **Phase 7 — Real-Time Operations:** Planned (Not started)
8. **Phase 8 — Home Delivery:** Planned (Not started)
9. **Phase 9 — Administration:** Planned (Not started)
10. **Phase 10 — Integration and Quality Assurance:** Planned (Not started)
11. **Phase 11 — Documentation Finalisation and Deployment:** Planned (Not started)

---

## 9. Current Implementation Status (Post Phase 4)

- 14 database tables created, migrated, and verified in `special_delicacies_db`.
- User model extended with unique `phone` and `is_active` flags.
- Complete RBAC implementation with roles, permissions, pivots, and seeders.
- Backend authorization middleware enforcing permission checks.
- Administrative menu category management (create, view, edit, sort order, activate/deactivate, safe deletion).
- Administrative menu item management (create, view, edit, category assignment, price management in GH₵, food photo upload/replace/remove, live availability toggling).
- Safe category deletion protection: blocks deletion with HTTP 422 if dishes are assigned.
- Food image upload, validation (JPEG/PNG/WebP max 5MB), secure filesystem storage, and full URL serialization.
- Admin menu management shell in React 19 (`/admin`, `/admin/categories`, `/admin/items`, `/admin/login`) with React Hook Form + Zod, responsive tables and mobile cards, touch targets >44px.
- Public menu and home page featured menu integration with live database records, GH₵ formatting, and image fallbacks.
- 83 automated backend tests covering all features and RBAC restrictions (303 assertions, 100% pass rate).
- Production build and oxlint passing with 0 errors.
