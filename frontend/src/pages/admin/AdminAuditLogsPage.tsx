import { useState, useEffect, useCallback } from "react";
import {
  FileCode,
  Search,
  Calendar,
  Eye,
  RefreshCw,
  AlertCircle,
  X,
  History,
} from "lucide-react";
import { cachedGet } from "@/lib/api";
import type { AdminAuditLogItem, PaginatedResponse } from "@/types/api";

export default function AdminAuditLogsPage() {
  const [logs, setLogs] = useState<AdminAuditLogItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Filters
  const [search, setSearch] = useState("");
  const [actionFilter, setActionFilter] = useState("");
  const [fromDate, setFromDate] = useState("");
  const [toDate, setToDate] = useState("");
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalCount, setTotalCount] = useState(0);

  // Inspector modal
  const [inspectLog, setInspectLog] = useState<AdminAuditLogItem | null>(null);

  useEffect(() => {
    document.title = "Audit Trail | Special Delicacies Admin";
  }, []);

  const fetchLogs = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (search) params.append("search", search);
      if (actionFilter) params.append("action", actionFilter);
      if (fromDate) params.append("from_date", fromDate);
      if (toDate) params.append("to_date", toDate);
      params.append("page", page.toString());
      params.append("per_page", "25");

      const response = await cachedGet<PaginatedResponse<AdminAuditLogItem>>(`/admin/audit-logs?${params.toString()}`, 10_000);
      if (response.data.success) {
        setLogs(response.data.data);
        setTotalPages(response.data.meta.last_page);
        setTotalCount(response.data.meta.total);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to load audit logs.";
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, [search, actionFilter, fromDate, toDate, page]);

  useEffect(() => {
    fetchLogs();
  }, [fetchLogs]);

  const getActionBadge = (action: string) => {
    if (action.includes("create")) {
      return "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300";
    }
    if (action.includes("update") || action.includes("transition")) {
      return "bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300";
    }
    if (action.includes("deactivate") || action.includes("delete") || action.includes("revoke")) {
      return "bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300";
    }
    if (action.includes("activate") || action.includes("confirm")) {
      return "bg-teal-100 text-teal-800 dark:bg-teal-950/60 dark:text-teal-300";
    }
    if (action.includes("password")) {
      return "bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300";
    }
    return "bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300";
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-[var(--color-border)]">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Administrative Audit Trail
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1">
            Immutable traceability records for staff, roles, settings, content, and orders
          </p>
        </div>

        <div className="flex items-center gap-2 self-start sm:self-auto">
          <span className="text-xs font-semibold px-3 py-1.5 rounded-lg bg-[var(--color-surface)] border border-[var(--color-border)] text-[var(--color-text)]">
            {totalCount} total audit records
          </span>
          <button
            type="button"
            onClick={() => fetchLogs()}
            className="p-2 border border-[var(--color-border)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] rounded-xl transition-colors"
            title="Refresh logs"
          >
            <RefreshCw size={16} />
          </button>
        </div>
      </div>

      {/* Notifications */}
      {error && (
        <div className="p-4 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/50 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <AlertCircle className="text-rose-600 dark:text-rose-400 shrink-0" size={20} />
            <p className="text-sm text-rose-700 dark:text-rose-300">{error}</p>
          </div>
          <button
            type="button"
            onClick={() => setError(null)}
            className="text-rose-600 hover:text-rose-800"
          >
            <X size={18} />
          </button>
        </div>
      )}

      {/* Filter and Search Bar */}
      <div className="p-4 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs space-y-3">
        <div className="flex flex-col md:flex-row gap-3">
          <div className="relative flex-1">
            <Search
              size={18}
              className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]"
            />
            <input
              type="text"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              placeholder="Search by description, actor name, action..."
              className="w-full pl-10 pr-4 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-sm text-[var(--color-text)] placeholder-[var(--color-text-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <select
              value={actionFilter}
              onChange={(e) => {
                setActionFilter(e.target.value);
                setPage(1);
              }}
              className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            >
              <option value="">All Actions</option>
              <option value="staff.create">Staff Created</option>
              <option value="staff.update">Staff Updated</option>
              <option value="staff.activate">Staff Activated</option>
              <option value="staff.deactivate">Staff Deactivated</option>
              <option value="staff.password_reset">Password Reset</option>
              <option value="role.permissions_update">Role Permissions</option>
              <option value="order.confirm_payment">Payment Confirmed</option>
              <option value="order.status_transition">Order Transition</option>
              <option value="settings.update">Settings Updated</option>
              <option value="website_content.update">Website Content</option>
            </select>
          </div>
        </div>

        {/* Date Filter Row */}
        <div className="flex flex-wrap items-center gap-2 pt-2 border-t border-[var(--color-border)]/60 text-xs">
          <span className="text-[var(--color-text-secondary)] font-semibold flex items-center gap-1">
            <Calendar size={14} /> Filter Dates:
          </span>
          <input
            type="date"
            value={fromDate}
            onChange={(e) => {
              setFromDate(e.target.value);
              setPage(1);
            }}
            className="px-2.5 py-1.5 bg-[var(--color-background)] border border-[var(--color-border)] rounded-lg text-xs text-[var(--color-text)]"
          />
          <span className="text-[var(--color-text-secondary)]">to</span>
          <input
            type="date"
            value={toDate}
            onChange={(e) => {
              setToDate(e.target.value);
              setPage(1);
            }}
            className="px-2.5 py-1.5 bg-[var(--color-background)] border border-[var(--color-border)] rounded-lg text-xs text-[var(--color-text)]"
          />
          {(fromDate || toDate) && (
            <button
              type="button"
              onClick={() => {
                setFromDate("");
                setToDate("");
                setPage(1);
              }}
              className="text-xs text-[var(--brand-copper)] hover:underline ml-1"
            >
              Clear
            </button>
          )}
        </div>
      </div>

      {/* Logs Table */}
      <div className="rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3">
            <RefreshCw size={24} className="animate-spin mx-auto text-[var(--brand-copper)]" />
            <p className="text-sm font-medium">Loading audit history...</p>
          </div>
        ) : logs.length === 0 ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3">
            <History size={32} className="mx-auto text-[var(--color-text-secondary)]/50" />
            <p className="text-base font-semibold text-[var(--color-text)]">No audit records found</p>
            <p className="text-xs">Administrative actions will appear here automatically.</p>
          </div>
        ) : (
          <>
            {/* Desktop Table View */}
            <div className="hidden md:block overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-[var(--color-border)] text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)] bg-[var(--color-background)]/50">
                    <th className="py-3 px-5">Timestamp</th>
                    <th className="py-3 px-5">Actor</th>
                    <th className="py-3 px-5">Action</th>
                    <th className="py-3 px-5">Description</th>
                    <th className="py-3 px-5">Origin IP</th>
                    <th className="py-3 px-5 text-right">Details</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[var(--color-border)]">
                  {logs.map((log) => (
                    <tr key={log.id} className="hover:bg-[var(--color-surface-secondary)]/40 transition-colors">
                      <td className="py-3.5 px-5 text-xs text-[var(--color-text-secondary)] whitespace-nowrap">
                        {new Date(log.created_at).toLocaleString()}
                      </td>
                      <td className="py-3.5 px-5">
                        <div className="flex items-center gap-2">
                          <div className="w-7 h-7 rounded-full bg-[var(--brand-copper)]/10 text-[var(--brand-copper)] flex items-center justify-center font-bold text-xs">
                            {log.user?.name ? log.user.name.charAt(0).toUpperCase() : "S"}
                          </div>
                          <div>
                            <p className="font-semibold text-xs text-[var(--color-text)]">
                              {log.user?.name ?? "System / CLI"}
                            </p>
                            {log.user?.email && (
                              <p className="text-[10px] text-[var(--color-text-secondary)] font-mono">
                                {log.user.email}
                              </p>
                            )}
                          </div>
                        </div>
                      </td>
                      <td className="py-3.5 px-5">
                        <span
                          className={`inline-flex px-2 py-0.5 rounded-full text-[11px] font-mono font-semibold ${getActionBadge(
                            log.action
                          )}`}
                        >
                          {log.action}
                        </span>
                      </td>
                      <td className="py-3.5 px-5 text-xs text-[var(--color-text)] max-w-md truncate">
                        {log.description}
                      </td>
                      <td className="py-3.5 px-5 text-xs font-mono text-[var(--color-text-secondary)]">
                        {log.ip_address || "127.0.0.1"}
                      </td>
                      <td className="py-3.5 px-5 text-right">
                        {(log.old_values || log.new_values) && (
                          <button
                            type="button"
                            onClick={() => setInspectLog(log)}
                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)] text-xs font-semibold text-[var(--color-text)] transition-colors"
                          >
                            <Eye size={12} /> Inspect
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Mobile Card View */}
            <div className="block md:hidden divide-y divide-[var(--color-border)]">
              {logs.map((log) => (
                <div key={log.id} className="p-4 space-y-2">
                  <div className="flex items-center justify-between text-xs">
                    <span
                      className={`px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold ${getActionBadge(
                        log.action
                      )}`}
                    >
                      {log.action}
                    </span>
                    <span className="text-[10px] text-[var(--color-text-secondary)]">
                      {new Date(log.created_at).toLocaleTimeString([], {
                        hour: "2-digit",
                        minute: "2-digit",
                      })}
                    </span>
                  </div>

                  <p className="text-xs font-medium text-[var(--color-text)] leading-relaxed">
                    {log.description}
                  </p>

                  <div className="flex items-center justify-between text-[11px] text-[var(--color-text-secondary)] pt-1 border-t border-[var(--color-border)]/60">
                    <span>By {log.user?.name ?? "System"}</span>
                    {(log.old_values || log.new_values) && (
                      <button
                        type="button"
                        onClick={() => setInspectLog(log)}
                        className="text-[var(--brand-copper)] font-semibold hover:underline flex items-center gap-1"
                      >
                        <Eye size={12} /> View Changes
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>

            {/* Pagination Controls */}
            {totalPages > 1 && (
              <div className="p-4 border-t border-[var(--color-border)] flex items-center justify-between text-xs text-[var(--color-text-secondary)]">
                <span>
                  Page {page} of {totalPages}
                </span>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] disabled:opacity-40 hover:bg-[var(--color-surface-secondary)] text-[var(--color-text)] font-semibold"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    disabled={page >= totalPages}
                    onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                    className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] disabled:opacity-40 hover:bg-[var(--color-surface-secondary)] text-[var(--color-text)] font-semibold"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* INSPECT CHANGES MODAL */}
      {inspectLog && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="w-full max-w-xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden max-h-[85vh] flex flex-col">
            <div className="p-5 border-b border-[var(--color-border)] flex items-center justify-between">
              <div>
                <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
                  <FileCode size={18} className="text-[var(--brand-copper)]" />
                  Audit Record Change Inspector
                </h2>
                <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">
                  Action: {inspectLog.action} on {new Date(inspectLog.created_at).toLocaleString()}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setInspectLog(null)}
                className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
              >
                <X size={20} />
              </button>
            </div>

            <div className="p-5 overflow-y-auto space-y-4 text-xs font-mono">
              <div className="p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)]">
                <span className="text-[10px] uppercase font-bold text-[var(--color-text-secondary)] block font-sans mb-1">
                  Description
                </span>
                <p className="font-sans text-xs text-[var(--color-text)] font-medium">
                  {inspectLog.description}
                </p>
              </div>

              {inspectLog.old_values && (
                <div>
                  <span className="text-[10px] uppercase font-bold text-[var(--color-text-secondary)] block font-sans mb-1">
                    Previous State (Before)
                  </span>
                  <pre className="p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] overflow-x-auto text-[11px] text-rose-700 dark:text-rose-400">
                    {JSON.stringify(inspectLog.old_values, null, 2)}
                  </pre>
                </div>
              )}

              {inspectLog.new_values && (
                <div>
                  <span className="text-[10px] uppercase font-bold text-[var(--color-text-secondary)] block font-sans mb-1">
                    Updated State (After)
                  </span>
                  <pre className="p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] overflow-x-auto text-[11px] text-emerald-700 dark:text-emerald-400">
                    {JSON.stringify(inspectLog.new_values, null, 2)}
                  </pre>
                </div>
              )}
            </div>

            <div className="p-4 border-t border-[var(--color-border)] text-right bg-[var(--color-surface)]">
              <button
                type="button"
                onClick={() => setInspectLog(null)}
                className="px-4 py-2 rounded-xl bg-[var(--color-surface-secondary)] hover:bg-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] transition-colors"
              >
                Close Inspector
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
