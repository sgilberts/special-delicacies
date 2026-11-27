import type {
  MembershipRole,
  MembershipStatus,
  OrganisationFilters,
  OrganisationFormValues,
  OrganisationStatus,
} from "@/types/organisations";

/**
 * Pure organisation-management rules shared by the page, its modals and the
 * tests. Permission visibility, query building, validation and formatting are
 * exercised without a browser; the backend middleware remains the enforcement
 * point for every capability.
 */

export const ORGANISATION_PAGE_SIZE = 15;
export const ORGANISATION_MEMBER_PAGE_SIZE = 10;

export const ORGANISATION_PERMISSIONS = {
  view: "organisations.view",
  create: "organisations.create",
  update: "organisations.update",
  archive: "organisations.archive",
  restore: "organisations.restore",
  manageMembers: "organisations.manage_members",
  viewFinancials: "organisations.view_financials",
} as const;

export interface OrganisationCapabilities {
  canView: boolean;
  canCreate: boolean;
  canUpdate: boolean;
  canArchive: boolean;
  canRestore: boolean;
  canManageMembers: boolean;
  canViewFinancials: boolean;
}

export function organisationCapabilities(
  permissions: readonly string[] | null | undefined,
  roles: readonly string[] | null | undefined = [],
): OrganisationCapabilities {
  const isSuperAdmin = roles?.includes("super-admin") ?? false;
  const granted = (slug: string) => isSuperAdmin || (permissions ?? []).includes(slug);

  return {
    canView: granted(ORGANISATION_PERMISSIONS.view),
    canCreate: granted(ORGANISATION_PERMISSIONS.create),
    canUpdate: granted(ORGANISATION_PERMISSIONS.update),
    canArchive: granted(ORGANISATION_PERMISSIONS.archive),
    canRestore: granted(ORGANISATION_PERMISSIONS.restore),
    canManageMembers: granted(ORGANISATION_PERMISSIONS.manageMembers),
    canViewFinancials: granted(ORGANISATION_PERMISSIONS.viewFinancials),
  };
}

export const DEFAULT_ORGANISATION_FILTERS: OrganisationFilters = {
  search: "",
  status: "",
  membershipStatus: "",
  sort: "created",
  direction: "desc",
};

/** Query string parameters for the server-side listing. */
export function organisationQuery(filters: OrganisationFilters, page: number): Record<string, string | number> {
  const query: Record<string, string | number> = {
    page,
    per_page: ORGANISATION_PAGE_SIZE,
    sort: filters.sort,
    direction: filters.direction,
  };

  if (filters.search.trim() !== "") query.search = filters.search.trim();
  if (filters.status !== "") query.status = filters.status;
  if (filters.membershipStatus !== "") query.membership_status = filters.membershipStatus;

  return query;
}

export function emptyOrganisationForm(): OrganisationFormValues {
  return { name: "", owner_customer_id: "", description: "", business_email: "", business_phone: "", address: "" };
}

export function organisationFormFrom(detail: {
  name: string;
  owner: { id: number } | null;
  description: string | null;
  business_email: string | null;
  business_phone: string | null;
  address: string | null;
}): OrganisationFormValues {
  return {
    name: detail.name,
    owner_customer_id: detail.owner ? String(detail.owner.id) : "",
    description: detail.description ?? "",
    business_email: detail.business_email ?? "",
    business_phone: detail.business_phone ?? "",
    address: detail.address ?? "",
  };
}

export function validateOrganisationForm(form: OrganisationFormValues, mode: "create" | "edit"): Partial<Record<keyof OrganisationFormValues, string>> {
  const errors: Partial<Record<keyof OrganisationFormValues, string>> = {};
  const name = form.name.trim();
  const email = form.business_email.trim();
  const phone = form.business_phone.trim();

  if (name.length < 2) errors.name = "Enter the organisation name (at least 2 characters).";
  else if (name.length > 160) errors.name = "The name may not be longer than 160 characters.";

  if (mode === "create" && form.owner_customer_id.trim() === "") {
    errors.owner_customer_id = "Select the primary contact for this organisation.";
  }

  if (email !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.business_email = "Enter a valid email address, or leave it blank.";
  } else if (email.length > 255) {
    errors.business_email = "The email address may not be longer than 255 characters.";
  }

  if (phone !== "") {
    if (phone.length < 5 || phone.length > 30) errors.business_phone = "Enter a telephone number between 5 and 30 characters.";
    else if (!/^[0-9+().\-\s]+$/.test(phone)) errors.business_phone = "Use only digits, spaces and + ( ) . - in the telephone number.";
  }

  return errors;
}

export function organisationFormPayload(form: OrganisationFormValues, mode: "create" | "edit"): Record<string, string | number | null> {
  const payload: Record<string, string | number | null> = {
    name: form.name.trim(),
    description: form.description.trim() === "" ? null : form.description.trim(),
    business_email: form.business_email.trim() === "" ? null : form.business_email.trim(),
    business_phone: form.business_phone.trim() === "" ? null : form.business_phone.trim(),
    address: form.address.trim() === "" ? null : form.address.trim(),
  };

  if (mode === "create") payload.owner_customer_id = Number(form.owner_customer_id);

  return payload;
}

/** Map a 422 payload onto field-level messages. */
export function organisationFieldErrors(
  response: { errors?: Record<string, string[] | string> } | undefined,
): Partial<Record<keyof OrganisationFormValues, string>> {
  const mapped: Partial<Record<keyof OrganisationFormValues, string>> = {};
  const errors = response?.errors ?? {};

  for (const field of ["name", "owner_customer_id", "business_email", "business_phone", "description", "address"] as const) {
    const value = errors[field];
    if (Array.isArray(value) && value.length > 0) mapped[field] = String(value[0]);
    else if (typeof value === "string" && value !== "") mapped[field] = value;
  }

  return mapped;
}

export function organisationStatusLabel(status: OrganisationStatus): string {
  if (status === "archived") return "Archived";
  if (status === "inactive") return "Suspended";
  return "Active";
}

export function organisationStatusTone(status: OrganisationStatus): string {
  if (status === "archived") return "border-[var(--color-border)] text-[var(--color-text-secondary)] bg-[var(--color-surface-secondary)]";
  if (status === "inactive") return "border-amber-300 text-amber-800 bg-amber-50 dark:border-amber-800 dark:text-amber-200 dark:bg-amber-950/40";
  return "border-emerald-300 text-emerald-800 bg-emerald-50 dark:border-emerald-800 dark:text-emerald-200 dark:bg-emerald-950/40";
}

export function membershipStatusLabel(status: MembershipStatus | string): string {
  const labels: Record<string, string> = { active: "Active", pending: "Pending", suspended: "Suspended", removed: "Removed" };
  return labels[status] ?? status;
}

export function membershipStatusTone(status: MembershipStatus | string): string {
  if (status === "active") return "border-emerald-300 text-emerald-800 bg-emerald-50 dark:border-emerald-800 dark:text-emerald-200 dark:bg-emerald-950/40";
  if (status === "pending") return "border-sky-300 text-sky-800 bg-sky-50 dark:border-sky-800 dark:text-sky-200 dark:bg-sky-950/40";
  if (status === "suspended") return "border-amber-300 text-amber-800 bg-amber-50 dark:border-amber-800 dark:text-amber-200 dark:bg-amber-950/40";
  return "border-[var(--color-border)] text-[var(--color-text-secondary)] bg-[var(--color-surface-secondary)]";
}

export function membershipRoleLabel(role: MembershipRole | string): string {
  const labels: Record<string, string> = { owner: "Owner", administrator: "Administrator", manager: "Manager", member: "Member" };
  return labels[role] ?? role;
}

export function organisationActivityLabel(action: string): string {
  const labels: Record<string, string> = {
    "organisation.created": "Organisation created",
    "organisation.updated": "Profile updated",
    "organisation.archived": "Organisation archived",
    "organisation.restored": "Organisation restored",
    "organisation.profile_updated": "Profile updated by a member",
    "organisation.membership.status_changed": "Membership status changed",
    "membership.status_changed": "Membership status changed",
    "membership.role_changed": "Membership role changed",
    "membership.financial_access_changed": "Membership financial access changed",
  };

  return labels[action] ?? action.replace(/[._]/g, " ");
}

export function formatOrganisationDate(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

export function formatOrganisationDateTime(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleString(undefined, { year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
}

export function formatOrganisationMoney(currency: string, amount: string | null | undefined): string {
  if (amount === null || amount === undefined || amount === "") return "—";
  const numeric = Number(amount);
  if (Number.isNaN(numeric)) return `${currency} ${amount}`;
  return `${currency} ${numeric.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Archive is only offered for a live record; restore only for an archived one. */
export function organisationLifecycleAction(
  status: OrganisationStatus,
  capabilities: OrganisationCapabilities,
): "archive" | "restore" | null {
  if (status === "archived") return capabilities.canRestore ? "restore" : null;
  return capabilities.canArchive ? "archive" : null;
}

/** Financial figures are requested only when the administrator may see them. */
export function shouldLoadFinancials(capabilities: OrganisationCapabilities, modalOpen: boolean): boolean {
  return modalOpen && capabilities.canViewFinancials;
}

export function organisationEmptyStateMessage(hasFilters: boolean): string {
  return hasFilters
    ? "No organisations match these filters. Adjust the search or filters to see more."
    : "No organisations have been recorded yet.";
}

export function organisationCountLabel(count: number): string {
  return `${count} ${count === 1 ? "organisation" : "organisations"}`;
}
