export function hasAnyPermission(
  grantedPermissions: readonly string[] | null | undefined,
  requiredPermissions: readonly string[],
): boolean {
  return requiredPermissions.some((permission) => grantedPermissions?.includes(permission));
}

export function getStaffSecurityVisibility(grantedPermissions: readonly string[] | null | undefined) {
  return {
    staffAccounts: hasAnyPermission(grantedPermissions, ["staff.view", "staff.manage"]),
    rolesAndPermissions: hasAnyPermission(grantedPermissions, ["roles.manage"]),
  };
}
