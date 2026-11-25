import { useEffect, useState, useCallback, useMemo } from "react";
import { useSearchParams, Link } from "react-router-dom";
import {
  QrCode,
  Search,
  RefreshCw,
  AlertTriangle,
  RotateCcw,
  Ban,
  Eye,
  Download,
  Printer,
  X,
  Loader2,
  CheckCircle,
  XCircle,
  Table2,
  Trash2,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import { useToast } from "@/components/ui/useToast";
import type { TableQrCode, PaginationMeta, QrGenerateResult } from "@/types/api";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ErrorState from "@/components/public/ErrorState";

function formatDate(str?: string | null) {
  if (!str) return "—";
  return new Date(str).toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export default function AdminQrCodesPage() {
  const { success, error: toastError } = useToast();
  const [searchParams] = useSearchParams();
  const tableParam = searchParams.get("table");

  const [qrCodes, setQrCodes] = useState<TableQrCode[]>([]);
  const [meta, setMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Filters
  const [search, setSearch] = useState(tableParam || "");
  const [statusFilter, setStatusFilter] = useState<"all" | "active" | "revoked">("all");
  const [page, setPage] = useState(1);

  // Action states
  const [regeneratingItem, setRegeneratingItem] = useState<TableQrCode | null>(null);
  const [revokingItem, setRevokingItem] = useState<TableQrCode | null>(null);
  const [deletingItem, setDeletingItem] = useState<TableQrCode | null>(null);
  const [isProcessing, setIsProcessing] = useState(false);

  // QR Modal preview
  const [previewModal, setPreviewModal] = useState<{
    tableNumber: string;
    token?: string;
    qrUrl?: string;
    qrRecord?: TableQrCode;
  } | null>(null);

  const fetchQrCodes = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string | number> = { page };
      if (search) params.search = search;
      if (statusFilter !== "all") {
        params.is_active = statusFilter === "active" ? 1 : 0;
      }

      const resp = await cachedGet<{ success: boolean; data: TableQrCode[]; meta?: PaginationMeta }>("/admin/tables/qr", 15_000, { params });
      const d = resp.data;
      if (d?.success) {
        setQrCodes(Array.isArray(d.data) ? d.data : []);
        if (d.meta) setMeta(d.meta);
      } else {
        setError("Failed to load QR code records.");
      }
    } catch {
      setError("Unable to connect to the QR code management service.");
    } finally {
      setLoading(false);
    }
  }, [page, search, statusFilter]);

  useEffect(() => {
    document.title = "QR Codes | Special Delicacies Admin";
    fetchQrCodes();
  }, [fetchQrCodes]);

  useEffect(() => {
    setPage(1);
  }, [search, statusFilter]);

  // Derived stats from loaded data / total
  const stats = useMemo(() => {
    const total = meta.total || qrCodes.length;
    const active = qrCodes.filter((q) => q.is_active).length;
    const revoked = qrCodes.filter((q) => !q.is_active).length;
    return { total, active, revoked };
  }, [meta.total, qrCodes]);

  // Regenerate handler
  const handleRegenerate = async () => {
    if (!regeneratingItem) return;
    setIsProcessing(true);
    try {
      const tableId = regeneratingItem.restaurant_table_id || regeneratingItem.table?.id;
      if (!tableId) {
        toastError("Table ID not found.");
        return;
      }
      const resp = await api.post<{ success: boolean; data: QrGenerateResult }>(
        `/admin/tables/${tableId}/qr/regenerate`
      );
      if (resp.data?.success && resp.data.data) {
        success(`New QR code generated for Table ${resp.data.data.table.table_number}.`);
        setPreviewModal({
          tableNumber: resp.data.data.table.table_number,
          token: resp.data.data.token,
          qrUrl: resp.data.data.qr_url,
        });
        setRegeneratingItem(null);
        fetchQrCodes();
      } else {
        toastError("Failed to regenerate QR code.");
      }
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to regenerate QR code.");
    } finally {
      setIsProcessing(false);
    }
  };

  // Revoke handler
  const handleRevoke = async () => {
    if (!revokingItem) return;
    setIsProcessing(true);
    try {
      const tableId = revokingItem.restaurant_table_id || revokingItem.table?.id;
      if (!tableId) {
        toastError("Table ID not found.");
        return;
      }
      await api.delete(`/admin/tables/${tableId}/qr/revoke`);
      success(`QR code for table ${revokingItem.table?.table_number || tableId} revoked.`);
      setRevokingItem(null);
      fetchQrCodes();
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to revoke QR code.");
    } finally {
      setIsProcessing(false);
    }
  };

  const handleDeleteRevoked = async () => {
    if (!deletingItem) return;
    setIsProcessing(true);
    try {
      await api.delete(`/admin/tables/qr/${deletingItem.id}`);
      success("Revoked QR code record deleted.");
      setDeletingItem(null);
      fetchQrCodes();
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to delete revoked QR code record.");
    } finally {
      setIsProcessing(false);
    }
  };

  const handlePrint = () => {
    window.print();
  };

  return (
    <div className="space-y-6">
      {/* Printable Area Styles */}
      <style>{`
        @media print {
          body * {
            visibility: hidden;
          }
          #print-qr-card, #print-qr-card * {
            visibility: visible;
          }
          #print-qr-card {
            position: absolute;
            left: 50%;
            top: 20%;
            transform: translate(-50%, -20%);
            width: 100%;
            max-width: 400px;
            padding: 24px;
            text-align: center;
            border: 2px solid #000;
            border-radius: 12px;
            background: white !important;
            color: black !important;
          }
        }
      `}</style>

      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-extrabold flex items-center gap-2 text-[var(--color-text)]">
            <QrCode className="w-7 h-7 text-[var(--color-accent)]" />
            QR Code Management
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-0.5">
            View, generate, print, and revoke table QR identification codes
          </p>
        </div>
        <Link
          to="/admin/tables"
          className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border border-[var(--color-border)] text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors"
        >
          <Table2 className="w-4 h-4" />
          View All Tables
        </Link>
      </div>

      {/* Stats cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="rounded-xl p-4 border border-[var(--color-border)] bg-[var(--color-surface)]">
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wide">
              Total Records
            </span>
            <QrCode className="w-4 h-4 text-[var(--color-accent)]" />
          </div>
          <p className="text-2xl font-extrabold text-[var(--color-accent)]">{stats.total}</p>
        </div>

        <div className="rounded-xl p-4 border border-[var(--color-border)] bg-[var(--color-surface)]">
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wide">
              Active QR Codes
            </span>
            <CheckCircle className="w-4 h-4 text-[var(--color-success)]" />
          </div>
          <p className="text-2xl font-extrabold text-[var(--color-success)]">{stats.active}</p>
        </div>

        <div className="rounded-xl p-4 border border-[var(--color-border)] bg-[var(--color-surface)]">
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wide">
              Revoked / Inactive
            </span>
            <XCircle className="w-4 h-4 text-[var(--color-danger)]" />
          </div>
          <p className="text-2xl font-extrabold text-[var(--color-danger)]">{stats.revoked}</p>
        </div>
      </div>

      {/* Filter / Search Bar */}
      <div className="flex flex-col sm:flex-row gap-3">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--color-text-secondary)]" />
          <input
            type="text"
            placeholder="Search by table number…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-9 pr-4 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-sm text-[var(--color-text)] placeholder-[var(--color-text-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]/40"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as "all" | "active" | "revoked")}
          className="px-3 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]/40"
        >
          <option value="all">All QR Status</option>
          <option value="active">Active Only</option>
          <option value="revoked">Revoked Only</option>
        </select>
        <button
          type="button"
          onClick={fetchQrCodes}
          className="p-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors"
          title="Refresh"
        >
          <RefreshCw className="w-4 h-4" />
        </button>
      </div>

      {/* Content State */}
      {loading && qrCodes.length === 0 ? (
        <div className="flex items-center justify-center min-h-[300px]">
          <LoadingSpinner />
        </div>
      ) : error && qrCodes.length === 0 ? (
        <ErrorState message={error} onRetry={fetchQrCodes} />
      ) : qrCodes.length === 0 ? (
        <div className="text-center py-16 border border-dashed border-[var(--color-border)] rounded-xl bg-[var(--color-surface)]">
          <QrCode className="w-12 h-12 mx-auto mb-3 text-[var(--color-text-secondary)]" />
          <p className="text-lg font-bold text-[var(--color-text)]">No QR codes found</p>
          <p className="text-sm text-[var(--color-text-secondary)] mb-4">
            {search || statusFilter !== "all"
              ? "Try adjusting your search criteria."
              : "Generate QR codes for tables from the Tables management page."}
          </p>
          <Link
            to="/admin/tables"
            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white transition-opacity hover:opacity-90"
            style={{ background: "var(--color-accent)" }}
          >
            Go to Tables
          </Link>
        </div>
      ) : (
        <>
          {/* Desktop Table View */}
          <div className="hidden sm:block overflow-x-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]">
            <table className="w-full text-sm">
              <thead className="border-b border-[var(--color-border)] bg-[var(--color-surface-secondary)]/40">
                <tr>
                  {["Table #", "Capacity", "QR Status", "Generated At", "Revoked At", "Actions"].map((h) => (
                    <th
                      key={h}
                      className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]"
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-[var(--color-border)]">
                {qrCodes.map((item) => (
                  <tr key={item.id} className="hover:bg-[var(--color-surface-secondary)]/30 transition-colors">
                    <td className="px-4 py-3 font-bold text-[var(--color-text)]">
                      {item.table?.table_number ? `Table ${item.table.table_number}` : `Table #${item.restaurant_table_id}`}
                    </td>
                    <td className="px-4 py-3 text-[var(--color-text-secondary)]">
                      {item.table?.capacity ? `${item.table.capacity} seats` : "—"}
                    </td>
                    <td className="px-4 py-3">
                      <span
                        className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold ${
                          item.is_active
                            ? "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300"
                            : "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300"
                        }`}
                      >
                        {item.is_active ? <CheckCircle className="w-3 h-3" /> : <XCircle className="w-3 h-3" />}
                        {item.is_active ? "Active" : "Revoked"}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">
                      {formatDate(item.created_at)}
                    </td>
                    <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">
                      {formatDate(item.revoked_at)}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1.5">
                        <button
                          type="button"
                          onClick={() =>
                            setPreviewModal({
                              tableNumber: item.table?.table_number || String(item.restaurant_table_id),
                              qrRecord: item,
                              qrUrl: item.qr_url || undefined,
                            })
                          }
                          className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors"
                          title="View Details"
                        >
                          <Eye className="w-3.5 h-3.5" />
                          View
                        </button>
                        <button
                          type="button"
                          onClick={() => setRegeneratingItem(item)}
                          className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-[var(--brand-gold)] hover:bg-[var(--color-surface-secondary)] transition-colors"
                          title="Regenerate QR Code"
                        >
                          <RotateCcw className="w-3.5 h-3.5" />
                          Regenerate
                        </button>
                        {item.is_active && (
                          <button
                            type="button"
                            onClick={() => setRevokingItem(item)}
                            className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-[var(--color-danger)] hover:bg-red-50/10 transition-colors"
                            title="Revoke QR Code"
                          >
                            <Ban className="w-3.5 h-3.5" />
                            Revoke
                          </button>
                        )}
                        {!item.is_active && (
                          <button type="button" onClick={() => setDeletingItem(item)} className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-[var(--color-danger)] hover:bg-[var(--color-surface-secondary)]" title="Delete revoked record">
                            <Trash2 className="w-3.5 h-3.5" /> Delete
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Mobile Cards */}
          <div className="sm:hidden space-y-3">
            {qrCodes.map((item) => (
              <div
                key={item.id}
                className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 space-y-3"
              >
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-bold text-[var(--color-text)]">
                      {item.table?.table_number ? `Table ${item.table.table_number}` : `Table #${item.restaurant_table_id}`}
                    </p>
                    <p className="text-xs text-[var(--color-text-secondary)]">
                      Capacity: {item.table?.capacity || "—"} seats
                    </p>
                  </div>
                  <span
                    className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold ${
                      item.is_active
                        ? "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300"
                        : "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300"
                    }`}
                  >
                    {item.is_active ? "Active" : "Revoked"}
                  </span>
                </div>

                <div className="text-xs text-[var(--color-text-secondary)] space-y-1">
                  <p>Generated: {formatDate(item.created_at)}</p>
                  {item.revoked_at && <p className="text-red-500">Revoked: {formatDate(item.revoked_at)}</p>}
                </div>

                <div className="flex items-center gap-2 pt-2 border-t border-[var(--color-border)]">
                  <button
                    type="button"
                    onClick={() =>
                      setPreviewModal({
                        tableNumber: item.table?.table_number || String(item.restaurant_table_id),
                        qrRecord: item,
                        qrUrl: item.qr_url || undefined,
                      })
                    }
                    className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-semibold border border-[var(--color-border)] text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]"
                  >
                    <Eye className="w-3.5 h-3.5" /> View
                  </button>
                  <button
                    type="button"
                    onClick={() => setRegeneratingItem(item)}
                    className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-semibold text-[var(--brand-gold)] border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]"
                  >
                    <RotateCcw className="w-3.5 h-3.5" /> Regenerate
                  </button>
                  {item.is_active && (
                    <button
                      type="button"
                      onClick={() => setRevokingItem(item)}
                      className="p-2 rounded-lg text-xs font-semibold text-[var(--color-danger)] border border-[var(--color-border)] hover:bg-red-50/10"
                      title="Revoke"
                    >
                      <Ban className="w-3.5 h-3.5" />
                    </button>
                  )}
                  {!item.is_active && (
                    <button type="button" onClick={() => setDeletingItem(item)} className="p-2 rounded-lg text-[var(--color-danger)] border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]" title="Delete revoked record">
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>

          {/* Pagination */}
          {meta.last_page > 1 && (
            <div className="flex items-center justify-between gap-2 pt-2">
              <span className="text-xs text-[var(--color-text-secondary)]">
                Page {meta.current_page} of {meta.last_page} ({meta.total} total)
              </span>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1}
                  className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-sm font-semibold disabled:opacity-40 hover:bg-[var(--color-surface-secondary)] transition-colors"
                >
                  Previous
                </button>
                <button
                  type="button"
                  onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                  disabled={page >= meta.last_page}
                  className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-sm font-semibold disabled:opacity-40 hover:bg-[var(--color-surface-secondary)] transition-colors"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </>
      )}

      {/* ── Regenerate Confirmation Modal ── */}
      {regeneratingItem && (
        <ModalWrapper title="Regenerate QR Code" onClose={() => setRegeneratingItem(null)}>
          <div className="space-y-4">
            <div className="flex items-start gap-3 p-3 rounded-xl bg-amber-50/10 border border-amber-500/20">
              <RotateCcw className="w-5 h-5 text-[var(--brand-gold)] flex-shrink-0 mt-0.5" />
              <div>
                <p className="text-sm font-semibold text-[var(--color-text)]">
                  Regenerate QR Code for Table{" "}
                  {regeneratingItem.table?.table_number || regeneratingItem.restaurant_table_id}?
                </p>
                <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                  This will immediately revoke any existing QR code. Any printed codes currently on the table
                  will stop working.
                </p>
              </div>
            </div>
            <div className="flex gap-3">
              <button
                type="button"
                onClick={handleRegenerate}
                disabled={isProcessing}
                className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white transition-all hover:opacity-90 disabled:opacity-60"
                style={{ background: "var(--color-accent)" }}
              >
                {isProcessing && <Loader2 className="w-4 h-4 animate-spin" />}
                Regenerate Now
              </button>
              <button
                type="button"
                onClick={() => setRegeneratingItem(null)}
                className="flex-1 py-2.5 rounded-xl text-sm font-semibold border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]"
              >
                Cancel
              </button>
            </div>
          </div>
        </ModalWrapper>
      )}

      {/* ── Revoke Confirmation Modal ── */}
      {revokingItem && (
        <ModalWrapper title="Revoke QR Code" onClose={() => setRevokingItem(null)}>
          <div className="space-y-4">
            <div className="flex items-start gap-3 p-3 rounded-xl bg-red-50/10 border border-red-500/20">
              <AlertTriangle className="w-5 h-5 text-[var(--color-danger)] flex-shrink-0 mt-0.5" />
              <div>
                <p className="text-sm font-semibold text-[var(--color-text)]">
                  Revoke QR Code for Table{" "}
                  {revokingItem.table?.table_number || revokingItem.restaurant_table_id}?
                </p>
                <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                  Customers will no longer be able to scan this QR code to access the table menu.
                </p>
              </div>
            </div>
            <div className="flex gap-3">
              <button
                type="button"
                onClick={handleRevoke}
                disabled={isProcessing}
                className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white bg-[var(--color-danger)] hover:opacity-90 disabled:opacity-60 transition-all"
              >
                {isProcessing && <Loader2 className="w-4 h-4 animate-spin" />}
                Revoke QR Code
              </button>
              <button
                type="button"
                onClick={() => setRevokingItem(null)}
                className="flex-1 py-2.5 rounded-xl text-sm font-semibold border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]"
              >
                Cancel
              </button>
            </div>
          </div>
        </ModalWrapper>
      )}

      {deletingItem && (
        <ModalWrapper title="Delete Revoked QR Record" onClose={() => setDeletingItem(null)}>
          <div className="space-y-4">
            <p className="text-sm text-[var(--color-text-secondary)]">
              Permanently delete the revoked QR record for table {deletingItem.table?.table_number || deletingItem.restaurant_table_id}?
            </p>
            <div className="flex gap-3">
              <button type="button" onClick={handleDeleteRevoked} disabled={isProcessing} className="flex-1 rounded-xl px-4 py-2.5 bg-[var(--color-danger)] text-white font-semibold disabled:opacity-50">
                {isProcessing ? "Deleting…" : "Delete Record"}
              </button>
              <button type="button" onClick={() => setDeletingItem(null)} className="flex-1 rounded-xl px-4 py-2.5 border border-[var(--color-border)] text-[var(--color-text)]">Cancel</button>
            </div>
          </div>
        </ModalWrapper>
      )}

      {/* ── QR Preview & Print Modal ── */}
      {previewModal && (
        <ModalWrapper
          title={`QR Code for Table ${previewModal.tableNumber}`}
          onClose={() => setPreviewModal(null)}
          maxWidth="max-w-md"
        >
          <div className="space-y-5">
            {previewModal.qrUrl ? (
              <div id="print-qr-card" className="flex flex-col items-center text-center p-4 rounded-xl bg-[var(--color-surface-secondary)]/50 border border-[var(--color-border)]">
                <div className="mb-2">
                  <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-accent)]">
                    Special Delicacies
                  </span>
                  <h3 className="text-xl font-extrabold text-[var(--color-text)]">
                    Table {previewModal.tableNumber}
                  </h3>
                </div>

                {/* QR Image via api.qrserver.com */}
                <div className="p-3 bg-white rounded-xl shadow-sm my-2">
                  <img
                    src={`https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(
                      previewModal.qrUrl
                    )}`}
                    alt={`QR Code for Table ${previewModal.tableNumber}`}
                    width={220}
                    height={220}
                    className="block"
                  />
                </div>

                <p className="text-xs text-[var(--color-text-secondary)] mt-2">
                  Scan this QR code to view menu & place your order
                </p>
                <p className="text-[10px] font-mono text-[var(--color-text-secondary)] mt-1 truncate max-w-xs select-all">
                  {previewModal.qrUrl}
                </p>

                {/* Action buttons (hidden on print) */}
                <div className="flex gap-2 w-full mt-4 print:hidden">
                  <a
                    href={`https://api.qrserver.com/v1/create-qr-code/?size=400x400&format=png&data=${encodeURIComponent(
                      previewModal.qrUrl
                    )}`}
                    download={`qr-table-${previewModal.tableNumber}.png`}
                    target="_blank"
                    rel="noreferrer"
                    className="flex-1 flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg text-xs font-bold text-white transition-opacity hover:opacity-90"
                    style={{ background: "var(--color-accent)" }}
                  >
                    <Download className="w-3.5 h-3.5" />
                    Download PNG
                  </a>
                  <button
                    type="button"
                    onClick={handlePrint}
                    className="flex-1 flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg text-xs font-bold border border-[var(--color-border)] text-[var(--color-text)] hover:bg-[var(--color-surface)] transition-colors"
                  >
                    <Printer className="w-3.5 h-3.5" />
                    Print
                  </button>
                </div>
              </div>
            ) : (
              <div className="space-y-4">
                <div className="p-4 rounded-xl bg-[var(--color-surface-secondary)]/50 border border-[var(--color-border)] space-y-2 text-sm">
                  <p className="font-semibold text-[var(--color-text)]">
                    Table: <span className="font-bold">{previewModal.tableNumber}</span>
                  </p>
                  <p className="text-[var(--color-text-secondary)]">
                    Status:{" "}
                    <span
                      className={`font-bold ${
                        previewModal.qrRecord?.is_active ? "text-green-600" : "text-red-500"
                      }`}
                    >
                      {previewModal.qrRecord?.is_active ? "Active" : "Revoked"}
                    </span>
                  </p>
                  <p className="text-xs text-[var(--color-text-secondary)]">
                    Generated: {formatDate(previewModal.qrRecord?.created_at)}
                  </p>
                  {previewModal.qrRecord?.revoked_at && (
                    <p className="text-xs text-red-500">
                      Revoked: {formatDate(previewModal.qrRecord?.revoked_at)}
                    </p>
                  )}
                </div>

                <div className="p-3 rounded-xl bg-amber-50/10 border border-amber-500/20 text-xs text-[var(--color-text-secondary)]">
                  <p className="font-semibold text-[var(--color-text)] mb-1">
                    Plain QR token is securely hashed on creation.
                  </p>
                  To download or print a new QR code image, click "Regenerate QR Code" below.
                </div>

                <div className="flex gap-2">
                  {previewModal.qrRecord && (
                    <button
                      type="button"
                      onClick={() => {
                        const rec = previewModal.qrRecord;
                        setPreviewModal(null);
                        if (rec) setRegeneratingItem(rec);
                      }}
                      className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold text-white transition-opacity hover:opacity-90"
                      style={{ background: "var(--color-accent)" }}
                    >
                      <RotateCcw className="w-3.5 h-3.5" />
                      Regenerate & View QR
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={() => setPreviewModal(null)}
                    className="flex-1 py-2 rounded-xl text-xs font-semibold border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]"
                  >
                    Close
                  </button>
                </div>
              </div>
            )}
          </div>
        </ModalWrapper>
      )}
    </div>
  );
}

function ModalWrapper({
  title,
  onClose,
  maxWidth = "max-w-md",
  children,
}: {
  title: string;
  onClose: () => void;
  maxWidth?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="action-modal-backdrop absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} aria-hidden="true" />
      <div
        className={`action-modal-panel relative z-10 w-full ${maxWidth} bg-[var(--color-surface)] rounded-2xl shadow-2xl border border-[var(--color-border)] p-6`}
      >
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-extrabold text-[var(--color-text)]">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)] transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
