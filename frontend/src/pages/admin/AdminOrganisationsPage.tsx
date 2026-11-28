import { useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, Archive, Building2, Edit2, Eye, Plus, RefreshCw, RotateCcw, Search, Users } from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { useConfirm } from "@/components/ui/ConfirmDialog";
import OrganisationDetailModal from "@/components/admin/organisations/OrganisationDetailModal";
import OrganisationFormModal from "@/components/admin/organisations/OrganisationFormModal";
import { usePrivateChannel } from "@/hooks/usePrivateChannel";
import type {
  OrganisationChangedPayload,
  OrganisationCounts,
  OrganisationFilters,
  OrganisationListMeta,
  OrganisationMembershipChangedPayload,
  OrganisationRow,
  OrganisationSort,
} from "@/types/organisations";
import {
  DEFAULT_ORGANISATION_FILTERS,
  ORGANISATION_PAGE_SIZE,
  formatOrganisationDate,
  organisationCapabilities,
  organisationCountLabel,
  organisationEmptyStateMessage,
  organisationLifecycleAction,
  organisationQuery,
  organisationStatusLabel,
  organisationStatusTone,
} from "@/lib/organisations";
import { pageWindow } from "@/lib/customers";

interface OrganisationListResponse {
  data: OrganisationRow[];
  meta: OrganisationListMeta;
  counts: OrganisationCounts;
}

const EMPTY_COUNTS: OrganisationCounts = { total: 0, active: 0, inactive: 0, archived: 0 };

export default function AdminOrganisationsPage() {
  const { user } = useAuth();
  const capabilities = organisationCapabilities(user?.permissions, user?.roles);
  const confirm = useConfirm();

  const [rows, setRows] = useState<OrganisationRow[]>([]);
  const [meta, setMeta] = useState<OrganisationListMeta>({ current_page: 1, last_page: 1, per_page: ORGANISATION_PAGE_SIZE, total: 0 });
  const [counts, setCounts] = useState<OrganisationCounts>(EMPTY_COUNTS);
  const [filters, setFilters] = useState<OrganisationFilters>(DEFAULT_ORGANISATION_FILTERS);
  const [searchInput, setSearchInput] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const [formState, setFormState] = useState<{ mode: "create" | "edit"; organisationId: number | null } | null>(null);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [detailToken, setDetailToken] = useState(0);
  const requestId = useRef(0);
  const refreshTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const detailIdRef = useRef<number | null>(null);
  detailIdRef.current = detailId;

  useEffect(() => {
    document.title = "Organisations | Special Delicacies Admin";
  }, []);

  // Search is debounced; the term is applied by the server, never to the
  // visible page, so it covers the complete dataset.
  useEffect(() => {
    const timer = setTimeout(() => {
      setFilters((current) => (current.search === searchInput ? current : { ...current, search: searchInput }));
      setPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [searchInput]);

  const load = useCallback(async (options: { silent?: boolean } = {}) => {
    const id = ++requestId.current;
    if (!options.silent) setLoading(true);
    setError("");
    try {
      const response = await api.get<OrganisationListResponse>("/admin/organisations", {
        params: organisationQuery(filters, page),
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
          ? "You do not have permission to view organisation records."
          : failure.response?.data?.message ?? "Organisation records could not be loaded.",
      );
    } finally {
      if (id === requestId.current) setLoading(false);
    }
  }, [filters, page]);

  useEffect(() => {
    void load();
  }, [load]);

  /**
   * A real-time event refreshes the authoritative listing (never patched by
   * hand, so filters, counts and pagination can never drift) and bumps the open
   * detail modal so it refetches. Bursts are coalesced into one request.
   */
  const scheduleRefresh = useCallback((organisationId?: number) => {
    if (organisationId !== undefined && detailIdRef.current === organisationId) {
      setDetailToken((token) => token + 1);
    }

    if (refreshTimer.current !== null) return;
    refreshTimer.current = setTimeout(() => {
      refreshTimer.current = null;
      void load({ silent: true });
    }, 300);
  }, [load]);

  useEffect(() => () => {
    if (refreshTimer.current !== null) clearTimeout(refreshTimer.current);
  }, []);

  const { state: connectionState } = usePrivateChannel({
    channel: "organisations",
    enabled: capabilities.canView,
    events: {
      ".organisation.changed": (payload) => {
        const event = payload as unknown as OrganisationChangedPayload;
        scheduleRefresh(event.organisation?.id);
      },
      ".organisation.membership.changed": (payload) => {
        const event = payload as unknown as OrganisationMembershipChangedPayload;
        scheduleRefresh(event.organisation_id);
      },
    },
    onReconnect: () => scheduleRefresh(),
  });

  // Slow fallback refresh while the socket is unavailable. Never more than one
  // request every 30 seconds and only while the page is visible.
  useEffect(() => {
    if (connectionState === "connected") return;
    const timer = setInterval(() => {
      if (document.visibilityState === "visible") void load({ silent: true });
    }, 30_000);
    return () => clearInterval(timer);
  }, [connectionState, load]);

  const changeFilter = <K extends keyof OrganisationFilters>(key: K, value: OrganisationFilters[K]) => {
    setFilters((current) => ({ ...current, [key]: value }));
    setPage(1);
  };

  const refreshAfterMutation = async (text: string) => {
    setMessage(text);
    await load({ silent: true });
  };

  const archiveOrganisation = async (row: Pick<OrganisationRow, "id" | "name">) => {
    const confirmed = await confirm({
      title: "Archive organisation",
      message: `Archive ${row.name}? Members, quotations, invoices and financial history are preserved. The organisation can be restored later.`,
      confirmLabel: "Archive",
      tone: "danger",
    });
    if (!confirmed) return;

    try {
      await api.post(`/admin/organisations/${row.id}/archive`);
      await refreshAfterMutation("Organisation archived. Its members and history are preserved.");
    } catch (cause) {
      const failure = cause as { response?: { data?: { message?: string } } };
      setError(failure.response?.data?.message ?? "The organisation could not be archived.");
    }
  };

  const restoreOrganisation = async (row: Pick<OrganisationRow, "id" | "name">) => {
    const confirmed = await confirm({
      title: "Restore organisation",
      message: `Restore ${row.name} to active status?`,
      confirmLabel: "Restore",
    });
    if (!confirmed) return;

    try {
      await api.post(`/admin/organisations/${row.id}/restore`);
      await refreshAfterMutation("Organisation restored to active status.");
    } catch (cause) {
      const failure = cause as { response?: { data?: { message?: string } } };
      setError(failure.response?.data?.message ?? "The organisation could not be restored.");
    }
  };

  const hasFilters = filters.search.trim() !== "" || filters.status !== "" || filters.membershipStatus !== "";
  const pages = pageWindow(meta.current_page, meta.last_page);

  return (
    <div className="space-y-5">
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[var(--color-text)] flex items-center gap-2">
            <Building2 size={22} className="text-[var(--brand-copper)]" />
            Organisations
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1 max-w-2xl">
            Manage organisation profiles, memberships and lifecycle. Archiving preserves members, quotations, invoices and
            all financial history.
          </p>
          <p className="text-xs font-semibold text-[var(--color-text-secondary)] mt-2" data-testid="organisation-total">
            {organisationCountLabel(counts.total)}
            <span className="font-normal"> · {counts.active} active · {counts.inactive} suspended · {counts.archived} archived</span>
          </p>
        </div>

        <div className="flex items-center gap-2 self-start">
          <span
            role="status"
            title={connectionState === "connected" ? "Live updates are connected" : "Live updates are reconnecting; the list refreshes every 30 seconds"}
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold ${
              connectionState === "connected"
                ? "border-emerald-300 text-emerald-800 bg-emerald-50 dark:border-emerald-800 dark:text-emerald-200 dark:bg-emerald-950/40"
                : "border-[var(--color-border)] text-[var(--color-text-secondary)]"
            }`}
          >
            <span className={`h-1.5 w-1.5 rounded-full ${connectionState === "connected" ? "bg-emerald-500" : "bg-amber-500"}`} />
            {connectionState === "connected" ? "Live" : connectionState === "connecting" ? "Connecting" : "Offline"}
          </span>

          {capabilities.canCreate && (
            <button
              type="button"
              onClick={() => setFormState({ mode: "create", organisationId: null })}
              className="btn-accent px-4 py-2 text-sm font-semibold inline-flex items-center gap-2"
            >
              <Plus size={16} /> Add Organisation
            </button>
          )}
        </div>
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

      <div className="p-4 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs flex flex-col lg:flex-row gap-3 lg:items-center">
        <div className="relative flex-1 min-w-0">
          <Search size={18} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]" />
          <input
            type="search"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Search by name, reference, email, or phone number…"
            aria-label="Search organisations"
            className="w-full pl-10 pr-4 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-sm text-[var(--color-text)] placeholder-[var(--color-text-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <select
            value={filters.status}
            onChange={(event) => changeFilter("status", event.target.value as OrganisationFilters["status"])}
            aria-label="Filter by status"
            className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Suspended</option>
            <option value="archived">Archived</option>
          </select>

          <select
            value={filters.membershipStatus}
            onChange={(event) => changeFilter("membershipStatus", event.target.value as OrganisationFilters["membershipStatus"])}
            aria-label="Filter by membership status"
            className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          >
            <option value="">Any membership</option>
            <option value="active">Has active members</option>
            <option value="pending">Has pending members</option>
            <option value="suspended">Has suspended members</option>
            <option value="removed">Has removed members</option>
          </select>

          <select
            value={`${filters.sort}:${filters.direction}`}
            onChange={(event) => {
              const [sort, direction] = event.target.value.split(":") as [OrganisationSort, "asc" | "desc"];
              setFilters((current) => ({ ...current, sort, direction }));
              setPage(1);
            }}
            aria-label="Sort organisations"
            className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          >
            <option value="created:desc">Newest first</option>
            <option value="created:asc">Oldest first</option>
            <option value="name:asc">Name A–Z</option>
            <option value="name:desc">Name Z–A</option>
            <option value="members:desc">Most members</option>
            <option value="members:asc">Fewest members</option>
          </select>

          <button
            type="button"
            onClick={() => void load()}
            className="p-2 border border-[var(--color-border)] bg-[var(--color-background)] hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] rounded-xl transition-colors"
            title="Refresh list"
            aria-label="Refresh organisation list"
          >
            <RefreshCw size={16} />
          </button>
        </div>
      </div>

      <div className="rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3" role="status">
            <RefreshCw size={24} className="animate-spin mx-auto text-[var(--brand-copper)]" />
            <p className="text-sm font-medium">Loading organisations…</p>
          </div>
        ) : rows.length === 0 ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-2">
            <Building2 size={32} className="mx-auto opacity-50" />
            <p className="text-base font-semibold text-[var(--color-text)]">No organisations to show</p>
            <p className="text-xs">{organisationEmptyStateMessage(hasFilters)}</p>
          </div>
        ) : (
          <>
            <div className="hidden md:block overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-[var(--color-border)] text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)] bg-[var(--color-background)]/50">
                    <th scope="col" className="py-3 px-5">Organisation</th>
                    <th scope="col" className="py-3 px-5">Reference</th>
                    <th scope="col" className="py-3 px-5">Contact person</th>
                    <th scope="col" className="py-3 px-5">Email</th>
                    <th scope="col" className="py-3 px-5">Phone</th>
                    <th scope="col" className="py-3 px-5">Membership</th>
                    <th scope="col" className="py-3 px-5">Status</th>
                    <th scope="col" className="py-3 px-5">Created</th>
                    <th scope="col" className="py-3 px-5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[var(--color-border)]">
                  {rows.map((row) => {
                    const lifecycle = organisationLifecycleAction(row.status, capabilities);
                    return (
                      <tr key={row.id} className="hover:bg-[var(--color-surface-secondary)]/40 transition-colors">
                        <td className="py-3 px-5">
                          <button type="button" onClick={() => setDetailId(row.id)} className="text-left font-semibold text-[var(--color-text)] hover:underline">
                            {row.name}
                          </button>
                        </td>
                        <td className="py-3 px-5 font-mono text-xs text-[var(--color-text-secondary)]">{row.reference}</td>
                        <td className="py-3 px-5 text-[var(--color-text)]">{row.contact_person ?? "—"}</td>
                        <td className="py-3 px-5 text-[var(--color-text-secondary)] break-all">{row.email ?? "—"}</td>
                        <td className="py-3 px-5 text-[var(--color-text-secondary)]">{row.phone ?? "—"}</td>
                        <td className="py-3 px-5 text-[var(--color-text)]">
                          <span className="inline-flex items-center gap-1 text-xs font-semibold">
                            <Users size={14} className="text-[var(--color-text-secondary)]" />
                            {row.members_count}
                            <span className="font-normal text-[var(--color-text-secondary)]">({row.active_members_count} active)</span>
                          </span>
                        </td>
                        <td className="py-3 px-5">
                          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border ${organisationStatusTone(row.status)}`}>
                            {organisationStatusLabel(row.status)}
                          </span>
                        </td>
                        <td className="py-3 px-5 text-xs text-[var(--color-text-secondary)]">{formatOrganisationDate(row.created_at)}</td>
                        <td className="py-3 px-5">
                          <div className="flex items-center justify-end gap-1">
                            <button
                              type="button"
                              onClick={() => setDetailId(row.id)}
                              className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]"
                              title="View organisation"
                              aria-label={`View ${row.name}`}
                            >
                              <Eye size={16} />
                            </button>
                            {capabilities.canUpdate && (
                              <button
                                type="button"
                                onClick={() => setFormState({ mode: "edit", organisationId: row.id })}
                                className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]"
                                title="Edit organisation"
                                aria-label={`Edit ${row.name}`}
                              >
                                <Edit2 size={16} />
                              </button>
                            )}
                            {lifecycle === "archive" && (
                              <button
                                type="button"
                                onClick={() => void archiveOrganisation(row)}
                                className="p-2 rounded-lg text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10"
                                title="Archive organisation"
                                aria-label={`Archive ${row.name}`}
                              >
                                <Archive size={16} />
                              </button>
                            )}
                            {lifecycle === "restore" && (
                              <button
                                type="button"
                                onClick={() => void restoreOrganisation(row)}
                                className="p-2 rounded-lg text-[var(--color-accent)] hover:bg-[var(--color-accent)]/10"
                                title="Restore organisation"
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

            <ul className="md:hidden divide-y divide-[var(--color-border)]">
              {rows.map((row) => {
                const lifecycle = organisationLifecycleAction(row.status, capabilities);
                return (
                  <li key={row.id} className="p-4 space-y-2">
                    <div className="flex items-start justify-between gap-2">
                      <button type="button" onClick={() => setDetailId(row.id)} className="text-left font-semibold text-[var(--color-text)]">
                        {row.name}
                        <span className="block text-xs font-mono text-[var(--color-text-secondary)]">{row.reference}</span>
                      </button>
                      <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border ${organisationStatusTone(row.status)}`}>
                        {organisationStatusLabel(row.status)}
                      </span>
                    </div>
                    <p className="text-xs text-[var(--color-text)] break-all">{row.email ?? "No email recorded"}</p>
                    <p className="text-xs text-[var(--color-text-secondary)]">{row.phone ?? "No phone recorded"}</p>
                    <p className="text-xs text-[var(--color-text-secondary)]">
                      {row.contact_person ?? "No contact person"} · {row.members_count} {row.members_count === 1 ? "member" : "members"} · Created {formatOrganisationDate(row.created_at)}
                    </p>
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                      <button type="button" onClick={() => setDetailId(row.id)} className="btn-ghost px-3 py-1.5 text-xs font-semibold inline-flex items-center gap-1">
                        <Eye size={14} /> View
                      </button>
                      {capabilities.canUpdate && (
                        <button
                          type="button"
                          onClick={() => setFormState({ mode: "edit", organisationId: row.id })}
                          className="btn-ghost px-3 py-1.5 text-xs font-semibold inline-flex items-center gap-1"
                        >
                          <Edit2 size={14} /> Edit
                        </button>
                      )}
                      {lifecycle === "archive" && (
                        <button
                          type="button"
                          onClick={() => void archiveOrganisation(row)}
                          className="px-3 py-1.5 text-xs font-semibold rounded-lg border border-[var(--color-danger)] text-[var(--color-danger)] inline-flex items-center gap-1"
                        >
                          <Archive size={14} /> Archive
                        </button>
                      )}
                      {lifecycle === "restore" && (
                        <button type="button" onClick={() => void restoreOrganisation(row)} className="btn-accent px-3 py-1.5 text-xs font-semibold inline-flex items-center gap-1">
                          <RotateCcw size={14} /> Restore
                        </button>
                      )}
                    </div>
                  </li>
                );
              })}
            </ul>

            <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-5 py-3 border-t border-[var(--color-border)] text-xs text-[var(--color-text-secondary)]">
              <p>
                Page {meta.current_page} of {meta.last_page} · {organisationCountLabel(meta.total)}
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
        <OrganisationFormModal
          mode={formState.mode}
          organisationId={formState.organisationId}
          onClose={() => setFormState(null)}
          onSaved={(text) => {
            setFormState(null);
            void refreshAfterMutation(text);
          }}
        />
      )}

      {detailId !== null && (
        <OrganisationDetailModal
          organisationId={detailId}
          refreshToken={detailToken}
          capabilities={capabilities}
          onClose={() => setDetailId(null)}
          onEdit={(id) => {
            setDetailId(null);
            setFormState({ mode: "edit", organisationId: id });
          }}
          onArchive={(row) => void archiveOrganisation(row)}
          onRestore={(row) => void restoreOrganisation(row)}
          onChanged={(text) => void refreshAfterMutation(text)}
        />
      )}
    </div>
  );
}
