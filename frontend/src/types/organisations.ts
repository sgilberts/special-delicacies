export type OrganisationStatus = "active" | "inactive" | "archived";
export type OrganisationSort = "name" | "created" | "members";
export type SortDirection = "asc" | "desc";
export type MembershipStatus = "active" | "pending" | "suspended" | "removed";
export type MembershipRole = "owner" | "administrator" | "manager" | "member";

/** One row of the organisation listing (admin API contract). */
export interface OrganisationRow {
  id: number;
  reference: string;
  name: string;
  contact_person: string | null;
  email: string | null;
  phone: string | null;
  members_count: number;
  active_members_count: number;
  pending_members_count: number;
  status: OrganisationStatus;
  created_at: string;
  updated_at: string;
  archived_at: string | null;
}

export interface OrganisationMembershipSummary {
  total: number;
  active: number;
  pending: number;
  suspended: number;
  removed: number;
  roles: { role: MembershipRole | string; count: number }[];
}

export interface OrganisationContact {
  id: number;
  name: string;
  email: string | null;
  phone: string;
}

export interface OrganisationDetail {
  id: number;
  reference: string;
  name: string;
  description: string | null;
  business_email: string | null;
  business_phone: string | null;
  address: string | null;
  status: OrganisationStatus;
  raw_status: string;
  owner: OrganisationContact | null;
  membership: OrganisationMembershipSummary;
  created_at: string;
  updated_at: string;
  archived_at: string | null;
}

export interface OrganisationActivityEvent {
  source: "admin" | "organisation";
  action: string;
  fields: string[];
  actor: string | null;
  description: string | null;
  occurred_at: string;
}

/** Permission-gated financial summary; never part of the profile payload. */
export interface OrganisationFinancials {
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

export interface OrganisationMember {
  id: number;
  customer: { id: number; name: string; email: string | null; phone: string; is_active: boolean } | null;
  role: MembershipRole | string;
  status: MembershipStatus | string;
  financial_access: boolean;
  invited_by: string | null;
  joined_at: string | null;
  approved_at: string | null;
  created_at: string;
}

export interface OrganisationListMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface OrganisationCounts {
  total: number;
  active: number;
  inactive: number;
  archived: number;
}

export interface OrganisationFilters {
  search: string;
  status: "" | OrganisationStatus;
  membershipStatus: "" | MembershipStatus;
  sort: OrganisationSort;
  direction: SortDirection;
}

export interface OrganisationFormValues {
  name: string;
  owner_customer_id: string;
  description: string;
  business_email: string;
  business_phone: string;
  address: string;
}

/** Payload of the private real-time channels (identity only, never financials). */
export interface CustomerChangedPayload {
  event_id: string;
  action: "created" | "updated" | "archived" | "restored" | string;
  customer: { id: number; status: string | null };
  occurred_at: string;
}

export interface OrganisationChangedPayload {
  event_id: string;
  action: "created" | "updated" | "archived" | "restored" | string;
  organisation: { id: number; status: string | null; members_count: number | null; active_members_count: number | null };
  occurred_at: string;
}

export interface OrganisationMembershipChangedPayload {
  event_id: string;
  action: string;
  organisation_id: number;
  membership: { id: number; status: string | null; role: string | null } | null;
  members_count: number | null;
  active_members_count: number | null;
  occurred_at: string;
}
