# Special Delicacies - Installation and Development Guide

## 1. Purpose

This document describes the local development environment and operational instructions established for Special Delicacies.

---

## 2. Required Environment

- **Operating System:** Windows
- **Local Web Stack:** ServBay
- **PHP:** 8.4.26 (or compatible Laravel 13 PHP version)
- **Composer:** 2.11.1+
- **Node.js:** 26.13.1+
- **npm:** 11.20.0+
- **Database Engine:** MySQL 8.4.11
- **Git:** 2.56.0+

---

## 3. Project Location and Structure

`C:\ServBay\www\projects\special-delicacies`

Main directories:
- `frontend/` — React 19 + TypeScript + Vite frontend
- `backend/` — Laravel 13 backend
- `docs/` — Project documentation

---

## 4. Local Domains

- **Frontend:** `https://special-delicacies.local`
- **Backend:** `https://api.special-delicacies.local`
- **API Base:** `https://api.special-delicacies.local/api/v1`

*Note: ServBay provides the local web server environment. Do not use `php artisan serve` as a substitute for the configured local domains.*

---

## 5. Backend Setup and Migrations

Open PowerShell and navigate to the backend directory:

```powershell
cd C:\ServBay\www\projects\special-delicacies\backend
```

### 5.1 Environment Configuration
Verify that `backend/.env` is configured for the dedicated database:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=special_delicacies_db
DB_USERNAME=your_database_username
DB_PASSWORD=your_database_password
SYSTEM_ADMIN_DEFAULT_PASSWORD=Admin@123456/AdminPassword123!
```

*Important: The database `erm_db` belongs to an external project. Never migrate, alter, or touch `erm_db`.*

### 5.2 Execute Database Migrations
Run the migrations to create the current application schema, including the online payment gateway configuration table:

```powershell
php artisan migrate
```

Verify migration status:

```powershell
php artisan migrate:status
```

### 5.3 Seed Foundational Data
Seed the four approved roles, granular permissions, and initial restaurant settings:

```powershell
php artisan db:seed
```

This runs:
- `RoleAndPermissionSeeder` (idempotent; creates roles, permissions, and role-permission associations)
- `RestaurantSettingSeeder` (idempotent; initializes default operational settings)

### 5.4 Run Automated Backend Tests
Run the complete PHPUnit test suite:

```powershell
php artisan test
```

### 5.5 List API Routes
Verify that all Phase 2 API routes are registered:

```powershell
php artisan route:list --path=api
```

---

## 6. Frontend Setup

Open PowerShell in the frontend directory:

```powershell
cd C:\ServBay\www\projects\special-delicacies\frontend
```

### 6.1 Install Dependencies
```powershell
npm install
```

### 6.2 Environment Configuration
Verify `frontend/.env` contains:

```env
VITE_API_BASE_URL=https://api.special-delicacies.local/api/v1
```

*Note: Save `frontend/.env` as UTF-8 without BOM.*

For a local or production deployment, set the frontend build URL and backend `FRONTEND_URL` to the public frontend origin. The backend should use a public filesystem disk for uploaded branding and menu images; create Laravel's public storage link once:

```powershell
php artisan storage:link
```

Complete Paystack credentials in the admin **Settings → Payment Gateway** screen after applying migrations. The secret key is stored encrypted in the database, so keep `APP_KEY` stable and backed up. Configure the Paystack webhook URL as `https://<api-host>/api/v1/payments/paystack/webhook`; local webhook testing requires a public HTTPS tunnel. See [FEATURES-AND-OPERATIONS.md](FEATURES-AND-OPERATIONS.md) for checkout behavior and the release checklist.

For Reverb configuration, hosted broadcasting fallback, and WebSocket reverse proxy examples, follow [broadcasting-deployment.md](broadcasting-deployment.md).

### 6.3 Build and Lint
```powershell
npm run build
npm run lint
```

---

## 7. Verification Steps

1. **Health Check:**
   ```powershell
   curl.exe -k -s "https://api.special-delicacies.local/api/v1/health"
   ```
   Expect: `{"success":true,"message":"Special Delicacies API is running.","application":"Special Delicacies","version":"1.0.0"}`

2. **Menu Check:**
   ```powershell
   curl.exe -k -s "https://api.special-delicacies.local/api/v1/menu"
   ```
   Expect: `{"success":true,"data":[]}` (or active categories)

3. **Database Overview:**
   ```powershell
   php artisan db:show
   ```
   Confirm the current application tables exist under `special_delicacies_db`; the full list is in [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md).
