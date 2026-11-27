import type {
  CustomerFilters,
  CustomerFormValues,
  CustomerStatus,
  CustomerType,
} from "@/types/customers";

/**
 * Pure customer-management rules shared by the page, its modals and the tests.
 * Keeping them here means permission visibility, query building, validation and
 * formatting are exercised without a browser.
 */

export const CUSTOMER_PAGE_SIZE = 15;

export const CUSTOMER_PERMISSIONS = {
  view: "customers.view",
  create: "customers.create",
  update: "customers.update",
  archive: "customers.archive",
  restore: "customers.restore",
  viewFinancials: "customers.view_financials",
} as const;

export interface CustomerCapabilities {
  canView: boolean;
  canCreate: boolean;
  canUpdate: boolean;
  canArchive: boolean;
  canRestore: boolean;
  canViewFinancials: boolean;
}

/**
 * Frontend visibility only: the backend middleware is the enforcement point for
 * every one of these capabilities.
 */
export function customerCapabilities(
  permissions: readonly string[] | null | undefined,
  roles: readonly string[] | null | undefined = [],
): CustomerCapabilities {
  const isSuperAdmin = roles?.includes("super-admin") ?? false;
  const granted = (slug: string) => isSuperAdmin || (permissions ?? []).includes(slug);

  return {
    canView: granted(CUSTOMER_PERMISSIONS.view),
    canCreate: granted(CUSTOMER_PERMISSIONS.create),
    canUpdate: granted(CUSTOMER_PERMISSIONS.update),
    canArchive: granted(CUSTOMER_PERMISSIONS.archive),
    canRestore: granted(CUSTOMER_PERMISSIONS.restore),
    canViewFinancials: granted(CUSTOMER_PERMISSIONS.viewFinancials),
  };
}

export const DEFAULT_CUSTOMER_FILTERS: CustomerFilters = {
  search: "",
  status: "",
  type: "",
  sort: "joined",
  direction: "desc",
};

/** Server-side query parameters: every filter is applied by the API, not the page. */
export function customerQuery(
  filters: CustomerFilters,
  page: number,
  perPage: number = CUSTOMER_PAGE_SIZE,
): Record<string, string | number> {
  const params: Record<string, string | number> = {
    page: Math.max(1, page),
    per_page: perPage,
    sort: filters.sort,
    direction: filters.direction,
  };

  const search = filters.search.trim();
  if (search !== "") params.search = search;
  if (filters.status !== "") params.status = filters.status;
  if (filters.type !== "") params.type = filters.type;

  return params;
}

export function emptyCustomerForm(): CustomerFormValues {
  return { name: "", email: "", phone: "" };
}

export function customerFormFrom(customer: { name: string; email: string | null; phone: string }): CustomerFormValues {
  return { name: customer.name, email: customer.email ?? "", phone: customer.phone };
}

/** Mirrors the backend rules so obvious mistakes never reach the API. */
export function validateCustomerForm(form: CustomerFormValues): Partial<Record<keyof CustomerFormValues, string>> {
  const errors: Partial<Record<keyof CustomerFormValues, string>> = {};
  const name = form.name.trim();
  const email = form.email.trim();
  const phone = form.phone.trim();

  if (name.length < 2) errors.name = "Enter the customer's full name (at least 2 characters).";
  else if (name.length > 120) errors.name = "The name may not be longer than 120 characters.";

  if (email !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.email = "Enter a valid email address, or leave it blank.";
  } else if (email.length > 255) {
    errors.email = "The email address may not be longer than 255 characters.";
  }

  if (phone === "") errors.phone = "A telephone number is required.";
  else if (phone.length < 5 || phone.length > 30) errors.phone = "Enter a telephone number between 5 and 30 characters.";
  else if (!/^[0-9+().\-\s]+$/.test(phone)) errors.phone = "Use only digits, spaces and + ( ) . - in the telephone number.";

  return errors;
}

export function customerFormPayload(form: CustomerFormValues): CustomerFormValues {
  return { name: form.name.trim(), email: form.email.trim(), phone: form.phone.trim() };
}

/** Map a 422 payload onto field-level messages. */
export function customerFieldErrors(
  response: { errors?: Record<string, string[] | string> } | undefined,
): Partial<Record<keyof CustomerFormValues, string>> {
  const mapped: Partial<Record<keyof CustomerFormValues, string>> = {};
  const errors = response?.errors ?? {};

  for (const field of ["name", "email", "phone"] as const) {
    const value = errors[field];
    if (Array.isArray(value) && value.length > 0) mapped[field] = String(value[0]);
    else if (typeof value === "string" && value !== "") mapped[field] = value;
  }

  return mapped;
}

export function customerStatusLabel(status: CustomerStatus): string {
  if (status === "archived") return "Archived";
  if (status === "inactive") return "Inactive";
  return "Active";
}

export function customerStatusTone(status: CustomerStatus): string {
  if (status === "archived") return "border-[var(--color-border)] text-[var(--color-text-secondary)] bg-[var(--color-surface-secondary)]";
  if (status === "inactive") return "border-amber-300 text-amber-800 bg-amber-50 dark:border-amber-800 dark:text-amber-200 dark:bg-amber-950/40";
  return "border-emerald-300 text-emerald-800 bg-emerald-50 dark:border-emerald-800 dark:text-emerald-200 dark:bg-emerald-950/40";
}

export function customerTypeLabel(type: CustomerType): string {
  return type === "organisation" ? "Organisation" : "Individual";
}

export function activityLabel(action: string): string {
  const labels: Record<string, string> = {
    created: "Customer record created",
    registered: "Registered an account",
    updated: "Profile updated",
    profile_updated: "Updated their own profile",
    archived: "Customer archived",
    restored: "Customer restored",
    "customer.created": "Customer record created",
    "customer.updated": "Profile updated by staff",
    "customer.archived": "Customer archived",
    "customer.restored": "Customer restored",
  };

  return labels[action] ?? action.replace(/[._]/g, " ");
}

export function formatCustomerDate(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

export function formatCustomerDateTime(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleString(undefined, { year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
}

export function formatCustomerMoney(currency: string, amount: string | null | undefined): string {
  if (amount === null || amount === undefined || amount === "") return "—";
  const numeric = Number(amount);
  if (Number.isNaN(numeric)) return `${currency} ${amount}`;
  return `${currency} ${numeric.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Page numbers for the pagination footer, current page centred. */
export function pageWindow(current: number, last: number, size = 5): number[] {
  if (last <= 0) return [];
  const width = Math.min(size, last);
  let start = Math.max(1, current - Math.floor(width / 2));
  const end = Math.min(last, start + width - 1);
  start = Math.max(1, end - width + 1);

  const pages: number[] = [];
  for (let page = start; page <= end; page += 1) pages.push(page);
  return pages;
}

/** The archive action is only offered for a live record; restore only for an archived one. */
export function customerLifecycleAction(
  status: CustomerStatus,
  capabilities: CustomerCapabilities,
): "archive" | "restore" | null {
  if (status === "archived") return capabilities.canRestore ? "restore" : null;
  return capabilities.canArchive ? "archive" : null;
}

/** Financial figures are requested only when the administrator may see them. */
export function shouldLoadFinancials(capabilities: CustomerCapabilities, modalOpen: boolean): boolean {
  return modalOpen && capabilities.canViewFinancials;
}

export function customerEmptyStateMessage(hasFilters: boolean): string {
  return hasFilters
    ? "No customers match these filters. Adjust the search or filters to see more."
    : "No customers have been recorded yet.";
}

export function totalCountLabel(count: number): string {
  return `${count} ${count === 1 ? "customer" : "customers"}`;
}
