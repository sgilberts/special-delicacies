import { useEffect, useState, useCallback } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  Table2,
  Plus,
  Search,
  Edit2,
  Trash2,
  X,
  Loader2,
  AlertTriangle,
  Power,
  QrCode,
  Users,
  CheckCircle,
  XCircle,
  RefreshCw,
  Printer,
  Download,
  RotateCcw,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import { useToast } from "@/components/ui/useToast";
import type { ApiResponse, RestaurantTable, TableStats, PaginationMeta } from "@/types/api";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ErrorState from "@/components/public/ErrorState";

const tableSchema = z.object({
  table_number: z.string().min(1, "Table number is required").max(20, "Max 20 characters"),
  capacity: z.number().int().min(1, "Min 1 seat").max(50, "Max 50 seats"),
  is_active: z.boolean(),
});

type TableFormValues = z.infer<typeof tableSchema>;

function formatDate(str?: string) {
  if (!str) return "—";
  return new Date(str).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
}

export default function AdminTablesPage() {
  const { success, error: toastError } = useToast();

  const [tables, setTables] = useState<RestaurantTable[]>([]);
  const [stats, setStats] = useState<TableStats>({ total: 0, active: 0, inactive: 0, with_qr: 0 });
  const [meta, setMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<"all" | "active" | "inactive">("all");
  const [page, setPage] = useState(1);

  // Modal states
  const [showModal, setShowModal] = useState(false);
  const [editingTable, setEditingTable] = useState<RestaurantTable | null>(null);
  const [deletingTable, setDeletingTable] = useState<RestaurantTable | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [isTogglingId, setIsTogglingId] = useState<number | null>(null);

  // QR Modal states
  const [qrModalTable, setQrModalTable] = useState<RestaurantTable | null>(null);
  const [qrModalData, setQrModalData] = useState<{ qrUrl?: string; token?: string } | null>(null);
  const [isQrLoading, setIsQrLoading] = useState(false);
  const [isQrActionLoading, setIsQrActionLoading] = useState(false);

  const openQrModal = async (table: RestaurantTable) => {
    setQrModalTable(table);
    setIsQrLoading(true);
    setQrModalData(null);
    try {
      const resp = await api.get<ApiResponse<{ qr_url: string; token: string }>>(`/admin/tables/${table.id}/qr`);
      if (resp.data?.success && resp.data.data) {
        setQrModalData({
          qrUrl: resp.data.data.qr_url,
          token: resp.data.data.token,
        });
      }
    } catch {
      setQrModalData(null);
    } finally {
      setIsQrLoading(false);
    }
  };

  const handleGenerateQr = async (tableId: number) => {
    setIsQrActionLoading(true);
    try {
      const resp = await api.post(`/admin/tables/${tableId}/qr/generate`);
      if (resp.data?.success && resp.data.data) {
        setQrModalData({
          qrUrl: resp.data.data.qr_url,
          token: resp.data.data.token,
        });
        success("QR code generated successfully.");
        fetchTables();
      }
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to generate QR code.");
    } finally {
      setIsQrActionLoading(false);
    }
  };

  const handleRegenerateQr = async (tableId: number) => {
    setIsQrActionLoading(true);
    try {
      const resp = await api.post(`/admin/tables/${tableId}/qr/regenerate`);
      if (resp.data?.success && resp.data.data) {
        setQrModalData({
          qrUrl: resp.data.data.qr_url,
          token: resp.data.data.token,
        });
        success("QR code regenerated successfully.");
        fetchTables();
      }
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to regenerate QR code.");
    } finally {
      setIsQrActionLoading(false);
    }
  };

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
    setError: setFormError,
  } = useForm<TableFormValues>({
    resolver: zodResolver(tableSchema),
    defaultValues: { table_number: "", capacity: 4, is_active: true },
  });

  const fetchTables = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string | number> = { page };
      if (search) params.search = search;
      if (statusFilter !== "all") params.is_active = statusFilter === "active" ? 1 : 0;

      const resp = await cachedGet<{ success: boolean; data: RestaurantTable[]; meta?: PaginationMeta; stats?: TableStats }>("/admin/tables", 15_000, { params });
      const d = resp.data;
      if (d?.success) {
        setTables(Array.isArray(d.data) ? d.data : []);
        if (d.meta) setMeta(d.meta);
        if (d.stats) setStats(d.stats);
      } else {
        setError("Failed to load tables.");
      }
    } catch {
      setError("Unable to connect to the tables service.");
    } finally {
      setLoading(false);
    }
  }, [page, search, statusFilter]);

  useEffect(() => {
    document.title = "Tables | Special Delicacies Admin";
    fetchTables();
  }, [fetchTables]);

  // Debounce search
  useEffect(() => {
    setPage(1);
  }, [search, statusFilter]);

  const openCreateModal = () => {
    reset({ table_number: "", capacity: 4, is_active: true });
    setEditingTable(null);
    setShowModal(true);
  };

  const openEditModal = (t: RestaurantTable) => {
    reset({ table_number: t.table_number, capacity: t.capacity, is_active: t.is_active });
    setEditingTable(t);
    setShowModal(true);
  };

  const closeModal = () => {
    setShowModal(false);
    setEditingTable(null);
  };

  const onSubmit = async (values: TableFormValues) => {
    setIsSubmitting(true);
    try {
      if (editingTable) {
        await api.put(`/admin/tables/${editingTable.id}`, values);
        success("Table updated successfully.");
      } else {
        await api.post("/admin/tables", values);
        success("Table created successfully.");
      }
      closeModal();
      fetchTables();
    } catch (err: unknown) {
      const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } };
      const apiErrors = e?.response?.data?.errors;
      if (apiErrors) {
        Object.entries(apiErrors).forEach(([field, msgs]) => {
          setFormError(field as keyof TableFormValues, { message: msgs[0] });
        });
      } else {
        toastError(e?.response?.data?.message || "Failed to save table.");
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleToggleStatus = async (t: RestaurantTable) => {
    setIsTogglingId(t.id);
    try {
      await api.patch(`/admin/tables/${t.id}/status`);
      success(`Table ${t.table_number} ${t.is_active ? "deactivated" : "activated"}.`);
      fetchTables();
    } catch {
      toastError("Failed to update table status.");
    } finally {
      setIsTogglingId(null);
    }
  };

  const handleDelete = async () => {
    if (!deletingTable) return;
    setIsDeleting(true);
    try {
      await api.delete(`/admin/tables/${deletingTable.id}`);
      success(`Table ${deletingTable.table_number} deleted.`);
      setDeletingTable(null);
      fetchTables();
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to delete table.");
    } finally {
      setIsDeleting(false);
    }
  };

  const statCards = [
    { label: "Total Tables", value: stats.total, icon: Table2, color: "var(--color-accent)" },
    { label: "Active", value: stats.active, icon: CheckCircle, color: "var(--color-success)" },
    { label: "Inactive", value: stats.inactive, icon: XCircle, color: "var(--color-danger)" },
    { label: "With QR Code", value: stats.with_qr, icon: QrCode, color: "var(--brand-gold)" },
  ];

  if (loading && tables.length === 0) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <LoadingSpinner />
      </div>
    );
  }

  if (error && tables.length === 0) {
    return <ErrorState message={error} onRetry={fetchTables} />;
  }

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-extrabold flex items-center gap-2 text-[var(--color-text)]">
            <Table2 className="w-7 h-7 text-[var(--color-accent)]" />
            Restaurant Tables
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-0.5">
            Manage your restaurant's seating layout
          </p>
        </div>
        <button
          type="button"
          onClick={openCreateModal}
          className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition-all hover:opacity-90 active:scale-95"
          style={{ background: "var(--color-accent)" }}
        >
          <Plus className="w-4 h-4" />
          Add Table
        </button>
      </div>

      {/* Stats cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {statCards.map((card) => {
          const Icon = card.icon;
          return (
            <div
              key={card.label}
              className="rounded-xl p-4 border border-[var(--color-border)] bg-[var(--color-surface)]"
            >
              <div className="flex items-center justify-between mb-2">
                <span className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wide">
                  {card.label}
                </span>
                <Icon className="w-4 h-4" style={{ color: card.color }} />
              </div>
              <p className="text-2xl font-extrabold" style={{ color: card.color }}>
                {card.value}
              </p>
            </div>
          );
        })}
      </div>

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-3">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--color-text-secondary)]" />
          <input
            type="text"
            placeholder="Search tables…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-9 pr-4 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-sm text-[var(--color-text)] placeholder-[var(--color-text-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]/40"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as "all" | "active" | "inactive")}
          className="px-3 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]/40"
        >
          <option value="all">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
        <button
          type="button"
          onClick={fetchTables}
          className="p-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors"
          title="Refresh"
        >
          <RefreshCw className="w-4 h-4" />
        </button>
      </div>

      {/* Loading overlay */}
      {loading && (
        <div className="text-center py-4 text-sm text-[var(--color-text-secondary)] animate-pulse">
          Refreshing…
        </div>
      )}

      {/* Desktop table */}
      {tables.length === 0 && !loading ? (
        <div className="text-center py-16 border border-dashed border-[var(--color-border)] rounded-xl bg-[var(--color-surface)]">
          <Table2 className="w-12 h-12 mx-auto mb-3 text-[var(--color-text-secondary)]" />
          <p className="text-lg font-bold text-[var(--color-text)]">No tables found</p>
          <p className="text-sm text-[var(--color-text-secondary)] mb-4">
            {search || statusFilter !== "all" ? "Try clearing your filters." : "Add your first table to get started."}
          </p>
          {!search && statusFilter === "all" && (
            <button
              type="button"
              onClick={openCreateModal}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white"
              style={{ background: "var(--color-accent)" }}
            >
              <Plus className="w-4 h-4" /> Add Table
            </button>
          )}
        </div>
      ) : (
        <>
          {/* Desktop table view */}
          <div className="hidden sm:block overflow-x-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]">
            <table className="w-full text-sm">
              <thead className="border-b border-[var(--color-border)] bg-[var(--color-surface-secondary)]/40">
                <tr>
                  {["Table #", "Capacity", "Status", "QR Status", "Created", "Actions"].map((h) => (
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
                {tables.map((t) => (
                  <tr key={t.id} className="hover:bg-[var(--color-surface-secondary)]/30 transition-colors">
                    <td className="px-4 py-3 font-bold text-[var(--color-text)]">{t.table_number}</td>
                    <td className="px-4 py-3 text-[var(--color-text-secondary)]">
                      <span className="flex items-center gap-1.5">
                        <Users className="w-3.5 h-3.5" />
                        {t.capacity} seats
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge active={t.is_active} />
                    </td>
                    <td className="px-4 py-3">
                      <QrBadge hasQr={!!t.has_active_qr} />
                    </td>
                    <td className="px-4 py-3 text-[var(--color-text-secondary)] text-xs">
                      {formatDate(t.created_at)}
                    </td>
                    <td className="px-4 py-3">
                      <RowActions
                        table={t}
                        isTogglingId={isTogglingId}
                        onEdit={() => openEditModal(t)}
                        onToggle={() => handleToggleStatus(t)}
                        onQr={() => openQrModal(t)}
                        onDelete={() => setDeletingTable(t)}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Mobile cards */}
          <div className="sm:hidden space-y-3">
            {tables.map((t) => (
              <div
                key={t.id}
                className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
              >
                <div className="flex items-start justify-between gap-2 mb-3">
                  <div>
                    <p className="font-bold text-[var(--color-text)]">Table {t.table_number}</p>
                    <p className="text-xs text-[var(--color-text-secondary)] flex items-center gap-1 mt-0.5">
                      <Users className="w-3 h-3" /> {t.capacity} seats
                    </p>
                  </div>
                  <div className="flex flex-col items-end gap-1">
                    <StatusBadge active={t.is_active} />
                    <QrBadge hasQr={!!t.has_active_qr} />
                  </div>
                </div>
                <p className="text-xs text-[var(--color-text-secondary)] mb-3">
                  Created: {formatDate(t.created_at)}
                </p>
                <RowActions
                  table={t}
                  isTogglingId={isTogglingId}
                  onEdit={() => openEditModal(t)}
                  onToggle={() => handleToggleStatus(t)}
                  onQr={() => openQrModal(t)}
                  onDelete={() => setDeletingTable(t)}
                />
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

      {/* Create / Edit Modal */}
      {showModal && (
        <Modal title={editingTable ? `Edit Table ${editingTable.table_number}` : "Add New Table"} onClose={closeModal}>
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
            <div>
              <label className="block text-sm font-semibold mb-1 text-[var(--color-text)]">
                Table Number <span className="text-[var(--color-danger)]">*</span>
              </label>
              <input
                {...register("table_number")}
                className="w-full px-3 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]/40"
                placeholder="e.g. T1, A-01, VIP-3"
              />
              {errors.table_number && (
                <p className="text-xs text-[var(--color-danger)] mt-1">{errors.table_number.message}</p>
              )}
            </div>
            <div>
              <label className="block text-sm font-semibold mb-1 text-[var(--color-text)]">
                Capacity (seats)
              </label>
              <input
                type="number"
                min={1}
                max={50}
                {...register("capacity", { valueAsNumber: true })}
                className="w-full px-3 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]/40"
              />
              {errors.capacity && (
                <p className="text-xs text-[var(--color-danger)] mt-1">{errors.capacity.message}</p>
              )}
            </div>
            <label className="flex items-center gap-3 cursor-pointer">
              <input type="checkbox" {...register("is_active")} className="w-4 h-4 rounded" />
              <span className="text-sm font-semibold text-[var(--color-text)]">Active</span>
            </label>

            <div className="flex gap-3 pt-2">
              <button
                type="submit"
                disabled={isSubmitting}
                className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white transition-all hover:opacity-90 disabled:opacity-60"
                style={{ background: "var(--color-accent)" }}
              >
                {isSubmitting && <Loader2 className="w-4 h-4 animate-spin" />}
                {editingTable ? "Save Changes" : "Create Table"}
              </button>
              <button
                type="button"
                onClick={closeModal}
                className="flex-1 py-2.5 rounded-xl text-sm font-semibold border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)] transition-colors"
              >
                Cancel
              </button>
            </div>
          </form>
        </Modal>
      )}

      {/* Delete confirmation */}
      {deletingTable && (
        <Modal title="Delete Table" onClose={() => setDeletingTable(null)}>
          <div className="space-y-4">
            <div className="flex items-start gap-3 p-3 rounded-xl bg-red-50/10 border border-red-200/20">
              <AlertTriangle className="w-5 h-5 text-[var(--color-danger)] flex-shrink-0 mt-0.5" />
              <div>
                <p className="text-sm font-semibold text-[var(--color-text)]">
                  Delete Table "{deletingTable.table_number}"?
                </p>
                <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                  This action cannot be undone. Tables with active orders cannot be deleted.
                </p>
              </div>
            </div>
            <div className="flex gap-3">
              <button
                type="button"
                onClick={handleDelete}
                disabled={isDeleting}
                className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white bg-[var(--color-danger)] hover:opacity-90 disabled:opacity-60 transition-all"
              >
                {isDeleting && <Loader2 className="w-4 h-4 animate-spin" />}
                Delete
              </button>
              <button
                type="button"
                onClick={() => setDeletingTable(null)}
                className="flex-1 py-2.5 rounded-xl text-sm font-semibold border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)] transition-colors"
              >
                Cancel
              </button>
            </div>
          </div>
        </Modal>
      )}

      {/* ── Table QR Code View / Print / Generate Modal ── */}
      {qrModalTable && (
        <Modal
          title={`QR Code for Table ${qrModalTable.table_number}`}
          onClose={() => {
            setQrModalTable(null);
            setQrModalData(null);
          }}
        >
          {/* Printable Styles */}
          <style>{`
            @media print {
              body * {
                visibility: hidden;
              }
              #print-table-qr-card, #print-table-qr-card * {
                visibility: visible;
              }
              #print-table-qr-card {
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

          {isQrLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="w-8 h-8 animate-spin text-[var(--color-accent)]" />
            </div>
          ) : qrModalData?.qrUrl ? (
            <div className="space-y-4">
              <div
                id="print-table-qr-card"
                className="flex flex-col items-center text-center p-4 rounded-xl bg-[var(--color-surface-secondary)]/50 border border-[var(--color-border)]"
              >
                <div className="mb-2">
                  <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-accent)]">
                    Special Delicacies
                  </span>
                  <h3 className="text-xl font-extrabold text-[var(--color-text)]">
                    Table {qrModalTable.table_number}
                  </h3>
                  <p className="text-xs text-[var(--color-text-secondary)]">
                    {qrModalTable.capacity} Seats · Dine-in
                  </p>
                </div>

                <div className="p-3 bg-white rounded-xl shadow-sm my-2 border">
                  <img
                    src={`https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(
                      qrModalData.qrUrl
                    )}`}
                    alt={`QR Code for Table ${qrModalTable.table_number}`}
                    width={220}
                    height={220}
                    className="block"
                  />
                </div>

                <p className="text-xs text-[var(--color-text-secondary)] mt-1 font-semibold">
                  Scan to view menu & place your order
                </p>
                <p className="text-[10px] font-mono text-[var(--color-text-secondary)] mt-0.5 truncate max-w-xs select-all">
                  {qrModalData.qrUrl}
                </p>

                {/* Print and Download buttons (hidden on paper) */}
                <div className="flex gap-2 w-full mt-4 print:hidden">
                  <button
                    type="button"
                    onClick={() => window.print()}
                    className="flex-1 flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-lg text-xs font-bold text-white transition-opacity hover:opacity-90 shadow-sm"
                    style={{ background: "var(--color-accent)" }}
                  >
                    <Printer className="w-3.5 h-3.5" />
                    Print QR Code
                  </button>
                  <a
                    href={`https://api.qrserver.com/v1/create-qr-code/?size=400x400&format=png&data=${encodeURIComponent(
                      qrModalData.qrUrl
                    )}`}
                    download={`qr-table-${qrModalTable.table_number}.png`}
                    target="_blank"
                    rel="noreferrer"
                    className="flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-lg text-xs font-bold border border-[var(--color-border)] text-[var(--color-text)] hover:bg-[var(--color-surface)] transition-colors"
                  >
                    <Download className="w-3.5 h-3.5" />
                    PNG
                  </a>
                </div>
              </div>

              {/* Regenerate Action */}
              <div className="flex items-center justify-between pt-2 border-t border-[var(--color-border)]">
                <p className="text-xs text-[var(--color-text-secondary)]">Need a new scannable code?</p>
                <button
                  type="button"
                  onClick={() => handleRegenerateQr(qrModalTable.id)}
                  disabled={isQrActionLoading}
                  className="flex items-center gap-1 text-xs font-semibold text-[var(--brand-gold)] hover:underline disabled:opacity-50"
                >
                  <RotateCcw className={`w-3.5 h-3.5 ${isQrActionLoading ? "animate-spin" : ""}`} />
                  Regenerate QR
                </button>
              </div>
            </div>
          ) : (
            <div className="space-y-4 text-center py-4">
              <div className="w-12 h-12 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-600 flex items-center justify-center mx-auto">
                <QrCode className="w-6 h-6" />
              </div>
              <div>
                <p className="font-bold text-base text-[var(--color-text)]">
                  Table {qrModalTable.table_number} has no active QR Code
                </p>
                <p className="text-xs text-[var(--color-text-secondary)] mt-1 max-w-xs mx-auto">
                  Generate a cryptographically secure QR code for this table so customers can scan and order.
                </p>
              </div>

              <div className="pt-2">
                <button
                  type="button"
                  onClick={() => handleGenerateQr(qrModalTable.id)}
                  disabled={isQrActionLoading}
                  className="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-50 shadow-sm"
                  style={{ background: "var(--color-accent)" }}
                >
                  {isQrActionLoading ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : (
                    <QrCode className="w-4 h-4" />
                  )}
                  Generate & View QR Code
                </button>
              </div>
            </div>
          )}
        </Modal>
      )}
    </div>
  );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function StatusBadge({ active }: { active: boolean }) {
  return (
    <span
      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold ${
        active
          ? "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300"
          : "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300"
      }`}
    >
      {active ? <CheckCircle className="w-3 h-3" /> : <XCircle className="w-3 h-3" />}
      {active ? "Active" : "Inactive"}
    </span>
  );
}

function QrBadge({ hasQr }: { hasQr: boolean }) {
  return (
    <span
      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold ${
        hasQr
          ? "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300"
          : "bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300"
      }`}
    >
      <QrCode className="w-3 h-3" />
      {hasQr ? "Has QR" : "No QR"}
    </span>
  );
}

interface RowActionsProps {
  table: RestaurantTable;
  isTogglingId: number | null;
  onEdit: () => void;
  onToggle: () => void;
  onQr: () => void;
  onDelete: () => void;
}

function RowActions({ table, isTogglingId, onEdit, onToggle, onQr, onDelete }: RowActionsProps) {
  return (
    <div className="flex items-center gap-1 flex-wrap">
      <button
        type="button"
        onClick={onEdit}
        className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-accent)] hover:bg-[var(--color-surface-secondary)] transition-colors"
        title="Edit"
      >
        <Edit2 className="w-4 h-4" />
      </button>
      <button
        type="button"
        onClick={onToggle}
        disabled={isTogglingId === table.id}
        className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-accent)] hover:bg-[var(--color-surface-secondary)] transition-colors disabled:opacity-50"
        title={table.is_active ? "Deactivate" : "Activate"}
      >
        {isTogglingId === table.id ? (
          <Loader2 className="w-4 h-4 animate-spin" />
        ) : (
          <Power className="w-4 h-4" />
        )}
      </button>
      <button
        type="button"
        onClick={onQr}
        className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--brand-gold)] hover:bg-[var(--color-surface-secondary)] transition-colors"
        title="Manage QR Code"
      >
        <QrCode className="w-4 h-4" />
      </button>
      <button
        type="button"
        onClick={onDelete}
        className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-danger)] hover:bg-[var(--color-surface-secondary)] transition-colors"
        title="Delete"
      >
        <Trash2 className="w-4 h-4" />
      </button>
    </div>
  );
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div
        className="action-modal-backdrop absolute inset-0 bg-black/50 backdrop-blur-sm"
        onClick={onClose}
        aria-hidden="true"
      />
      <div className="action-modal-panel relative z-10 w-full max-w-md bg-[var(--color-surface)] rounded-2xl shadow-2xl border border-[var(--color-border)] p-6">
        <div className="flex items-center justify-between mb-5">
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
