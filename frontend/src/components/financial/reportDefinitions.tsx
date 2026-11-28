import type { ReactNode } from "react";
import type { FilterSpec } from "@/components/financial/ReportShell";
import { dateOnly, dateTime, money, titleCase } from "@/components/financial/format";

export type ReportDefinition = {
  key: string;
  title: string;
  description: string;
  permission: string;
  endpoint: string;
  exportEndpoint: string;
  dateBasis: string;
  filters: FilterSpec[];
  sortable: { key: string; label: string }[];
  columns: { key: string; label: string; right?: boolean; sortKey?: string; render: (row: Record<string, any>) => ReactNode }[];
  summary: (summary: Record<string, any> | undefined, currency: string) => { label: string; value: string; hint?: string }[];
  detail?: { label: string; permission: string; endpoint: (id: number) => string };
  emptyMessage: string;
};

const payerName = (row: Record<string, any>) => row.payer?.name ?? "—";
const payerMeta = (row: Record<string, any>) => (
  <small className="block text-[var(--color-text-secondary)]">{row.payer?.type ?? ""}{row.payer?.reference ? ` · ${row.payer.reference}` : ""}</small>
);
const muted = (value?: string | null) => <small className="block text-[var(--color-text-secondary)]">{value ?? "—"}</small>;

const STATUS_OPTIONS = [
  { value: "succeeded", label: "Succeeded" },
  { value: "pending", label: "Pending" },
  { value: "failed", label: "Failed" },
  { value: "cancelled", label: "Cancelled" },
];

const RECEIPTS: ReportDefinition = {
  key: "receipts",
  title: "Payment & Receipt Register",
  description: "Every payment transaction with its receipt, verification and remaining balance. Pending money is never treated as received.",
  permission: "financials.reports.receipts.view",
  endpoint: "/admin/reports/receipts",
  exportEndpoint: "/admin/reports/receipts/export.csv",
  dateBasis: "receipt date (received_at, falling back to created_at for unresolved payments)",
  filters: [
    { param: "status", label: "Payment status", options: STATUS_OPTIONS },
    { param: "method", label: "Method", options: [{ value: "cash", label: "Cash" }, { value: "gateway", label: "Online" }] },
    { param: "purpose", label: "Purpose", options: [{ value: "order", label: "Order" }, { value: "invoice", label: "Invoice" }] },
    { param: "payer_type", label: "Payer type", options: [{ value: "customer", label: "Customer" }, { value: "organisation", label: "Organisation" }] },
  ],
  sortable: [{ key: "received_at", label: "Received" }, { key: "amount", label: "Amount" }],
  columns: [
    { key: "reference", label: "Receipt", render: (row) => <>{row.transaction_reference}{muted(row.provider_reference)}</> },
    { key: "payer", label: "Payer", render: (row) => <>{payerName(row)}{payerMeta(row)}</> },
    { key: "purpose", label: "Purpose", render: (row) => <>{titleCase(row.payment_purpose)}{muted(row.order_number)}</> },
    { key: "method", label: "Method", render: (row) => <>{titleCase(row.payment_method)}{muted(row.provider ?? "manual")}</> },
    { key: "amount", label: "Amount", right: true, render: (row) => money(row.currency, row.amount) },
    { key: "status", label: "Status", render: (row) => <>{titleCase(row.status)}{muted(titleCase(row.verification_status))}</> },
    { key: "received", label: "Received", sortKey: "received_at", render: (row) => dateTime(row.received_at) },
    { key: "confirmed", label: "Confirmed by", render: (row) => row.confirmed_by ?? "—" },
  ],
  summary: (summary, currency) => [
    { label: "Successful receipts", value: money(currency, summary?.totals?.succeeded_amount), hint: "Succeeded transactions with a received date. Pending, failed and cancelled money is excluded." },
    { label: "Cash collected", value: money(currency, summary?.by_method?.cash?.amount), hint: "Succeeded cash receipts only." },
    { label: "Online collected", value: money(currency, summary?.by_method?.gateway?.amount), hint: "Succeeded provider receipts only." },
    { label: "Rows in register", value: String(summary?.totals?.receipts ?? "—"), hint: "Includes pending, failed and cancelled rows for reconciliation." },
  ],
  emptyMessage: "No payments match these filters.",
};

const ALLOCATIONS: ReportDefinition = {
  key: "allocations",
  title: "Payment Allocations",
  description: "How receipts were applied to invoices. Active and reversed allocations stay separate; a reversal never erases its history.",
  permission: "financials.reports.allocations.view",
  endpoint: "/admin/reports/allocations",
  exportEndpoint: "/admin/reports/allocations/export.csv",
  dateBasis: "allocation date (allocated_at)",
  filters: [
    { param: "status", label: "Allocation state", options: [{ value: "active", label: "Active" }, { value: "reversed", label: "Reversed" }] },
    { param: "basis", label: "Date basis", options: [{ value: "allocation", label: "Allocation date" }, { value: "receipt", label: "Receipt date" }] },
  ],
  sortable: [{ key: "allocated_at", label: "Allocated" }, { key: "amount", label: "Amount" }],
  columns: [
    { key: "allocation", label: "Allocation", render: (row) => <>{row.allocation_reference}{muted(row.receipt_reference)}</> },
    { key: "invoice", label: "Invoice", render: (row) => row.invoice_reference },
    { key: "payer", label: "Payer", render: (row) => <>{payerName(row)}{payerMeta(row)}</> },
    { key: "receipt_amount", label: "Receipt", right: true, render: (row) => money(row.currency, row.receipt_amount) },
    { key: "amount", label: "Allocated", right: true, sortKey: "amount", render: (row) => money(row.currency, row.allocation_amount) },
    { key: "remaining", label: "Receipt available", right: true, render: (row) => money(row.currency, row.receipt_available_amount) },
    { key: "date", label: "Allocated at", sortKey: "allocated_at", render: (row) => <>{dateTime(row.allocated_at)}{muted(row.actor ?? "actor unavailable")}</> },
    { key: "state", label: "State", render: (row) => row.reversed
      ? <span className="font-semibold text-[var(--color-danger)]">Reversed{muted(row.reversal?.reference)}</span>
      : <span className="font-semibold text-[var(--color-success)]">Active</span> },
  ],
  summary: (summary, currency) => [
    { label: "Active allocations", value: money(currency, summary?.active_amount), hint: "Allocations with no reversal." },
    { label: "Reversed allocations", value: money(currency, summary?.reversed_amount), hint: "Reported separately; never subtracted from the active column." },
    { label: "Active count", value: String(summary?.active_count ?? "—") },
    { label: "Reversed count", value: String(summary?.reversed_count ?? "—") },
  ],
  emptyMessage: "No allocations match these filters.",
};

const UNALLOCATED: ReportDefinition = {
  key: "unallocated",
  title: "Unallocated Receipts",
  description: "Successful invoice receipts with money still available for allocation. Order receipts, pending and failed money are excluded.",
  permission: "financials.reports.unallocated.view",
  endpoint: "/admin/reports/unallocated",
  exportEndpoint: "/admin/reports/unallocated/export.csv",
  dateBasis: "receipt date (received_at)",
  filters: [
    { param: "bucket", label: "Age", options: ["0-7", "8-30", "31-60", "61-90", "90+"].map((value) => ({ value, label: `${value} days` })) },
  ],
  sortable: [{ key: "received_at", label: "Received" }, { key: "amount", label: "Available" }],
  columns: [
    { key: "receipt", label: "Receipt", render: (row) => <>{row.transaction_reference}{muted(row.payment_method)}</> },
    { key: "payer", label: "Payer", render: (row) => <>{payerName(row)}{payerMeta(row)}</> },
    { key: "amount", label: "Original", right: true, render: (row) => money(row.currency, row.amount) },
    { key: "allocated", label: "Allocated", right: true, render: (row) => money(row.currency, row.allocated_amount) },
    { key: "designated", label: "Designated credit", right: true, render: (row) => money(row.currency, row.designated_amount) },
    { key: "available", label: "Available", right: true, sortKey: "amount", render: (row) => money(row.currency, row.available_amount) },
    { key: "received", label: "Received", sortKey: "received_at", render: (row) => dateTime(row.received_at) },
    { key: "age", label: "Age", render: (row) => <>{row.age_days ?? "—"} days{muted(row.age_bucket)}</> },
  ],
  summary: (summary, currency) => {
    const buckets = summary?.buckets ?? {};
    return [
      { label: "Available to allocate", value: money(currency, summary?.total_amount), hint: `As of ${summary?.as_of ?? "the period end"}. Refund reservations do not reduce this figure.` },
      { label: "Receipts holding funds", value: String(summary?.receipts ?? "—") },
      { label: "0–7 days", value: money(currency, buckets["0-7"]?.amount) },
      { label: "Over 90 days", value: money(currency, buckets["90+"]?.amount), hint: "Oldest unapplied money." },
    ];
  },
  emptyMessage: "Every invoice receipt in this period is fully applied.",
};

const RECEIVABLES: ReportDefinition = {
  key: "receivables",
  title: "Accounts Receivable",
  description: "Issued invoices with their active allocations, applied credit and outstanding balance. Invoice value is not cash revenue.",
  permission: "financials.reports.receivables.view",
  endpoint: "/admin/reports/receivables",
  exportEndpoint: "/admin/reports/receivables/export.csv",
  dateBasis: "invoice issue date; ageing as of the period end",
  filters: [
    { param: "payment_state", label: "Payment state", options: [{ value: "unpaid", label: "Unpaid" }, { value: "partially_paid", label: "Partially paid" }, { value: "fully_paid", label: "Fully paid" }] },
    { param: "overdue_only", label: "Overdue", options: [{ value: "1", label: "Overdue only" }] },
  ],
  sortable: [{ key: "issue_date", label: "Invoice date" }, { key: "due_date", label: "Due date" }, { key: "outstanding", label: "Outstanding" }],
  columns: [
    { key: "invoice", label: "Invoice", render: (row) => <>{row.invoice_reference}{muted(row.status)}</> },
    { key: "payer", label: "Payer", render: (row) => <>{payerName(row)}{payerMeta(row)}</> },
    { key: "dates", label: "Issued / due", sortKey: "due_date", render: (row) => <>{dateOnly(row.invoice_date)}{muted(`due ${dateOnly(row.due_date)}`)}</> },
    { key: "total", label: "Invoice total", right: true, render: (row) => money(row.currency, row.invoice_total) },
    { key: "allocated", label: "Allocated", right: true, render: (row) => money(row.currency, row.allocated_amount) },
    { key: "credit_note", label: "Credit note", right: true, render: (row) => money(row.currency, row.credit_note_applied_amount) },
    { key: "credit", label: "Credit applied", right: true, render: (row) => money(row.currency, row.credit_applied_amount) },
    { key: "outstanding", label: "Outstanding", right: true, sortKey: "outstanding", render: (row) => money(row.currency, row.outstanding_amount) },
    { key: "age", label: "Overdue", render: (row) => <>{titleCase(row.payment_state)}{muted(row.days_overdue > 0 ? `${row.days_overdue} days · ${row.ageing_bucket}` : "current")}</> },
  ],
  summary: (summary, currency) => {
    const buckets = summary?.buckets ?? {};
    return [
      { label: "Outstanding receivables", value: money(currency, summary?.outstanding_total), hint: `As of ${summary?.as_of ?? "the period end"}. Invoice total minus active allocations and applied credit.` },
      { label: "Open invoices", value: String(summary?.invoices ?? "—") },
      { label: "Current", value: money(currency, buckets.current?.outstanding) },
      { label: "Over 90 days", value: money(currency, buckets["90+"]?.outstanding) },
    ];
  },
  emptyMessage: "No invoices match these filters.",
};

const CREDIT_COLUMNS = [
  { key: "payer", label: "Payer", render: (row: Record<string, any>) => <>{payerName(row)}{payerMeta(row)}</> },
  { key: "opening", label: "Opening", right: true, render: (row: Record<string, any>) => money(row.currency, row.opening_balance) },
  { key: "credited", label: "Credited", right: true, render: (row: Record<string, any>) => money(row.currency, row.credited) },
  { key: "applied", label: "Applied", right: true, render: (row: Record<string, any>) => money(row.currency, row.applied) },
  { key: "reversed", label: "Reversed", right: true, render: (row: Record<string, any>) => money(row.currency, row.reversed) },
  { key: "closing", label: "Closing balance", right: true, render: (row: Record<string, any>) => money(row.currency, row.closing_balance) },
  { key: "activity", label: "Last activity", render: (row: Record<string, any>) => dateTime(row.last_activity_at) },
];

const CREDIT_SUMMARY = (summary: Record<string, any> | undefined, currency: string) => [
  { label: "Closing credit balance", value: money(currency, summary?.closing_balance), hint: "Derived from the append-only credit ledger. Credit is not cash received." },
  { label: "Credited in total", value: money(currency, summary?.credited) },
  { label: "Applied", value: money(currency, summary?.applied) },
  { label: "Reversed", value: money(currency, summary?.reversed) },
];

const CUSTOMER_CREDIT: ReportDefinition = {
  key: "customer-credit",
  title: "Customer Credit",
  description: "Derived customer credit balances with opening, movement and closing figures. The ledger is the only source of truth.",
  permission: "financials.reports.customer_credit.view",
  endpoint: "/admin/reports/customer-credit",
  exportEndpoint: "/admin/reports/customer-credit/export.csv",
  dateBasis: "credit ledger entry date (created_at); opening is everything before the period",
  filters: [{ param: "include_zero", label: "Balances", options: [{ value: "1", label: "Include zero balances" }] }],
  sortable: [],
  columns: CREDIT_COLUMNS,
  summary: CREDIT_SUMMARY,
  detail: { label: "Statement", permission: "financials.reports.customer_credit.view", endpoint: (id) => `/admin/reports/customer-credit/${id}` },
  emptyMessage: "No customer holds a credit balance for this period.",
};

const ORGANISATION_CREDIT: ReportDefinition = {
  ...CUSTOMER_CREDIT,
  key: "organisation-credit",
  title: "Organisation Credit",
  description: "Derived organisation credit balances. Only users with the organisation credit report permission can open it.",
  permission: "financials.reports.organisation_credit.view",
  endpoint: "/admin/reports/organisation-credit",
  exportEndpoint: "/admin/reports/organisation-credit/export.csv",
  detail: { label: "Statement", permission: "financials.reports.organisation_credit.view", endpoint: (id) => `/admin/reports/organisation-credit/${id}` },
  emptyMessage: "No organisation holds a credit balance for this period.",
};

const REFUNDS: ReportDefinition = {
  key: "refunds",
  title: "Refund Reports",
  description: "Refund lifecycle by stage. Only confirmed refunds count as money returned; submitted refunds are in flight.",
  permission: "financials.reports.refunds.view",
  endpoint: "/admin/reports/refunds",
  exportEndpoint: "/admin/reports/refunds/export.csv",
  dateBasis: "request date (requested_at), or confirmation date when selected",
  filters: [
    { param: "status", label: "Status", options: ["requested", "approved", "submitting", "submitted", "confirmed", "rejected", "failed"].map((value) => ({ value, label: titleCase(value) })) },
    { param: "basis", label: "Date basis", options: [{ value: "requested", label: "Requested" }, { value: "confirmed", label: "Confirmed" }] },
  ],
  sortable: [],
  columns: [
    { key: "refund", label: "Refund", render: (row) => <>{row.refund_reference}{muted(row.receipt_reference)}</> },
    { key: "payer", label: "Payer", render: (row) => <>{payerName(row)}{payerMeta(row)}</> },
    { key: "amount", label: "Amount", right: true, render: (row) => money(row.currency, row.amount) },
    { key: "status", label: "Status", render: (row) => <>{titleCase(row.status)}{muted(titleCase(row.stage))}</> },
    { key: "staff", label: "Requested / approved", render: (row) => <>{row.requesting_staff ?? "—"}{muted(row.approving_staff ?? "awaiting approval")}</> },
    { key: "requested", label: "Requested", render: (row) => dateTime(row.requested_at) },
    { key: "completed", label: "Completed", render: (row) => row.completed_at ? dateTime(row.completed_at) : "—" },
    { key: "provider", label: "Provider", render: (row) => <>{row.provider ?? "manual"}{muted(row.provider_confirmation_reference ?? row.provider_submission_status)}</> },
  ],
  summary: (summary, currency) => [
    { label: "Confirmed (returned)", value: money(currency, summary?.stages?.confirmed?.amount), hint: "Only confirmed refunds are money returned to the payer." },
    { label: "Pipeline", value: money(currency, summary?.stages?.pipeline?.amount), hint: "Requested and approved, not yet submitted." },
    { label: "In flight", value: money(currency, summary?.stages?.in_flight?.amount), hint: "Submitted to a provider or awaiting an outcome." },
    { label: "Rejected / failed", value: money(currency, summary?.stages?.rejected?.amount) },
  ],
  emptyMessage: "No refunds match these filters.",
};

const CREDIT_NOTES: ReportDefinition = {
  key: "credit-notes",
  title: "Credit Note Reports",
  description: "Issued credit notes with their originating invoice and application status. A credit note is not a cash refund.",
  permission: "financials.reports.credit_notes.view",
  endpoint: "/admin/reports/credit-notes",
  exportEndpoint: "/admin/reports/credit-notes/export.csv",
  dateBasis: "issue date (issued_at)",
  filters: [
    { param: "status", label: "Status", options: [{ value: "issued", label: "Issued" }, { value: "voided", label: "Voided" }, { value: "draft", label: "Draft" }] },
    { param: "basis", label: "Date basis", options: [{ value: "issued", label: "Issued" }, { value: "created", label: "Created" }] },
  ],
  sortable: [],
  columns: [
    { key: "note", label: "Credit note", render: (row) => <>{row.credit_note_number}{muted(row.original_invoice)}</> },
    { key: "payer", label: "Payer", render: (row) => <>{payerName(row)}{payerMeta(row)}</> },
    { key: "amount", label: "Amount", right: true, render: (row) => money(row.currency, row.amount) },
    { key: "applied", label: "Applied to invoice", right: true, render: (row) => money(row.currency, row.applied_to_origin_amount) },
    { key: "remaining", label: "Remaining (FIFO)", right: true, render: (row) => money(row.currency, row.remaining_amount) },
    { key: "issued", label: "Issued", render: (row) => <>{dateTime(row.issued_at)}{muted(row.issuing_staff)}</> },
    { key: "status", label: "Application", render: (row) => <>{titleCase(row.application_status)}{muted(row.status)}</> },
  ],
  summary: (summary, currency) => [
    { label: "Credit notes issued", value: money(currency, summary?.issued_amount), hint: "Issued credit notes in the period." },
    { label: "Issued count", value: String(summary?.issued_count ?? "—") },
    { label: "Voided", value: money(currency, summary?.voided_amount) },
    { label: "Voided count", value: String(summary?.voided_count ?? "—") },
  ],
  emptyMessage: "No credit notes match these filters.",
};

const ACTIVITY: ReportDefinition = {
  key: "activity",
  title: "Financial Activity & Audit",
  description: "Chronological financial events with actor, reference and reason. Read-only: corrections are audited financial operations, never edits here.",
  permission: "financials.reports.activity.view",
  endpoint: "/admin/reports/activity",
  exportEndpoint: "/admin/reports/activity/export.csv",
  dateBasis: "event time",
  filters: [
    {
      param: "event_type",
      label: "Event type",
      options: [
        "receipt", "allocation", "allocation_reversal", "credit_designation", "credit_application", "credit_reversal",
        "refund", "refund_confirmed", "credit_note_issued", "credit_note_voided",
      ].map((value) => ({ value, label: titleCase(value) })),
    },
  ],
  sortable: [],
  columns: [
    { key: "at", label: "When", render: (row) => dateTime(row.event_at) },
    { key: "type", label: "Event", render: (row) => titleCase(row.event_type) },
    { key: "reference", label: "Reference", render: (row) => <>{row.reference}{muted(row.subject)}</> },
    { key: "actor", label: "Actor", render: (row) => row.actor ?? "—" },
    { key: "amount", label: "Amount", right: true, render: (row) => (row.amount === null ? "—" : money(row.currency, row.amount)) },
    { key: "reason", label: "Reason", render: (row) => row.reason ?? "—" },
  ],
  summary: () => [],
  emptyMessage: "No financial events match these filters.",
};

export const REPORTS: Record<string, ReportDefinition> = {
  receipts: RECEIPTS,
  allocations: ALLOCATIONS,
  unallocated: UNALLOCATED,
  receivables: RECEIVABLES,
  "customer-credit": CUSTOMER_CREDIT,
  "organisation-credit": ORGANISATION_CREDIT,
  refunds: REFUNDS,
  "credit-notes": CREDIT_NOTES,
  activity: ACTIVITY,
};

export const REPORT_LINKS = [
  { key: "receipts", label: "Payment & Receipt Register", to: "/admin/financials/reports/receipts" },
  { key: "allocations", label: "Payment Allocations", to: "/admin/financials/reports/allocations" },
  { key: "unallocated", label: "Unallocated Receipts", to: "/admin/financials/reports/unallocated" },
  { key: "receivables", label: "Accounts Receivable", to: "/admin/financials/reports/receivables" },
  { key: "customer-credit", label: "Customer Credit", to: "/admin/financials/reports/customer-credit" },
  { key: "organisation-credit", label: "Organisation Credit", to: "/admin/financials/reports/organisation-credit" },
  { key: "refunds", label: "Refund Reports", to: "/admin/financials/reports/refunds" },
  { key: "credit-notes", label: "Credit Note Reports", to: "/admin/financials/reports/credit-notes" },
  { key: "activity", label: "Financial Activity", to: "/admin/financials/reports/activity" },
];
