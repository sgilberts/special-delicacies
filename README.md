# Special Delicacies

## Restaurant Website and Digital Ordering System

### Project Overview

Special Delicacies is a restaurant website and digital ordering management platform.

The approved system scope includes:

- Public restaurant website.
- Dynamic food and beverage menu.
- QR-code-based dine-in ordering with individual table credentials.
- Real-time kitchen order management.
- Real-time waiter and waitress order management.
- Home delivery ordering with delivery zones.
- Pay on Delivery.
- Restaurant administration dashboard.

---

### Technology Stack

| Component | Technology | Status |
|---|---|---|
| Frontend | React 19 + TypeScript | Operational & Fully Routed |
| Routing | React Router v7 | Implemented |
| Build Tool | Vite | Configured |
| Styling | Tailwind CSS v4 | Universal Design System |
| Forms & Validation | React Hook Form + Zod | Operational |
| Backend | Laravel 13 | Operational |
| Database | MySQL 8.4 (`special_delicacies_db`) | 15 Tables Implemented |
| Authentication | Laravel Sanctum | Tested & Protected Endpoints |
| Authorization | RBAC (Roles & Permissions) | Middleware Enforced |
| Real-time | Laravel Reverb and hosted Pusher-compatible broadcaster | Configurable |
| API | REST API (`/api/v1/`) | Implemented & Tested |
| Currency | Ghana Cedi (GHS / GH₵) | Configured |

---

### Project Structure

- `frontend/`: React application (Light/Dark/System theme, React Router, public pages, responsive layout, API client).
- `backend/`: Laravel application (Models, migrations, seeders, services, API controllers).
- `docs/`: Comprehensive project documentation.

---

### Development Environment

- Operating System: Windows
- Local Server: ServBay
- Backend Domain: `https://api.special-delicacies.local`
- Frontend Domain: `https://special-delicacies.local`
- Database: MySQL 8.4.11 (`special_delicacies_db`)

---

### Architecture & Security Highlights

1. **Decoupled Architecture:** React frontend communicates with Laravel via REST API (`/api/v1/`).
2. **Authoritative Backend:** Prices, order calculations, availability, and status transitions are validated strictly on the server.
3. **RBAC Protection:** 4 approved staff roles (`administrator`, `waiter-waitress`, `kitchen-staff`, `delivery-staff`). Waiters cannot edit prices; kitchen staff cannot access admin settings.
4. **QR Token Cryptography:** Cryptographically secure 64-character tokens are hashed with SHA-256 before storage; raw tokens are never stored in the database.
5. **Historical Snapshots:** Order line items freeze item names, unit prices, and line totals to protect financial history against subsequent menu alterations.
6. **Strict Database Isolation:** The external `erm_db` database was completely untouched and remains isolated.
7. **Mobile Ergonomics:** Responsive layout with safe-area-inset bottom padding, fixed bottom dock navigation on mobile, and guaranteed min 44px touch targets.

---

### Current Implementation Status

- **Phase 1 — Project Foundation:** COMPLETED & ACCEPTED.
- **Phase 2 — Database and Backend Foundation:** COMPLETED & ACCEPTED.
- **Phase 3 — Public Restaurant Website:** COMPLETED & ACCEPTED.
- **Phase 4 — Menu Management:** COMPLETED & READY FOR ACCEPTANCE.
  - Granular RBAC permissions for menu management (`menu.categories.*`, `menu.items.*`).
  - REST API endpoints for administrative category & item CRUD, sorting, status, and overview.
  - Safe category deletion logic enforcing foreign-key protection (HTTP 422 on populated categories).
  - Secure menu image upload, validation (JPEG/PNG/WebP max 5MB), and storage on public filesystem disk.
  - Authenticated admin menu management interface in React 19 (`/admin`, `/admin/categories`, `/admin/items`).
  - Seamless public website menu integration displaying database-stored categories, items, and GH₵ prices.
  - 83 automated backend tests covering all features and RBAC restrictions (303 assertions, 100% pass rate).
  - TypeScript compilation and Oxlint linting clean (0 errors).

QR ordering and live broadcast configuration, including Apache/Nginx and shared-hosting deployment notes, are documented in [docs/broadcasting-deployment.md](docs/broadcasting-deployment.md).

Start at the [documentation index](docs/README.md) for current operating guides. It links to payment gateway configuration, public ordering, branding, role management, deployment, schema, and API reference instructions.

---

### Verification Commands

```powershell
# Frontend build & lint:
cd frontend
npm run build
npm run lint

# Backend test suite & status:
cd backend
php artisan migrate:status
php artisan test
php artisan route:list --path=api
```
