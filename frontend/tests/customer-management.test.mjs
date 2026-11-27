import assert from "node:assert/strict";
import test from "node:test";
import {
  CUSTOMER_PAGE_SIZE,
  DEFAULT_CUSTOMER_FILTERS,
  activityLabel,
  customerCapabilities,
  customerEmptyStateMessage,
  customerFieldErrors,
  customerFormFrom,
  customerFormPayload,
  customerLifecycleAction,
  customerQuery,
  customerStatusLabel,
  customerTypeLabel,
  emptyCustomerForm,
  formatCustomerMoney,
  pageWindow,
  shouldLoadFinancials,
  totalCountLabel,
  validateCustomerForm,
} from "../src/lib/customers.ts";

// ---------------------------------------------------------------- permissions

test("super admin satisfies every customer capability", () => {
  const capabilities = customerCapabilities([], ["super-admin"]);

  assert.equal(capabilities.canView, true);
  assert.equal(capabilities.canCreate, true);
  assert.equal(capabilities.canUpdate, true);
  assert.equal(capabilities.canArchive, true);
  assert.equal(capabilities.canRestore, true);
  assert.equal(capabilities.canViewFinancials, true);
});

test("customer-view permission alone does not grant mutations or financials", () => {
  const capabilities = customerCapabilities(["customers.view"], ["admin"]);

  assert.equal(capabilities.canView, true);
  assert.equal(capabilities.canCreate, false);
  assert.equal(capabilities.canUpdate, false);
  assert.equal(capabilities.canArchive, false);
  assert.equal(capabilities.canRestore, false);
  assert.equal(capabilities.canViewFinancials, false);
});

test("an ordinary administrator with no customer permissions sees nothing", () => {
  const capabilities = customerCapabilities(["staff.view", "orders.view"], ["admin"]);

  assert.deepEqual(Object.values(capabilities), [false, false, false, false, false, false]);
});

test("financial visibility is its own capability", () => {
  const withFinancials = customerCapabilities(["customers.view", "customers.view_financials"]);
  const withoutFinancials = customerCapabilities(["customers.view", "customers.update"]);

  assert.equal(withFinancials.canViewFinancials, true);
  assert.equal(withoutFinancials.canViewFinancials, false);
});

// -------------------------------------------------------------------- queries

test("search and filters are sent to the API, not applied to the visible page", () => {
  const params = customerQuery(
    { search: "  ama  ", status: "archived", type: "organisation", sort: "name", direction: "asc" },
    3,
  );

  assert.deepEqual(params, {
    page: 3,
    per_page: CUSTOMER_PAGE_SIZE,
    sort: "name",
    direction: "asc",
    search: "ama",
    status: "archived",
    type: "organisation",
  });
});

test("empty filters are omitted so the API applies its own defaults", () => {
  const params = customerQuery(DEFAULT_CUSTOMER_FILTERS, 1);

  assert.equal("search" in params, false);
  assert.equal("status" in params, false);
  assert.equal("type" in params, false);
  assert.equal(params.page, 1);
  assert.equal(params.sort, "joined");
  assert.equal(params.direction, "desc");
});

test("pagination windows stay inside the page range and centre the current page", () => {
  assert.deepEqual(pageWindow(1, 1), [1]);
  assert.deepEqual(pageWindow(1, 3), [1, 2, 3]);
  assert.deepEqual(pageWindow(5, 10), [3, 4, 5, 6, 7]);
  assert.deepEqual(pageWindow(10, 10), [6, 7, 8, 9, 10]);
  assert.deepEqual(pageWindow(2, 10), [1, 2, 3, 4, 5]);
  assert.deepEqual(pageWindow(1, 0), []);
});

// ----------------------------------------------------------------------- form

test("a new customer form starts empty and requires a name and phone number", () => {
  const errors = validateCustomerForm(emptyCustomerForm());

  assert.equal(errors.name !== undefined, true);
  assert.equal(errors.phone !== undefined, true);
  assert.equal(errors.email, undefined, "email is optional");
});

test("the customer form rejects malformed contact details", () => {
  const errors = validateCustomerForm({ name: "A", email: "not-an-email", phone: "abc" });

  assert.equal(errors.name !== undefined, true);
  assert.equal(errors.email !== undefined, true);
  assert.equal(errors.phone !== undefined, true);
});

test("a well-formed customer form passes and is trimmed for submission", () => {
  const form = { name: "  Ama Mensah  ", email: " ama@example.com ", phone: " 024 400 0000 " };

  assert.deepEqual(validateCustomerForm(form), {});
  assert.deepEqual(customerFormPayload(form), {
    name: "Ama Mensah",
    email: "ama@example.com",
    phone: "024 400 0000",
  });
});

test("the edit form is prefilled from the existing customer", () => {
  assert.deepEqual(
    customerFormFrom({ name: "Kofi Boateng", email: null, phone: "+233244000111" }),
    { name: "Kofi Boateng", email: "", phone: "+233244000111" },
  );
});

test("backend field errors are mapped onto the matching form fields", () => {
  const mapped = customerFieldErrors({
    errors: { email: ["Another customer already uses this email address."], phone: "Enter a valid Ghanaian phone number." },
  });

  assert.equal(mapped.email, "Another customer already uses this email address.");
  assert.equal(mapped.phone, "Enter a valid Ghanaian phone number.");
  assert.equal(mapped.name, undefined);
  assert.deepEqual(customerFieldErrors(undefined), {});
});

// -------------------------------------------------------------- presentation

test("status and type labels describe the record truthfully", () => {
  assert.equal(customerStatusLabel("active"), "Active");
  assert.equal(customerStatusLabel("inactive"), "Inactive");
  assert.equal(customerStatusLabel("archived"), "Archived");
  assert.equal(customerTypeLabel("individual"), "Individual");
  assert.equal(customerTypeLabel("organisation"), "Organisation");
});

test("empty states distinguish no records from no matches", () => {
  assert.match(customerEmptyStateMessage(false), /have been recorded yet/);
  assert.match(customerEmptyStateMessage(true), /match these filters/);
});

test("the customer count is pluralised correctly", () => {
  assert.equal(totalCountLabel(1), "1 customer");
  assert.equal(totalCountLabel(0), "0 customers");
  assert.equal(totalCountLabel(12), "12 customers");
});

test("financial amounts render with the reporting currency", () => {
  assert.equal(formatCustomerMoney("GHS", "1250.5"), "GHS 1,250.50");
  assert.equal(formatCustomerMoney("GHS", "0.00"), "GHS 0.00");
  assert.equal(formatCustomerMoney("GHS", null), "—");
});

test("audit actions are described in plain language", () => {
  assert.equal(activityLabel("created"), "Customer record created");
  assert.equal(activityLabel("profile_updated"), "Updated their own profile");
  assert.equal(activityLabel("customer.archived"), "Customer archived");
  assert.equal(activityLabel("something_new"), "something new");
});

// ------------------------------------------------------------- modal gating

test("the profile modal never requests financials without the financial permission", () => {
  assert.equal(shouldLoadFinancials(customerCapabilities(["customers.view"]), true), false);
  assert.equal(shouldLoadFinancials(customerCapabilities(["customers.view", "customers.view_financials"]), true), true);
  assert.equal(shouldLoadFinancials(customerCapabilities([], ["super-admin"]), true), true);
  assert.equal(shouldLoadFinancials(customerCapabilities(["customers.view_financials"]), false), false);
});

// ----------------------------------------------------------------- lifecycle

test("archive and restore are offered only for the matching state and permission", () => {
  const full = customerCapabilities([], ["super-admin"]);
  const viewOnly = customerCapabilities(["customers.view"]);

  assert.equal(customerLifecycleAction("active", full), "archive");
  assert.equal(customerLifecycleAction("inactive", full), "archive");
  assert.equal(customerLifecycleAction("archived", full), "restore");
  assert.equal(customerLifecycleAction("active", viewOnly), null);
  assert.equal(customerLifecycleAction("archived", viewOnly), null);

  assert.equal(customerLifecycleAction("active", customerCapabilities(["customers.archive"])), "archive");
  assert.equal(customerLifecycleAction("archived", customerCapabilities(["customers.archive"])), null);
  assert.equal(customerLifecycleAction("archived", customerCapabilities(["customers.restore"])), "restore");
});
