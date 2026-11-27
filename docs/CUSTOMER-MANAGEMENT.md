# Customer Management (Customer Support → Customers)

Administrative customer records: listing, search, filters, pagination, create, edit,
a customer detail modal, and archive/restore with granular RBAC.

## Route and navigation

- Route: `/admin/customers` (lazy-loaded page behind `ProtectedRoute requiredPermissions={["customers.view"]}`).
- Sidebar: **Customer Support → Customers**, shown only to staff holding `customers.view`
  (or the `super-admin` role). The section also holds the chat items, so a customer
  administrator without chat access still sees Customer Support.

## Permissions

| Permission | Capability |
|---|---|
| `customers.view` | List customers, open the detail modal |
| `customers.create` | Create a customer |
| `customers.update` | Edit profile and contact details |
| `customers.archive` | Archive a customer |
| `customers.restore` | Restore an archived customer |
| `customers.view_financials` | Read the customer financial overview |

Registration: created by migration `2026_11_27_000006_add_customer_management` and added
to `RoleAndPermissionSeeder`, so both paths agree.

**Default grants.** `super-admin` holds every permission (existing intrinsic rule). The
default `admin` role deliberately receives **none** of the `customers.*` permissions: the
seeder excludes the whole prefix, matching the migration, which grants only to
`super-admin`. A super-admin grants customer management to a role in
**Staff & Security → Roles & Permissions**. Because the seeder re-applies the `admin`
role's default list on every seed, grants made to `admin` itself must be re-checked after a
re-seed — the same behaviour as the existing `financials.*` and `chat.*` prefixes. Any
wider default is an owner decision.

Enforcement is server-side: every endpoint carries its own `permission:` middleware in
`routes/api.php`, so a direct API call cannot bypass the portal's visibility rules.

## API

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/v1/admin/customers` | `customers.view` |
| POST | `/api/v1/admin/customers` | `customers.create` |
| GET | `/api/v1/admin/customers/{id}` | `customers.view` |
| PUT | `/api/v1/admin/customers/{id}` | `customers.update` |
| POST | `/api/v1/admin/customers/{id}/archive` | `customers.archive` |
| POST | `/api/v1/admin/customers/{id}/restore` | `customers.restore` |
| GET | `/api/v1/admin/customers/{id}/financials` | `customers.view_financials` |

Listing parameters: `search` (name, email, phone — normalized phone variants included),
`status` (`active|inactive|archived`), `type` (`individual|organisation`), `sort`
(`name|joined`), `direction`, `page`, `per_page` (max 100). All of them are applied by the
database query.

There is **no delete endpoint**: `DELETE /api/v1/admin/customers/{id}` is not routed, so
customers with orders, receipts, invoices, refunds or credit-ledger entries cannot be
removed and no cascade is introduced.

## Data model

`customers.archived_at` (nullable timestamp, indexed) is the only schema addition.
Status is derived:

- `archived` — `archived_at` set;
- `inactive` — `archived_at` null and `is_active` false;
- `active` — `archived_at` null and `is_active` true.

Archiving sets `archived_at` and `is_active = false`, so the existing account rules (login
and checkout) already treat the customer as inactive. Restoring clears both. `archived_at`
is not mass assignable; only the archive/restore lifecycle writes it.

Customer type is derived from the existing organisation membership model: a customer with
an active membership is reported as an *organisation* customer, otherwise *individual*.
No customer type column was added.

## Identity rules

`CustomerManagementService` reuses the registration rules: email is trimmed and
lower-cased, phone numbers are normalized with `GhanaPhoneNumber` (`+233…`), duplicates are
detected across stored phone variants and case-insensitive email, and changed contact
details clear the matching verification timestamp. An administrator never chooses a
customer password; a created record receives a random unusable password and the customer
sets their own through the existing password-recovery flow.

## Financial overview

The modal's financial section is loaded from its own endpoint, only when the administrator
holds `customers.view_financials`, and never as part of the profile payload. Figures come
from the existing financial services:

- payments received — succeeded `payment_transactions` attributed to the customer;
- deposits — the Stage 4B deposit/advance query in `DepositService`;
- outstanding invoices — `PaymentAllocationService::invoiceOutstandingMinor()` per issued,
  non-void invoice (allocations, reversals and applied credit included);
- credit balance — `CustomerCreditService::balanceMinor()` from the append-only ledger;
- refunds — confirmed and in-flight `refund_requests`.

Nothing is recalculated in the browser.

## Audit

Every mutation writes a `customer_audit_logs` row (customer timeline) and an
`admin_audit_logs` row carrying the acting staff member, before/after values, IP and user
agent. The modal's activity section merges both, newest first.

## Verification

| Check | Command | Result |
|---|---|---|
| Customer management feature tests | `php artisan test --filter=AdminCustomerManagementTest` | 22 passed, 205 assertions |
| Full backend suite | `php artisan test` | 509 passed, 2878 assertions |
| Frontend customer logic tests | `npm run test:customers` | 19 passed |
| Frontend RBAC tests | `npm run test:rbac` | 12 passed |
| TypeScript | `npx tsc -b` | clean |
| Production build | `npm run build` | built successfully |
| Lint | `npm run lint` | 0 errors (warnings only, matching existing patterns) |

Browser verification (disposable preview database, super-admin): the Customer Support →
Customers nav item is highlighted on the page; the table renders server-paginated rows with
all seven columns; a no-match search shows the empty state; a customer was created through
the modal (phone normalized to `+233…`), the detail modal showed all four sections with
financial figures, the record was edited from the modal, archived with a confirmation that
states financial history is preserved, and restored; the mobile viewport renders the card
layout without sideways scrolling. Console: no errors.

The Deposits & Credit page was re-verified after the section-loading refactor: every section
(receipts, balances, invoices, ledger, staff deposit panel) loads for a super-admin with no
error notices, and a staff account holding only `financials.deposits.view` gets a working
page with just the permitted section — no global load failure and no credit data. The
per-section server-error path is covered by `Stage4bCustomerCreditPageTest`; the temporary
broken receipt row used for the earlier manual reproduction was removed from the disposable
preview database.

Component-level rendering tests are not part of this repository's test setup (it runs
`node --test` over pure TypeScript modules); page and modal rendering is verified in the
browser instead.

## Outstanding owner decisions

- Whether the default `admin` role should receive any `customers.*` permission.
- Whether a future hard-delete-with-guard workflow is wanted (currently archive only).
- Address and other extended profile fields are not part of the customer model; the form and
  modal expose exactly the fields the model supports.
