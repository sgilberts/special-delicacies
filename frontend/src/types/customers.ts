export type CustomerStatus = "active" | "inactive" | "archived";
export type CustomerType = "individual" | "organisation";
export type CustomerSort = "name" | "joined";
export type SortDirection = "asc" | "desc";

/** One row of the customer listing (admin API contract). */
export interface CustomerRow {
  id: number;
  reference: string;
  name: string;
  email: string | null;
  phone: string;
  status: CustomerStatus;
  customer_type: CustomerType;
  email_verified: boolean;
  phone_verified: boolean;
  joined_at: string;
  last_activity_at: string | null;
  archived_at: string | null;
}

export interface CustomerMembership {
  organisation: string | null;
  role: string;
  status: string;
  joined_at: string | null;
}

export interface CustomerDetail extends CustomerRow {
  memberships: CustomerMembership[];
  updated_at: string;
}

export interface CustomerActivityEvent {
  source: "customer" | "admin";
  action: string;
  fields: string[];
  actor: string | null;
  description: string | null;
  occurred_at: string;
}

/** Permission-gated financial summary; never part of the profile payload. */
export interface CustomerFinancials {
  currency: string;
  payments_received: string;
  payments_count: number;
  last_payment_at: string | null;
  deposits_total: string;
  deposits_count: number;
  outstanding_invoices: string;
  outstanding_invoice_count: number;
  credit_balance: string;
  refunds_total: string;
  refunds_confirmed_count: number;
  refunds_pending_count: number;
  generated_at: string;
}

export interface CustomerListMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface CustomerCounts {
  total: number;
  active: number;
  inactive: number;
  archived: number;
}

export interface CustomerFilters {
  search: string;
  status: "" | CustomerStatus;
  type: "" | CustomerType;
  sort: CustomerSort;
  direction: SortDirection;
}

export interface CustomerFormValues {
  name: string;
  email: string;
  phone: string;
}
