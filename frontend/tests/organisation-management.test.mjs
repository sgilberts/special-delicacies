import assert from "node:assert/strict";
import test from "node:test";
import {
  ORGANISATION_MEMBER_PAGE_SIZE,
  ORGANISATION_PAGE_SIZE,
  DEFAULT_ORGANISATION_FILTERS,
  emptyOrganisationForm,
  formatOrganisationDate,
  formatOrganisationMoney,
  membershipRoleLabel,
  membershipStatusLabel,
  organisationActivityLabel,
  organisationCapabilities,
  organisationCountLabel,
  organisationEmptyStateMessage,
  organisationFieldErrors,
  organisationFormFrom,
  organisationFormPayload,
  organisationLifecycleAction,
  organisationQuery,
  organisationStatusLabel,
  shouldLoadFinancials,
  validateOrganisationForm,
} from "../src/lib/organisations.ts";

// ---------------------------------------------------------------- permissions

test("super admin satisfies every organisation capability", () => {
  const capabilities = organisationCapabilities([], ["super-admin"]);

  assert.deepEqual(capabilities, {
    canView: true,
    canCreate: true,
    canUpdate: true,
    canArchive: true,
    canRestore: true,
    canManageMembers: true,
    canViewFinancials: true,
  });
});

test("organisations.view alone does not grant mutations, members or financials", () => {
  const capabilities = organisationCapabilities(["organisations.view"], ["admin"]);

  assert.equal(capabilities.canView, true);
  assert.equal(capabilities.canCreate, false);
  assert.equal(capabilities.canUpdate, false);
  assert.equal(capabilities.canArchive, false);
  assert.equal(capabilities.canRestore, false);
  assert.equal(capabilities.canManageMembers, false);
  assert.equal(capabilities.canViewFinancials, false);
});

test("financial visibility is independent of profile management", () => {
  const capabilities = organisationCapabilities(
    ["organisations.view", "organisations.update", "organisations.view_financials"],
    ["admin"],
  );

  assert.equal(capabilities.canUpdate, true);
  assert.equal(capabilities.canViewFinancials, true);
  assert.equal(capabilities.canArchive, false);
});

test("customer permissions never leak into organisation capabilities", () => {
  const capabilities = organisationCapabilities(
    ["customers.view", "customers.create", "customers.view_financials"],
    ["admin"],
  );

  assert.equal(capabilities.canView, false);
  assert.equal(capabilities.canCreate, false);
  assert.equal(capabilities.canViewFinancials, false);
});

// ---------------------------------------------------------------- listing query

test("listing query sends every filter to the server", () => {
  const query = organisationQuery(
    { search: "  Adom  ", status: "archived", membershipStatus: "pending", sort: "name", direction: "asc" },
    3,
  );

  assert.deepEqual(query, {
    page: 3,
    per_page: ORGANISATION_PAGE_SIZE,
    sort: "name",
    direction: "asc",
    search: "Adom",
    status: "archived",
    membership_status: "pending",
  });
});

test("blank filters are omitted so the backend applies no hidden constraint", () => {
  const query = organisationQuery(DEFAULT_ORGANISATION_FILTERS, 1);

  assert.equal("search" in query, false);
  assert.equal("status" in query, false);
  assert.equal("membership_status" in query, false);
  assert.equal(query.page, 1);
});

test("member page size is independent of the organisation page size", () => {
  assert.equal(ORGANISATION_MEMBER_PAGE_SIZE, 10);
  assert.ok(ORGANISATION_PAGE_SIZE >= 10);
});

// ---------------------------------------------------------------- validation

test("create requires an owner and a real name", () => {
  const errors = validateOrganisationForm(emptyOrganisationForm(), "create");

  assert.ok(errors.name);
  assert.ok(errors.owner_customer_id);
});

test("edit does not require the owner to be resubmitted", () => {
  const errors = validateOrganisationForm(
    { ...emptyOrganisationForm(), name: "Adom Catering", owner_customer_id: "" },
    "edit",
  );

  assert.equal(errors.owner_customer_id, undefined);
  assert.equal(errors.name, undefined);
});

test("email and phone are validated only when provided", () => {
  const invalid = validateOrganisationForm(
    { ...emptyOrganisationForm(), name: "Adom Catering", owner_customer_id: "1", business_email: "not-an-email", business_phone: "abc" },
    "create",
  );

  assert.ok(invalid.business_email);
  assert.ok(invalid.business_phone);

  const valid = validateOrganisationForm(
    { ...emptyOrganisationForm(), name: "Adom Catering", owner_customer_id: "1", business_email: "", business_phone: "" },
    "create",
  );

  assert.deepEqual(valid, {});
});

test("form payload trims values, nulls blanks and sends the owner only on create", () => {
  const form = {
    name: "  Adom Catering  ",
    owner_customer_id: "12",
    description: "  ",
    business_email: "accounts@example.test",
    business_phone: "",
    address: " 12 Market Road ",
  };

  assert.deepEqual(organisationFormPayload(form, "create"), {
    name: "Adom Catering",
    description: null,
    business_email: "accounts@example.test",
    business_phone: null,
    address: "12 Market Road",
    owner_customer_id: 12,
  });

  assert.equal("owner_customer_id" in organisationFormPayload(form, "edit"), false);
});

test("form is prefilled from the API detail", () => {
  const form = organisationFormFrom({
    name: "Adom Catering",
    owner: { id: 4 },
    description: null,
    business_email: "accounts@example.test",
    business_phone: "+233200000000",
    address: null,
  });

  assert.deepEqual(form, {
    name: "Adom Catering",
    owner_customer_id: "4",
    description: "",
    business_email: "accounts@example.test",
    business_phone: "+233200000000",
    address: "",
  });
});

test("backend field errors map onto the form fields", () => {
  const mapped = organisationFieldErrors({
    errors: {
      name: ["An organisation with this name already exists."],
      business_phone: "Enter a valid Ghanaian phone number.",
      unknown: ["ignored"],
    },
  });

  assert.equal(mapped.name, "An organisation with this name already exists.");
  assert.equal(mapped.business_phone, "Enter a valid Ghanaian phone number.");
  assert.equal("unknown" in mapped, false);
});

// ---------------------------------------------------------------- lifecycle

test("archive is offered only with permission and only for a live record", () => {
  const capabilities = organisationCapabilities(["organisations.view", "organisations.archive"], ["admin"]);

  assert.equal(organisationLifecycleAction("active", capabilities), "archive");
  assert.equal(organisationLifecycleAction("inactive", capabilities), "archive");
  assert.equal(organisationLifecycleAction("archived", capabilities), null);
});

test("restore is offered only for an archived record with permission", () => {
  const capabilities = organisationCapabilities(["organisations.restore"], ["admin"]);

  assert.equal(organisationLifecycleAction("archived", capabilities), "restore");
  assert.equal(organisationLifecycleAction("active", capabilities), null);
});

test("financials are requested only when the modal is open and permission is held", () => {
  const allowed = organisationCapabilities(["organisations.view_financials"], ["admin"]);
  const denied = organisationCapabilities(["organisations.view"], ["admin"]);

  assert.equal(shouldLoadFinancials(allowed, true), true);
  assert.equal(shouldLoadFinancials(allowed, false), false);
  assert.equal(shouldLoadFinancials(denied, true), false);
});

// ---------------------------------------------------------------- presentation

test("status, membership and activity labels are human readable", () => {
  assert.equal(organisationStatusLabel("active"), "Active");
  assert.equal(organisationStatusLabel("inactive"), "Suspended");
  assert.equal(organisationStatusLabel("archived"), "Archived");
  assert.equal(membershipStatusLabel("pending"), "Pending");
  assert.equal(membershipRoleLabel("owner"), "Owner");
  assert.equal(organisationActivityLabel("organisation.membership.status_changed"), "Membership status changed");
  assert.equal(organisationActivityLabel("some.new.action"), "some new action");
});

test("dates and money render safely", () => {
  assert.equal(formatOrganisationDate(null), "—");
  assert.equal(formatOrganisationDate("not-a-date"), "—");
  assert.match(formatOrganisationDate("2026-11-27T10:00:00Z"), /2026/);
  assert.equal(formatOrganisationMoney("GHS", null), "—");
  assert.equal(formatOrganisationMoney("GHS", "100"), "GHS 100.00");
});

test("empty-state copy distinguishes filtered and unfiltered lists", () => {
  assert.match(organisationEmptyStateMessage(true), /match these filters/);
  assert.match(organisationEmptyStateMessage(false), /No organisations/);
  assert.equal(organisationCountLabel(1), "1 organisation");
  assert.equal(organisationCountLabel(3), "3 organisations");
});
