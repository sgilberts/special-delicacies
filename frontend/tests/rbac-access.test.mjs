import assert from "node:assert/strict";
import test from "node:test";
import { getStaffSecurityVisibility, hasAnyPermission } from "../src/lib/permissionAccess.ts";

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
