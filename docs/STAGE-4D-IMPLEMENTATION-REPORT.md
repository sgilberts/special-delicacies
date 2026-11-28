# Stage 4D — Implementation Report

**Date:** 2026-11-28
**Branch:** `org-support-upgrade`
**Stage 4D implementation commit:** `d45ded5` — “Implement Stage 4D financial reporting and reconciliation modules”
**Baseline:** `main` at `067e87d` (PR #3 merge); branch head before Stage 4D work `e83de09`
**Companion documents:** `docs/STAGE-4D-DECISION-REGISTER.md`, `docs/STAGE-4D-IMPLEMENTATION-PLAN.md`, `docs/STAGE-4D-API.md`, `docs/STAGE-4D-RECONCILIATION-GUIDE.md`

This report records what exists in the repository and what was executed to verify it. It separates implementation from evidence and does not describe planned work as completed.

---

## 1. Scope delivered

- A reporting kernel: signed minor-unit money helpers, timezone-aware periods, a single per-receipt derivation, FIFO credit attribution and a CSV exporter.
- Nine financial reports:
  1. Payment and receipt register
  2. Payment allocations
  3. Unallocated receipts
  4. Accounts receivable
  5. Customer credit (with statement)
  6. Organisation credit (with statement)
  7. Refunds
  8. Credit notes
  9. Financial activity and audit
- A financial management dashboard: twelve separated figures, previous-period comparison, daily/weekly/monthly trends, operational panels and exceptions.
- Internal reconciliation: receipt and invoice classification, exception codes, auditable runs with prepare/review and immutable closed history.
- 21 report routes + 5 reconciliation routes (26 total), permission-gated.
- 13 new permissions granted to `super-admin` only.
- An additive migration: permissions, four indexes and the `reconciliation_runs` table.
- A frontend financial module: dashboard, nine report pages with filters/sorting/pagination/CSV export, credit statement drawer and reconciliation workbench.
- Three documentation files delivered in this turn (see §5).

**Not delivered:** external provider settlement reconciliation, statement import, fee/net settlement reporting, manual statement matching, scheduled reconciliation. See §8–§9.

---

## 2. Files and migrations changed

### Migration

`backend/database/migrations/2026_11_28_000001_add_stage4d_reporting_permissions_and_reconciliation.php`

- Creates/updates 13 permissions and grants them to `super-admin` with `syncWithoutDetaching` (re-seeding never strips them).
- Adds indexes (only when absent): `payment_transactions(status, received_at, currency)`, `payment_allocations(allocated_at)`, `customer_credit_ledger_entries(created_at, entry_type)`, `refund_requests(confirmed_at)`.
- Creates `reconciliation_runs` (id, reference unique, source, period_start, period_end, status, prepared_by, prepared_by_name_snapshot, prepared_at, reviewed_by, reviewed_by_name_snapshot, reviewed_at, exception_count, resolution_notes, summary JSON, timestamps, two indexes).
- `down()` drops the table, the indexes (when present) and the permission grants.

No Stage 4A–4C table is altered, dropped or re-created.

### Backend files (commit `d45ded5`)

- `backend/app/Http/Controllers/Api/V1/Admin/FinancialReportsController.php`
- `backend/app/Http/Controllers/Api/V1/Admin/FinancialReconciliationController.php`
- `backend/app/Services/Reporting/ActivityReportService.php`
- `backend/app/Services/Reporting/AllocationReportService.php`
- `backend/app/Services/Reporting/CreditAttribution.php`
- `backend/app/Services/Reporting/CreditNoteReportService.php`
- `backend/app/Services/Reporting/CreditReportService.php`
- `backend/app/Services/Reporting/CsvExporter.php`
- `backend/app/Services/Reporting/DashboardService.php`
- `backend/app/Services/Reporting/Money.php`
- `backend/app/Services/Reporting/ReceiptLedger.php`
- `backend/app/Services/Reporting/ReceiptRegisterService.php`
- `backend/app/Services/Reporting/ReceivableReportService.php`
- `backend/app/Services/Reporting/ReconciliationService.php`
- `backend/app/Services/Reporting/RefundReportService.php`
- `backend/app/Services/Reporting/ReportPeriod.php`
- `backend/app/Services/Reporting/UnallocatedReportService.php`
- `backend/routes/api.php` (Stage 4D route blocks only)
- `backend/tests/Feature/Concerns/BuildsFinancialReports.php`
- `backend/tests/Feature/Stage4dFinancialReportsTest.php`
- `backend/tests/Feature/Stage4dPermissionTest.php`
- `backend/tests/Feature/Stage4dReconciliationTest.php`
- `backend/tests/Feature/Stage4dReportingKernelTest.php`

### Frontend files (commit `d45ded5`)

- `frontend/src/App.tsx` (three routes)
- `frontend/src/components/admin/AdminLayout.tsx` (report navigation)
- `frontend/src/components/financial/ReportShell.tsx`
- `frontend/src/components/financial/ReportTable.tsx`
- `frontend/src/components/financial/format.ts`
- `frontend/src/components/financial/reportDefinitions.tsx`
- `frontend/src/components/financial/useReportQuery.ts`
- `frontend/src/pages/admin/financial/FinancialDashboardPage.tsx`
- `frontend/src/pages/admin/financial/FinancialReconciliationPage.tsx`
- `frontend/src/pages/admin/financial/FinancialReportPage.tsx`

### Documentation (this turn)

- `docs/STAGE-4D-API.md`
- `docs/STAGE-4D-RECONCILIATION-GUIDE.md`
- `docs/STAGE-4D-IMPLEMENTATION-REPORT.md` (this file)

The Stage 4D commit touches no Stage 4A–4C service, model, policy or ledger. The only shared files edited are `routes/api.php` (additive route blocks), `App.tsx` and `AdminLayout.tsx` (additive routes/menu items).

---

## 3. Backend implementation

### 3.1 Reporting kernel

- `Money` — parses SQL decimal aggregates into signed integer minor units and formats back to two-decimal strings. Stage 4A–4C arithmetic continues to use `PaymentAllocationService::toMinorUnits()`.
- `ReportPeriod` — `Africa/Accra` day boundaries, end-of-day inclusive upper bound, swap when reversed, 366-day clamp, previous-period derivation, metadata.
- `ReceiptLedger` — one SQL definition of a receipt’s worth: allocated (active allocations), designated (designations net of reversals), reserved (live refunds), available and refundable; shared filters (period, currency, status, method, provider, purpose, payer type, search); one row presenter.
- `CreditAttribution` — FIFO attribution of credit entries mirroring `CustomerCreditReversalService::remainingReversibleMinor()`.
- `CsvExporter` — streamed CSV with formula-injection escaping.

### 3.2 Report services

`DashboardService`, `ReceiptRegisterService`, `AllocationReportService`, `UnallocatedReportService`, `ReceivableReportService`, `CreditReportService`, `RefundReportService`, `CreditNoteReportService`, `ActivityReportService`, `ReconciliationService`. All read through query builder aggregates; no report loads a ledger into PHP memory or issues a query per row.

### 3.3 API and permissions

- Routes live inside the existing `auth:sanctum` + `EnsureStaffAccount` admin group, each with `permission:<slug>`.
- 21 report routes (dashboard; read + CSV export for eight reports; three customer-credit routes and three organisation-credit routes) and 5 reconciliation routes.
- CSV requires the report read permission **and** `financials.reports.export`.
- Contact details are withheld without `customers.view_financials` / `organisations.view_financials` (super-admin always sees them).

### 3.4 Reconciliation

- Snapshot: receipt counts/amounts/classification, invoice counts/amounts/classification, exceptions E-01/E-05/E-07/E-08 + TOTAL, settlement availability.
- Prepare: stores the snapshot JSON, `awaiting_review`, reference `REC-<from>-<to>-<random>`, exception count, audit entry.
- Review: closes as `reconciled` or `reconciled_with_exceptions`; refuses `reconciled` when exceptions exist; refuses any edit to a closed run; audit entry.
- Settlement availability is a fixed `available: false`, `tier: not_configured` payload naming the missing data and decisions D4/D5/D12/D13.

---

## 4. Frontend implementation

- Routes:
  - `/admin/financials/dashboard` — guarded by `financials.reports.dashboard.view`.
  - `/admin/financials/reports/:report` — nine definitions, each enforcing its own permission inside the page.
  - `/admin/financials/reconciliation` — guarded by `financials.reconciliation.view`.
- Sidebar entries are added only for the permissions the user holds.
- Report pages share `ReportShell` (period, currency, search, report-specific filters, export button), `ReportTable` (sorting, pagination, empty/loading/error states) and `useReportQuery` (debounced fetch, abort on change, error mapping including 403).
- The dashboard renders twelve separated figures with definitions, previous-period comparison, daily/weekly/monthly trends, five-row operational panels and the exceptions list.
- Credit reports open a statement drawer; the statement is read-only.
- The reconciliation workbench shows the snapshot, exception list, settlement “Not configured” panel, run history and prepare/review controls gated by permission. Closed runs are labelled “closed · read-only”.
- `reportDefinitions.tsx` mirrors the API’s fields and export columns; it is the single source for the nine report pages.

---

## 5. Tests and exact results

All commands ran inside the Compose `api` / `web` services on the sandbox stack. PHPUnit 12.5.45, PHP 8.4.28.

### 5.1 Backend — SQLite (in-memory)

```
docker compose --project-name app --project-directory "$BASE44_PROJECT_ROOT" \
  -f "$BASE44_CONFIG_ROOT/docker-compose.base44.yml" exec -T api \
  sh -lc 'cd /app/backend && ./vendor/bin/phpunit'
```

Result: **OK (549 tests, 3278 assertions)**, 26.093 s.

### 5.2 Backend — MySQL 8.4.13 (disposable)

Database: `special_delicacies_stage4d_test` (created for this run, grants limited to that database).
Environment: `BASE44_MYSQL_TEST_DATABASE=special_delicacies_stage4d_test`; `tests/TestCase.php` refuses any database that is not a disposable `special_delicacies_*_test` name and refuses the protected development/acceptance names.

```
docker compose ... exec -T -e BASE44_MYSQL_TEST_DATABASE=special_delicacies_stage4d_test api \
  sh -lc 'cd /app/backend && ./vendor/bin/phpunit'
```

Result: **OK (549 tests, 3278 assertions)**, 74.752 s. Server version confirmed `8.4.13`.

### 5.3 Stage 4D test coverage

| File | Covers |
| --- | --- |
| `Stage4dReportingKernelTest` | `Money`, `ReportPeriod`, CSV escaping, FIFO attribution parity |
| `Stage4dFinancialReportsTest` | Dashboard figures/trends, all nine reports, statements, exports, end-of-day boundary, currency partitioning |
| `Stage4dPermissionTest` | Read vs export separation, ordinary-admin denial, reconciliation permission separation, settlement unavailability, contact masking |
| `Stage4dReconciliationTest` | Receipt/invoice classification, prepare/review lifecycle, exception rule, immutability, audit entries |

### 5.4 Image-upload regression (GD)

Seven tests previously blocked by a missing GD extension, run against the rebuilt image:

```
./vendor/bin/phpunit tests/Feature/AdminMenuItemTest.php tests/Feature/ChatFeatureTest.php \
  tests/Feature/PaymentGatewayManagementTest.php tests/Feature/UserProfileTest.php \
  --filter "test_admin_can_create_menu_item_with_image|test_menu_item_creation_rejects_oversized_image|test_admin_can_replace_and_remove_item_photograph|test_admin_can_delete_menu_item_and_cleans_up_image|test_image_attachment_gets_private_thumbnail_and_invalid_image_is_rejected|test_admin_favicon_upload_is_saved_and_returned_to_the_public_content_api|test_staff_can_upload_a_profile_photo"
```

Result: **OK (7 tests, 43 assertions)**.

### 5.5 Frontend

| Command | Result |
| --- | --- |
| `npm run test:rbac` | 12 pass, 0 fail |
| `npm run test:customers` | 19 pass, 0 fail |
| `npm run test:organisations` | 19 pass, 0 fail |
| `npm run lint` (oxlint) | 79 warnings, **0 errors** |
| `npm run build` (`tsc -b` + Vite + postbuild) | success; `index-*.js` 902.30 kB, `AdminFinancialsPage-*.js` 42.57 kB; route fallbacks/404 generated |

---

## 6. MySQL verification

- Server: MySQL **8.4.13** in the Compose `mysql` service.
- Test database: `special_delicacies_stage4d_test`, created for this run with `utf8mb4`/`utf8mb4_unicode_ci`.
- Full suite result on MySQL: 549 tests / 3278 assertions, identical to SQLite.
- The development database `special_delicacies_db` and the protected acceptance databases were not migrated, reset or modified by the test run.
- The disposable test database was dropped after the run; no test database or fixture remains.

---

## 7. Browser verification

### 7.1 This turn

Signed in to the sandbox preview at `/admin/login` with the repository’s documented development super-admin account, then opened the three financial routes.

| Screen | Assertion | Result |
| --- | --- | --- |
| `/admin/financials/dashboard` | “Financial Dashboard” heading visible; “Financial trends” panel; overview cards render | Passed |
| `/admin/financials/reports/receipts` | “Payment & Receipt Register” heading; summary section renders | Passed (the table was still in its loading state at capture time; table rows were not asserted) |
| `/admin/financials/reconciliation` | “Provider settlement reconciliation” panel renders with “Not configured.” | Passed |

- No browser console errors were reported.
- Dashboard figures were all `GHS 0.00` with “no previous baseline”, consistent with a preview database holding no financial records — i.e. no acceptance fixture remains.

### 7.2 Not re-driven in the browser this turn

- Prepare → review → close of a reconciliation run. It is covered by `Stage4dReconciliationTest`, but driving it in the preview would create a persistent `reconciliation_runs` row and there is no delete endpoint to clean it up. It is therefore **not** re-verified in the browser here.
- CSV download through the browser. Covered by the automated export tests; not clicked in the browser this turn.

The earlier Stage 4D session reported browser verification of the financial pages and the reconciliation lifecycle. That report is not treated as evidence for this turn; only §7.1 is evidence produced now.

---

## 8. Security and permission controls

- Every Stage 4D route carries `auth:sanctum`, `EnsureStaffAccount` and a `permission:<slug>` middleware.
- 13 granular permissions, granted to `super-admin` only. `admin` receives none.
- Read and export are separate: export requires the report read permission **and** `financials.reports.export`.
- Reconciliation view / prepare / review are three separate permissions; the automated permission test asserts each boundary.
- Contact details (email/phone) are withheld unless the caller holds the payer-specific financial permission.
- CSV cells are escaped against formula injection.
- Reconciliation runs are append-only: closed runs cannot be re-reviewed or edited.
- Reports are read-only; the only writes are the run row and two audit entries.
- No secret, credential or settlement value is read or written by Stage 4D code.

---

## 9. Regression safeguards for Stages 4A–4C

- No Stage 4A–4C service, model, policy, ledger or table was modified. Stage 4D adds a namespace (`Services/Reporting`), two controllers, routes, a migration and frontend files.
- All Stage 4A–4C tests run in the same suite and pass on SQLite and MySQL (549/549 both).
- Stage 4D derives balances from the existing tables; it does not create a second balance, ledger or snapshot of financial state (the only stored snapshot is the reconciliation run’s JSON evidence).
- Credit attribution is parity-tested against `CustomerCreditReversalService::remainingReversibleMinor()`.
- The migration is additive and guarded (`hasTable`, `getIndexes`), and its `down()` is limited to what it created.
- The reporting tests assert that refunds count as returned money only when `confirmed`, and that pending money is never treated as received.

---

## 10. Environment validation — GD

### 10.1 What was found

- `ChatService` calls `getimagesize`, `imagecreatefromjpeg/png/gif/webp`, `imagecopyresampled`, `imagejpeg/png/gif/webp`. GD is a **runtime** requirement for chat attachments, not a test-only dependency.
- The environment’s `Dockerfile.backend` installed only `pdo_mysql` and `bcmath`. GD had been installed by hand in the running container, which does not survive container recreation or a fresh environment.
- The seven image-upload tests failed in that situation; they pass once GD exists.

### 10.2 Change authored

`Dockerfile.backend` (environment configuration repository) now installs GD with freetype, jpeg and webp support:

```dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql bcmath gd \
    && rm -rf /var/lib/apt/lists/*
```

`BASE44-SETUP.md` was updated to document the extension, why it is a runtime requirement, and how to verify it.

### 10.3 Local validation performed

- `docker compose ... build api` succeeded.
- `docker run --rm app-api php -m` → `bcmath`, `gd`, `pdo_mysql`.
- `gd_info()` → `bool(true)`, `GD Version: bundled (2.1.0 compatible)`.
- The `api` container was recreated from the new image; inside it `php -r 'extension_loaded("gd")'` → `gd:present`.
- The seven image-upload tests passed in the recreated container (§5.4).
- The full suite passed again on the recreated container (§5.1).

### 10.4 Persistence — blocked, pending

The change could **not** be published to the shared environment configuration from this sandbox:

- `configure_environment` returned HTTP 409 `Configuration changed or was not inspected` on every attempt: three attempts with `expected_commit = 2603c1c` (the clean, detached HEAD of the local configuration clone), including one immediately after a fresh `inspect_environment`.
- `inspect_environment` reports `Environment validation: idle` and `Last environment validation failure: none`, but its result (20,017 characters) is truncated at 10,000 characters, so the saved configuration commit it requires as `expected_commit` is not readable from the tool output.
- The configuration clone is shallow and detached at `2603c1c`; its recorded remote (`https://…artifacts.cloudflare.net/git/prod/environment-…`) returns HTTP 404, so the sandbox cannot re-fetch the published revision.
- Omitting `expected_commit` is rejected by validation (it is the “initial setup” path and still conflicts).

Consequently:

- **Validated locally:** yes — the built image contains GD and the affected tests pass on it.
- **Persistent in a fresh environment:** **not confirmed**. The image definition change is authored but unpublished; a new environment created from the last published revision would still lack GD.
- The change is left **pending owner action**: publish the `Dockerfile.backend` change through the environment configuration once the configuration repository can be inspected/published from a working sandbox.

No application source change was made for GD, and no environment change was deployed or merged.

---

## 11. Outstanding limitations and unresolved decisions

Retrieved from `docs/STAGE-4D-DECISION-REGISTER.md` (authoritative register: `docs/STAGE-4D-OWNER-DECISION-REGISTER.md`).

| Decision | Status | Effect |
| --- | --- | --- |
| D4 — settlement data source | No feed; document-only tier implemented. Automated tier open. | Settlement reconciliation unavailable |
| D5 — gateway fee handling | Not exercised | No fee table, no fee or net-settlement figure |
| D7 — default date basis | Labelled conservative default | Each report states its basis; owner has not confirmed |
| D8 — unallocated ageing | Labelled conservative default (receipt date; 0–7/8–30/31–60/61–90/90+) | Owner has not confirmed |
| D10 — legacy treatment | Included, not rewritten; E-08 surfaced | Delivery-fee subset column and per-row legacy badge not added |
| D12 — reconciliation approval separation | Not exercised | No monetary threshold or second-approver rule; only prepare/review separation |
| D13 — statement retention | Not exercised | No statement import or storage |
| D14 — multi-currency | Resolved: strictly per currency | No cross-currency totals |

Other limitations:

- Settlement reporting has no figures, import, fee, deposit or payout data.
- Reconciliation exceptions are counts; there is no reconciliation CSV.
- Reconciliation runs are manual and point-in-time; there is no scheduler.
- Order receipts are excluded from receipt reconciliation by design.

---

## 12. Work not performed

- No external settlement reconciliation, statement import, fee calculation or payout matching.
- No change to Stage 4A–4C business rules, ledgers, models or migrations.
- No merge, deployment, publishing or Stage 5 work.
- No environment configuration publication (blocked, §10.4).
- No acceptance fixture or temporary financial record was created; the preview database shows zero figures.
- The disposable MySQL test database was dropped after the run.

---

## 13. Completion status

| Area | Status | Evidence |
| --- | --- | --- |
| 1. Implemented | Yes | §2–§4, commit `d45ded5` |
| 2. Automated-test verified | Yes | 549 tests / 3278 assertions, SQLite and MySQL; 7 image-upload tests / 43 assertions |
| 3. MySQL verified | Yes | MySQL 8.4.13, `special_delicacies_stage4d_test`, 549/3278 |
| 4. Browser verified | Partial | §7.1: three financial screens render signed in; reconciliation prepare/review not re-driven in the browser |
| 5. Documentation completed | Yes | `docs/STAGE-4D-API.md`, `docs/STAGE-4D-RECONCILIATION-GUIDE.md`, this report |
| 6. Environment validated and persistent | No — local only, pending | GD validated in the built image; publication blocked (§10.4) |
| 7. Pending owner decisions | Yes | D4 automated tier, D5, D7, D8, D10 (subset/badge), D12, D13 |
| 8. Not completed or blocked | Yes | Settlement reconciliation; environment publication; browser lifecycle re-verification |
