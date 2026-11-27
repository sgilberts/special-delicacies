import { useCallback, useEffect, useRef, useState } from "react";
import {
  AlertCircle,
  Archive,
  Edit2,
  Eye,
  RefreshCw,
  RotateCcw,
  Search,
  UserPlus,
  Users,
} from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { useConfirm } from "@/components/ui/ConfirmDialog";
import CustomerDetailModal from "@/components/admin/customers/CustomerDetailModal";
import CustomerFormModal, { type EditableCustomer } from "@/components/admin/customers/CustomerFormModal";
import type {
  CustomerCounts,
  CustomerDetail,
  CustomerFilters,
  CustomerListMeta,
  CustomerRow,
  CustomerSort,
} from "@/types/customers";
import {
  CUSTOMER_PAGE_SIZE,
  DEFAULT_CUSTOMER_FILTERS,
  customerCapabilities,
  customerEmptyStateMessage,
  customerLifecycleAction,
  customerQuery,
  customerStatusLabel,
  customerStatusTone,
  customerTypeLabel,
  formatCustomerDate,
  pageWindow,
  totalCountLabel,
} from "@/lib/customers";

interface CustomerListResponse {
  data: CustomerRow[];
  meta: CustomerListMeta;
  counts: CustomerCounts;
}

const EMPTY_COUNTS: CustomerCounts = { total: 0, active: 0, inactive: 0, archived: 0 };

export default function AdminCustomersPage() {
  const { user } = useAuth();
  const capabilities = customerCapabilities(user?.permissions, user?.roles);
  const confirm = useConfirm();

  const [rows, setRows] = useState<CustomerRow[]>([]);
  const [meta, setMeta] = useState<CustomerListMeta>({ current_page: 1, last_page: 1, per_page: CUSTOMER_PAGE_SIZE, total: 0 });
  const [counts, setCounts] = useState<CustomerCounts>(EMPTY_COUNTS);
  const [filters, setFilters] = useState<CustomerFilters>(DEFAULT_CUSTOMER_FILTERS);
  const [searchInput, setSearchInput] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const [formState, setFormState] = useState<{ mode: "create" | "edit"; customer: EditableCustomer | null } | null>(null);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [detailKey, setDetailKey] = useState(0);
  const requestId = useRef(0);

  useEffect(() => {
    document.title = "Customers | Special Delicacies Admin";
  }, []);

  // Search is debounced so typing does not fire a request per keystroke; the
  // term is still applied by the server, never to the visible page.
  useEffect(() => {
    const timer = setTimeout(() => {
      setFilters((current) => (current.search === searchInput ? current : { ...current, search: searchInput }));
      setPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [searchInput]);

  const load = useCallback(async () => {
    const id = ++requestId.current;
    setLoading(true);
    setError("");
    try {
      const response = await api.get<CustomerListResponse>("/admin/customers", {
        params: customerQuery(filters, page),
      });
      if (id !== requestId.current) return;
      setRows(response.data.data);
      setMeta(response.data.meta);
      setCounts(response.data.counts);
    } catch (cause) {
      if (id !== requestId.current) return;
      const failure = cause as { response?: { status?: number; data?: { message?: string } } };
      setRows([]);
      setError(
        failure.response?.status === 403
          ? "You do not have permission to view customer records."
          : failure.response?.data?.message ?? "Customer records could not be loaded.",
      );
    } finally {
      if (id === requestId.current) setLoading(false);
    }
  }, [filters, page]);

  useEffect(() => {
    void load();
  }, [load]);

  const changeFilter = <K extends keyof CustomerFilters>(key: K, value: CustomerFilters[K]) => {
    setFilters((current) => ({ ...current, [key]: value }));
    setPage(1);
  };

  const refreshAfterMutation = async (text: string, keepDetailOpen?: number) => {
    setMessage(text);
    await load();
    if (keepDetailOpen) {
      setDetailId(keepDetailOpen);
      setDetailKey((current) => current + 1);
    }
  };

  const archiveCustomer = async (customer: { id: number; name: string }) => {
    const confirmed = await confirm({
      title: "Archive customer",
      message: `Archive ${customer.name}? Their orders, invoices, payments, deposits, refunds and credit history are all preserved. An archived customer can no longer sign in or start new transactions, and can be restored later.`,
      confirmLabel: "Archive customer",
      tone: "danger",
    });
    if (!confirmed) return;

    try {
      await api.post(`/admin/customers/${customer.id}/archive`);
      setDetailId(null);
      await refreshAfterMutation(`${customer.name} was archived. Financial history was preserved.`);
    } catch (cause) {
      const failure = cause as { response?: { status?: number; data?: { message?: string } } };
      setError(
        failure.response?.status === 403
          ? "You do not have permission to archive customers."
          : failure.response?.data?.message ?? "The customer could not be archived.",
      );
    }
  };

  const restoreCustomer = async (customer: { id: number; name: string }) => {
    const confirmed = await confirm({
      title: "Restore customer",
      message: `Restore ${customer.name} to active status? They will be able to sign in and start new transactions again.`,
      confirmLabel: "Restore customer",
    });
    if (!confirmed) return;

    try {
      await api.post(`/admin/customers/${customer.id}/restore`);
      setDetailId(null);
      await refreshAfterMutation(`${customer.name} was restored to active status.`);
    } catch (cause) {
      const failure = cause as { response?: { status?: number; data?: { message?: string } } };
      setError(
        failure.response?.status === 403
          ? "You do not have permission to restore customers."
          : failure.response?.data?.message ?? "The customer could not be restored.",
      );
    }
  };

  const handleSaved = async (customer: CustomerDetail, mode: "create" | "edit") => {
    setFormState(null);
    await refreshAfterMutation(
      mode === "create" ? `${customer.name} was added.` : `${customer.name} was updated.`,
      mode === "edit" ? customer.id : undefined,
    );
  };

  const hasFilters = filters.search.trim() !== "" || filters.status !== "" || filters.type !== "";
  const pages = pageWindow(meta.current_page, meta.last_page);

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[var(--color-text)] flex items-center gap-2">
            <Users size={22} className="text-[var(--brand-copper)]" />
            Customers
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1 max-w-2xl">
            Manage customer records and view customer information. Archived customers keep their full financial and audit
            history.
          </p>
          <p className="text-xs font-semibold text-[var(--color-text-secondary)] mt-2" data-testid="customer-total">
            {totalCountLabel(counts.total)}
            <span className="font-normal"> · {counts.active} active · {counts.inactive} inactive · {counts.archived} archived</span>
          </p>
        </div>

        {capabilities.canCreate && (
          <button
            type="button"
            onClick={() => setFormState({ mode: "create", customer: null })}
            className="btn-accent px-4 py-2 text-sm font-semibold inline-flex items-center gap-2 self-start"
          >
            <UserPlus size={16} /> Add Customer
          </button>
        )}
      </div>

      {message && (
        <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
          {message}
        </div>
      )}

      {error && (
        <div role="alert" className="flex items-start justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
          <span className="flex items-start gap-2">
            <AlertCircle size={16} className="mt-0.5 flex-shrink-0" />
            {error}
          </span>
          <button type="button" onClick={() => void load()} className="underline font-semibold whitespace-nowrap">
            Retry
          </button>
        </div>
      )}

      {/* Search and filters — every control is applied by the API */}
      <div className="p-4 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs flex flex-col lg:flex-row gap-3 lg:items-center">
        <div className="relative flex-1 min-w-0">
          <Search size={18} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]" />
          <input
            type="search"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Search by name, email, or phone number…"
            aria-label="Search customers"
            className="w-full pl-10 pr-4 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-sm text-[var(--color-text)] placeholder-[var(--color-text-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <select
            value={filters.status}
            onChange={(event) => changeFilter("status", event.target.value as CustomerFilters["status"])}
            aria-label="Filter by status"
            className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="archived">Archived</option>
          </select>

          <select
            value={filters.type}
            onChange={(event) => changeFilter("type", event.target.value as CustomerFilters["type"])}
            aria-label="Filter by customer type"
            className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          >
            <option value="">All types</option>
            <option value="individual">Individual</option>
            <option value="organisation">Organisation</option>
          </select>

          <select
            value={`${filters.sort}:${filters.direction}`}
            onChange={(event) => {
              const [sort, direction] = event.target.value.split(":") as [CustomerSort, "asc" | "desc"];
              setFilters((current) => ({ ...current, sort, direction }));
              setPage(1);
            }}
            aria-label="Sort customers"
            className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          >
            <option value="joined:desc">Newest first</option>
            <option value="joined:asc">Oldest first</option>
            <option value="name:asc">Name A–Z</option>
            <option value="name:desc">Name Z–A</option>
          </select>

          <button
            type="button"
            onClick={() => void load()}
            className="p-2 border border-[var(--color-border)] bg-[var(--color-background)] hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] rounded-xl transition-colors"
            title="Refresh list"
            aria-label="Refresh customer list"
          >
            <RefreshCw size={16} />
          </button>
        </div>
      </div>

      {/* Listing */}
      <div className="rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3" role="status">
            <RefreshCw size={24} className="animate-spin mx-auto text-[var(--brand-copper)]" />
            <p className="text-sm font-medium">Loading customers…</p>
          </div>
        ) : rows.length === 0 ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-2">
            <Users size={32} className="mx-auto opacity-50" />
            <p className="text-base font-semibold text-[var(--color-text)]">No customers to show</p>
            <p className="text-xs">{customerEmptyStateMessage(hasFilters)}</p>
          </div>
        ) : (
          <>
            {/* Desktop table */}
            <div className="hidden md:block overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-[var(--color-border)] text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)] bg-[var(--color-background)]/50">
                    <th scope="col" className="py-3 px-5">Customer</th>
                    <th scope="col" className="py-3 px-5">Contact</th>
                    <th scope="col" className="py-3 px-5">Type</th>
                    <th scope="col" className="py-3 px-5">Status</th>
                    <th scope="col" className="py-3 px-5">Joined</th>
                    <th scope="col" className="py-3 px-5">Last activity</th>
                    <th scope="col" className="py-3 px-5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[var(--color-border)]">
                  {rows.map((row) => {
                    const lifecycle = customerLifecycleAction(row.status, capabilities);
                    return (
                      <tr key={row.id} className="hover:bg-[var(--color-surface-secondary)]/40 transition-colors">
                        <td className="py-3.5 px-5">
                          <button
                            type="button"
                            onClick={() => setDetailId(row.id)}
                            className="text-left font-semibold text-[var(--color-text)] hover:text-[var(--brand-copper)]"
                          >
                            {row.name}
                          </button>
                          <p className="text-xs text-[var(--color-text-secondary)] font-mono">{row.reference}</p>
                        </td>
                        <td className="py-3.5 px-5">
                          <p className="text-xs text-[var(--color-text)] break-all">{row.email ?? "No email recorded"}</p>
                          <p className="text-xs text-[var(--color-text-secondary)]">{row.phone}</p>
                        </td>
                        <td className="py-3.5 px-5 text-xs text-[var(--color-text)]">{customerTypeLabel(row.customer_type)}</td>
                        <td className="py-3.5 px-5">
                          <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border ${customerStatusTone(row.status)}`}>
                            {customerStatusLabel(row.status)}
                          </span>
                        </td>
                        <td className="py-3.5 px-5 text-xs text-[var(--color-text)]">{formatCustomerDate(row.joined_at)}</td>
                        <td className="py-3.5 px-5 text-xs text-[var(--color-text-secondary)]">{formatCustomerDate(row.last_activity_at)}</td>
                        <td className="py-3.5 px-5">
                          <div className="flex items-center justify-end gap-1">
                            <button
                              type="button"
                              onClick={() => setDetailId(row.id)}
                              className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]"
                              title="View customer"
                              aria-label={`View ${row.name}`}
                            >
                              <Eye size={16} />
                            </button>
                            {capabilities.canUpdate && (
                              <button
                                type="button"
                                onClick={() => setFormState({ mode: "edit", customer: row })}
                                className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]"
                                title="Edit customer"
                                aria-label={`Edit ${row.name}`}
                              >
                                <Edit2 size={16} />
                              </button>
                            )}
                            {lifecycle === "archive" && (
                              <button
                                type="button"
                                onClick={() => void archiveCustomer(row)}
                                className="p-2 rounded-lg text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10"
                                title="Archive customer"
                                aria-label={`Archive ${row.name}`}
                              >
                                <Archive size={16} />
                              </button>
                            )}
                            {lifecycle === "restore" && (
                              <button
                                type="button"
                                onClick={() => void restoreCustomer(row)}
                                className="p-2 rounded-lg text-[var(--color-accent)] hover:bg-[var(--color-accent)]/10"
                                title="Restore customer"
                                aria-label={`Restore ${row.name}`}
                              >
                                <RotateCcw size={16} />
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            {/* Mobile cards */}
            <ul className="md:hidden divide-y divide-[var(--color-border)]">
              {rows.map((row) => {
                const lifecycle = customerLifecycleAction(row.status, capabilities);
                return (
                  <li key={row.id} className="p-4 space-y-2">
                    <div className="flex items-start justify-between gap-2">
                      <button
                        type="button"
                        onClick={() => setDetailId(row.id)}
                        className="text-left font-semibold text-[var(--color-text)]"
                      >
                        {row.name}
                        <span className="block text-xs font-mono text-[var(--color-text-secondary)]">{row.reference}</span>
                      </button>
                      <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border ${customerStatusTone(row.status)}`}>
                        {customerStatusLabel(row.status)}
                      </span>
                    </div>
                    <p className="text-xs text-[var(--color-text)] break-all">{row.email ?? "No email recorded"}</p>
                    <p className="text-xs text-[var(--color-text-secondary)]">{row.phone}</p>
                    <p className="text-xs text-[var(--color-text-secondary)]">
                      {customerTypeLabel(row.customer_type)} · Joined {formatCustomerDate(row.joined_at)}
                    </p>
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                      <button
                        type="button"
                        onClick={() => setDetailId(row.id)}
                        className="btn-ghost px-3 py-1.5 text-xs font-semibold inline-flex items-center gap-1"
                      >
                        <Eye size={14} /> View
                      </button>
                      {capabilities.canUpdate && (
                        <button
                          type="button"
                          onClick={() => setFormState({ mode: "edit", customer: row })}
                          className="btn-ghost px-3 py-1.5 text-xs font-semibold inline-flex items-center gap-1"
                        >
                          <Edit2 size={14} /> Edit
                        </button>
                      )}
                      {lifecycle === "archive" && (
                        <button
                          type="button"
                          onClick={() => void archiveCustomer(row)}
                          className="px-3 py-1.5 text-xs font-semibold rounded-lg border border-[var(--color-danger)] text-[var(--color-danger)] inline-flex items-center gap-1"
                        >
                          <Archive size={14} /> Archive
                        </button>
                      )}
                      {lifecycle === "restore" && (
                        <button
                          type="button"
                          onClick={() => void restoreCustomer(row)}
                          className="btn-accent px-3 py-1.5 text-xs font-semibold inline-flex items-center gap-1"
                        >
                          <RotateCcw size={14} /> Restore
                        </button>
                      )}
                    </div>
                  </li>
                );
              })}
            </ul>

            {/* Pagination */}
            <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-5 py-3 border-t border-[var(--color-border)] text-xs text-[var(--color-text-secondary)]">
              <p>
                Page {meta.current_page} of {meta.last_page} · {totalCountLabel(meta.total)}
              </p>
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  disabled={meta.current_page <= 1}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                  className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] disabled:opacity-40 hover:bg-[var(--color-surface-secondary)] text-[var(--color-text)] font-semibold"
                >
                  Previous
                </button>
                {pages.map((value) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setPage(value)}
                    aria-current={value === meta.current_page ? "page" : undefined}
                    className={`px-3 py-1.5 rounded-lg border font-semibold ${
                      value === meta.current_page
                        ? "border-[var(--brand-copper)] text-[var(--brand-copper)]"
                        : "border-[var(--color-border)] text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]"
                    }`}
                  >
                    {value}
                  </button>
                ))}
                <button
                  type="button"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setPage((current) => Math.min(meta.last_page, current + 1))}
                  className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] disabled:opacity-40 hover:bg-[var(--color-surface-secondary)] text-[var(--color-text)] font-semibold"
                >
                  Next
                </button>
              </div>
            </div>
          </>
        )}
      </div>

      {formState && (
        <CustomerFormModal
          mode={formState.mode}
          customer={formState.customer}
          onClose={() => setFormState(null)}
          onSaved={(customer, mode) => void handleSaved(customer, mode)}
        />
      )}

      {detailId !== null && (
        <CustomerDetailModal
          key={`${detailId}:${detailKey}`}
          customerId={detailId}
          capabilities={capabilities}
          onClose={() => setDetailId(null)}
          onEdit={(customer) => {
            setDetailId(null);
            setFormState({ mode: "edit", customer });
          }}
          onArchive={(customer) => void archiveCustomer(customer)}
          onRestore={(customer) => void restoreCustomer(customer)}
        />
      )}
    </div>
  );
}
