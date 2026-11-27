import assert from "node:assert/strict";
import test from "node:test";
import { getStaffSecurityVisibility, hasAnyPermission } from "../src/lib/permissionAccess.ts";
import { globalLoadErrorVisible, httpStatusOf, sectionFailureState, showsEmptyState } from "../src/lib/sectionLoad.ts";

test("staff management never exposes Roles & Permissions", () => {
  const permissions = ["staff.view", "staff.manage"];
  const visibility = getStaffSecurityVisibility(permissions);

  assert.deepEqual(visibility, { staffAccounts: true, rolesAndPermissions: false });
  assert.equal(hasAnyPermission(permissions, ["roles.manage"]), false);
});

test("roles management does not expose Staff Accounts", () => {
  const visibility = getStaffSecurityVisibility(["roles.manage"]);

  assert.deepEqual(visibility, { staffAccounts: false, rolesAndPermissions: true });
  assert.equal(hasAnyPermission(["roles.manage"], ["staff.view", "staff.manage"]), false);
});

test("both navigation areas appear only when their own permissions are granted", () => {
  assert.deepEqual(
    getStaffSecurityVisibility(["staff.manage", "roles.manage"]),
    { staffAccounts: true, rolesAndPermissions: true },
  );
  assert.deepEqual(
    getStaffSecurityVisibility([]),
    { staffAccounts: false, rolesAndPermissions: false },
  );
});

test("view-only staff permission does not enable staff mutations or role access", () => {
  const permissions = ["staff.view"];

  assert.equal(getStaffSecurityVisibility(permissions).staffAccounts, true);
  assert.equal(hasAnyPermission(permissions, ["staff.manage"]), false);
  assert.equal(hasAnyPermission(permissions, ["roles.manage"]), false);
});

test("SUPER ADMIN role satisfies protected allocation and commercial document permissions", () => {
  const permissions = [];
  const roles = ["super-admin"];

  assert.equal(hasAnyPermission(permissions, ["financials.allocations.view", "financials.allocations.manage"], roles), true);
  assert.equal(hasAnyPermission(permissions, ["commercial_documents.view"], roles), true);
  assert.equal(hasAnyPermission(permissions, ["financials.allocations.manage"], roles), true);
  assert.equal(hasAnyPermission(permissions, ["financials.allocations.reverse"], roles), true);
});

test("ordinary staff still require each explicit allocation permission", () => {
  const roles = ["admin"];

  assert.equal(hasAnyPermission([], ["financials.allocations.view", "financials.allocations.manage"], roles), false);
  assert.equal(hasAnyPermission(["financials.allocations.view"], ["financials.allocations.view", "financials.allocations.manage"], roles), true);
  assert.equal(hasAnyPermission(["financials.allocations.view"], ["financials.allocations.manage"], roles), false);
  assert.equal(hasAnyPermission(["financials.allocations.view"], ["financials.allocations.reverse"], roles), false);
});

test("deposit and customer-credit screens require their own granular staff permissions", () => {
  assert.equal(hasAnyPermission([], ["financials.deposits.view", "financials.deposits.manage"]), false);
  assert.equal(hasAnyPermission(["financials.deposits.view"], ["financials.deposits.view", "financials.deposits.manage"]), true);
  assert.equal(hasAnyPermission(["financials.deposits.view"], ["financials.customer_credit.manage"]), false);
  assert.equal(hasAnyPermission(["financials.customer_credit.view"], ["financials.customer_credit.manage"]), false);
  assert.equal(hasAnyPermission([], ["financials.customer_credit.manage"], ["super-admin"]), true);
});

test("a denied customer-credit section is an access decision, not a load failure", () => {
  assert.equal(sectionFailureState({ response: { status: 403 } }), "forbidden");
  assert.equal(sectionFailureState({ response: { status: 401 } }), "forbidden");
});

test("server and network failures keep the section in its error state", () => {
  assert.equal(sectionFailureState({ response: { status: 500 } }), "error");
  assert.equal(sectionFailureState({ response: { status: 422 } }), "error");
  assert.equal(sectionFailureState(new Error("Network Error")), "error");
  assert.equal(sectionFailureState(undefined), "error");
});

test("empty financial states render only after the section actually loaded", () => {
  assert.equal(showsEmptyState("ready"), true);
  for (const state of ["loading", "forbidden", "error"]) assert.equal(showsEmptyState(state), false);
});

test("section failure classification reads the HTTP status safely", () => {
  assert.equal(httpStatusOf({ response: { status: 403 } }), 403);
  assert.equal(httpStatusOf({ response: {} }), undefined);
  assert.equal(httpStatusOf(null), undefined);
});

test("the page-level load error is reserved for a genuine outage", () => {
  const all = (...states) => states.map((state) => ({ requested: true, state }));
  assert.equal(globalLoadErrorVisible(all("error", "error", "error", "error")), true);
  assert.equal(globalLoadErrorVisible(all("error", "ready", "ready", "ready")), false);
  assert.equal(globalLoadErrorVisible(all("forbidden", "forbidden", "forbidden", "forbidden")), false);
  assert.equal(globalLoadErrorVisible(all("ready", "ready", "ready", "ready")), false);
  assert.equal(globalLoadErrorVisible([{ requested: false, state: "ready" }]), false);
  assert.equal(globalLoadErrorVisible([]), false);
});
