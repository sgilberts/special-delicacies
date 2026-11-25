import { useState, useEffect, useCallback } from "react";
import {
  MapPin,
  Plus,
  Pencil,
  Trash2,
  ToggleLeft,
  ToggleRight,
  X,
  Loader2,
  AlertCircle,
  CheckCircle,
  RefreshCw,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import type { ApiResponse, DeliveryZone } from "@/types/api";

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatCurrency(val: string | number) {
  return `GH₵ ${parseFloat(String(val)).toFixed(2)}`;
}

// ── Form state ────────────────────────────────────────────────────────────────

interface ZoneFormState {
  name: string;
  description: string;
  delivery_fee: string;
  is_active: boolean;
}

const defaultForm: ZoneFormState = {
  name: "",
  description: "",
  delivery_fee: "0.00",
  is_active: true,
};

// ── Validation ────────────────────────────────────────────────────────────────

interface FormErrors {
  name?: string;
  delivery_fee?: string;
}

function validateForm(form: ZoneFormState): FormErrors {
  const errors: FormErrors = {};
  if (!form.name.trim()) errors.name = "Zone name is required.";
  const fee = parseFloat(form.delivery_fee);
  if (isNaN(fee) || fee < 0) errors.delivery_fee = "Delivery fee must be 0 or more.";
  return errors;
}

// ── Confirm Dialog ────────────────────────────────────────────────────────────

function ConfirmDialog({
  message,
  onConfirm,
  onCancel,
  loading,
}: {
  message: string;
  onConfirm: () => void;
  onCancel: () => void;
  loading: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div
        className="action-modal-backdrop absolute inset-0 bg-black/50 backdrop-blur-sm"
        onClick={onCancel}
      />
      <div className="action-modal-panel relative z-10 bg-[var(--color-surface)] rounded-2xl shadow-xl border border-[var(--color-border)] p-6 w-full max-w-sm">
        <div className="flex items-start gap-3 mb-5">
          <AlertCircle className="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
          <p className="text-sm text-[var(--color-text)]">{message}</p>
        </div>
        <div className="flex justify-end gap-2">
          <button
            onClick={onCancel}
            className="px-4 py-2 rounded-xl text-sm font-semibold text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)] border border-[var(--color-border)] transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={onConfirm}
            disabled={loading}
            className="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-60 transition-colors"
          >
            {loading && <Loader2 className="w-4 h-4 animate-spin" />}
            Delete
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Zone Modal (Create / Edit) ────────────────────────────────────────────────

interface ZoneModalProps {
  zone: DeliveryZone | null; // null = create mode
  onClose: () => void;
  onSaved: () => void;
}

function ZoneModal({ zone, onClose, onSaved }: ZoneModalProps) {
  const isEdit = zone !== null;
  const [form, setForm] = useState<ZoneFormState>(
    zone
      ? {
          name: zone.name,
          description: zone.description ?? "",
          delivery_fee: parseFloat(zone.delivery_fee).toFixed(2),
          is_active: zone.is_active,
        }
      : { ...defaultForm }
  );
  const [errors, setErrors] = useState<FormErrors>({});
  const [submitting, setSubmitting] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>
  ) => {
    const { name, value, type } = e.target;
    setForm((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? (e.target as HTMLInputElement).checked : value,
    }));
    setErrors((prev) => ({ ...prev, [name]: undefined }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const errs = validateForm(form);
    if (Object.keys(errs).length > 0) {
      setErrors(errs);
      return;
    }
    setSubmitting(true);
    setServerError(null);
    try {
      const payload = {
        name: form.name.trim(),
        description: form.description.trim() || null,
        delivery_fee: parseFloat(form.delivery_fee),
        is_active: form.is_active,
      };
      if (isEdit && zone) {
        await api.put(`/admin/delivery-zones/${zone.id}`, payload);
      } else {
        await api.post("/admin/delivery-zones", payload);
      }
      onSaved();
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { message?: string } } })?.response?.data
          ?.message ?? "Something went wrong. Please try again.";
      setServerError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div
        className="absolute inset-0 bg-black/50 backdrop-blur-sm"
        onClick={onClose}
      />
      <div className="relative z-10 bg-[var(--color-surface)] rounded-2xl shadow-xl border border-[var(--color-border)] w-full max-w-md">
        {/* Modal header */}
        <div className="flex items-center gap-3 px-6 py-4 border-b border-[var(--color-border)]">
          <div
            className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
            style={{ background: "#302019" }}
          >
            <MapPin className="w-4 h-4 text-[#D8AA65]" />
          </div>
          <h2 className="font-bold text-base text-[var(--color-text)] flex-1">
            {isEdit ? "Edit Delivery Zone" : "Add Delivery Zone"}
          </h2>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)] transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="px-6 py-5 space-y-4">
          {serverError && (
            <div className="flex items-start gap-2 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3">
              <AlertCircle className="w-4 h-4 text-red-500 mt-0.5 shrink-0" />
              <p className="text-sm text-red-700 dark:text-red-300">{serverError}</p>
            </div>
          )}

          {/* Zone Name */}
          <div>
            <label className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1">
              Zone Name <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              name="name"
              value={form.name}
              onChange={handleChange}
              placeholder="e.g. East Legon"
              className={`w-full px-3 py-2 rounded-xl border text-sm bg-[var(--color-surface-secondary)] text-[var(--color-text)] placeholder-[var(--color-text-secondary)] focus:outline-none focus:ring-2 focus:ring-[#BD7048] transition-colors ${
                errors.name
                  ? "border-red-400"
                  : "border-[var(--color-border)]"
              }`}
            />
            {errors.name && (
              <p className="mt-1 text-xs text-red-500">{errors.name}</p>
            )}
          </div>

          {/* Description */}
          <div>
            <label className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1">
              Description
            </label>
            <textarea
              name="description"
              value={form.description}
              onChange={handleChange}
              rows={3}
              placeholder="Optional zone description…"
              className="w-full px-3 py-2 rounded-xl border border-[var(--color-border)] text-sm bg-[var(--color-surface-secondary)] text-[var(--color-text)] placeholder-[var(--color-text-secondary)] focus:outline-none focus:ring-2 focus:ring-[#BD7048] transition-colors resize-none"
            />
          </div>

          {/* Delivery Fee */}
          <div>
            <label className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1">
              Delivery Fee (GH₵) <span className="text-red-500">*</span>
            </label>
            <div className="relative">
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-semibold text-[var(--color-text-secondary)]">
                GH₵
              </span>
              <input
                type="number"
                name="delivery_fee"
                value={form.delivery_fee}
                onChange={handleChange}
                min="0"
                step="0.01"
                placeholder="0.00"
                className={`w-full pl-12 pr-3 py-2 rounded-xl border text-sm bg-[var(--color-surface-secondary)] text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[#BD7048] transition-colors ${
                  errors.delivery_fee
                    ? "border-red-400"
                    : "border-[var(--color-border)]"
                }`}
              />
            </div>
            {errors.delivery_fee && (
              <p className="mt-1 text-xs text-red-500">{errors.delivery_fee}</p>
            )}
          </div>

          {/* Is Active toggle */}
          <div className="flex items-center gap-3">
            <label className="relative inline-flex items-center cursor-pointer">
              <input
                type="checkbox"
                name="is_active"
                checked={form.is_active}
                onChange={handleChange}
                className="sr-only peer"
              />
              <div className="w-11 h-6 bg-gray-300 dark:bg-gray-600 peer-checked:bg-[#78866C] rounded-full transition-colors peer-focus:ring-2 peer-focus:ring-[#BD7048]" />
              <div className="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5" />
            </label>
            <span className="text-sm font-semibold text-[var(--color-text)]">
              {form.is_active ? "Active" : "Inactive"}
            </span>
          </div>

          {/* Footer */}
          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-xl text-sm font-semibold text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)] border border-[var(--color-border)] transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={submitting}
              className="flex items-center gap-1.5 px-5 py-2 rounded-xl bg-[#BD7048] text-white text-sm font-semibold hover:bg-[#a55e3a] disabled:opacity-60 transition-colors"
            >
              {submitting && <Loader2 className="w-4 h-4 animate-spin" />}
              {isEdit ? "Save Changes" : "Create Zone"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function AdminDeliveryZonesPage() {
  const [zones, setZones] = useState<DeliveryZone[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Modal state
  const [modalOpen, setModalOpen] = useState(false);
  const [editingZone, setEditingZone] = useState<DeliveryZone | null>(null);

  // Confirm delete state
  const [deleteTarget, setDeleteTarget] = useState<DeliveryZone | null>(null);
  const [deleteLoading, setDeleteLoading] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  // Per-row toggle loading
  const [togglingId, setTogglingId] = useState<number | null>(null);

  const fetchZones = useCallback(async () => {
    try {
      setError(null);
      const res = await cachedGet<ApiResponse<DeliveryZone[]>>("/admin/delivery-zones", 15_000);
      setZones(res.data.data ?? []);
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { message?: string } } })?.response?.data
          ?.message ?? "Failed to load delivery zones.";
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchZones();
  }, [fetchZones]);

  const handleSaved = useCallback(() => {
    setModalOpen(false);
    setEditingZone(null);
    setLoading(true);
    fetchZones();
  }, [fetchZones]);

  const handleToggleStatus = useCallback(
    async (zone: DeliveryZone) => {
      setTogglingId(zone.id);
      try {
        await api.patch(`/admin/delivery-zones/${zone.id}/status`);
        await fetchZones();
      } catch (err: unknown) {
        console.error("Toggle failed", err);
      } finally {
        setTogglingId(null);
      }
    },
    [fetchZones]
  );

  const handleDeleteConfirm = useCallback(async () => {
    if (!deleteTarget) return;
    setDeleteLoading(true);
    setDeleteError(null);
    try {
      await api.delete(`/admin/delivery-zones/${deleteTarget.id}`);
      setDeleteTarget(null);
      setLoading(true);
      await fetchZones();
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { message?: string } } })?.response?.data
          ?.message ?? "Cannot delete this zone. It may have existing orders.";
      setDeleteError(msg);
    } finally {
      setDeleteLoading(false);
    }
  }, [deleteTarget, fetchZones]);

  const activeCount = zones.filter((z) => z.is_active).length;

  return (
    <div className="min-h-screen bg-[var(--color-background)]">
      {/* Zone modal */}
      {modalOpen && (
        <ZoneModal
          zone={editingZone}
          onClose={() => {
            setModalOpen(false);
            setEditingZone(null);
          }}
          onSaved={handleSaved}
        />
      )}

      {/* Delete confirm dialog */}
      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex flex-col">
          {deleteError && (
            <div className="fixed top-4 right-4 z-[60] rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 flex items-start gap-2 shadow-lg max-w-sm">
              <AlertCircle className="w-4 h-4 text-red-500 shrink-0 mt-0.5" />
              <p className="text-sm text-red-700 dark:text-red-300">{deleteError}</p>
              <button
                onClick={() => setDeleteError(null)}
                className="ml-auto text-red-500"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
          )}
          <ConfirmDialog
            message={`Are you sure you want to delete "${deleteTarget.name}"? This cannot be undone if orders exist for this zone.`}
            onConfirm={handleDeleteConfirm}
            onCancel={() => {
              setDeleteTarget(null);
              setDeleteError(null);
            }}
            loading={deleteLoading}
          />
        </div>
      )}

      {/* Page header */}
      <div className="mb-6 flex flex-col sm:flex-row sm:items-center gap-4">
        <div className="flex items-center gap-3">
          <div
            className="w-10 h-10 rounded-xl flex items-center justify-center shadow-sm"
            style={{ background: "#302019" }}
          >
            <MapPin className="w-5 h-5 text-[#D8AA65]" />
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-[var(--color-text)] leading-tight">
              Delivery Zones
            </h1>
            <p className="text-xs text-[var(--color-text-secondary)]">
              Manage delivery coverage areas and fees
            </p>
          </div>
        </div>
        <div className="sm:ml-auto flex items-center gap-2">
          <button
            onClick={() => {
              setLoading(true);
              fetchZones();
            }}
            disabled={loading}
            className="p-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)] disabled:opacity-60 transition-colors"
            title="Refresh"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? "animate-spin" : ""}`} />
          </button>
          <button
            onClick={() => {
              setEditingZone(null);
              setModalOpen(true);
            }}
            className="flex items-center gap-2 px-4 py-2 rounded-xl bg-[#BD7048] text-white text-sm font-semibold hover:bg-[#a55e3a] transition-colors shadow-sm"
          >
            <Plus className="w-4 h-4" />
            Add Zone
          </button>
        </div>
      </div>

      {/* Stats row */}
      {!loading && !error && (
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
          <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3">
            <p className="text-xs text-[var(--color-text-secondary)] font-semibold uppercase tracking-wider">
              Total Zones
            </p>
            <p className="text-2xl font-extrabold text-[var(--color-text)] mt-1">
              {zones.length}
            </p>
          </div>
          <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3">
            <p className="text-xs text-[var(--color-text-secondary)] font-semibold uppercase tracking-wider">
              Active
            </p>
            <p className="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-1">
              {activeCount}
            </p>
          </div>
          <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3">
            <p className="text-xs text-[var(--color-text-secondary)] font-semibold uppercase tracking-wider">
              Inactive
            </p>
            <p className="text-2xl font-extrabold text-[var(--color-text-secondary)] mt-1">
              {zones.length - activeCount}
            </p>
          </div>
        </div>
      )}

      {/* Error state */}
      {error && (
        <div className="rounded-2xl border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4 flex items-center gap-3 mb-6">
          <AlertCircle className="w-5 h-5 text-red-600 dark:text-red-400 shrink-0" />
          <p className="text-sm text-red-700 dark:text-red-300 flex-1">{error}</p>
          <button
            onClick={fetchZones}
            className="text-sm font-semibold text-red-700 dark:text-red-300 underline"
          >
            Retry
          </button>
        </div>
      )}

      {/* Loading skeleton */}
      {loading && (
        <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] overflow-hidden animate-pulse">
          {Array.from({ length: 4 }).map((_, i) => (
            <div
              key={i}
              className="flex items-center gap-4 px-4 py-4 border-b border-[var(--color-border)] last:border-0"
            >
              <div className="h-4 bg-[var(--color-surface-secondary)] rounded flex-1" />
              <div className="h-4 bg-[var(--color-surface-secondary)] rounded w-20" />
              <div className="h-4 bg-[var(--color-surface-secondary)] rounded w-16" />
              <div className="h-6 bg-[var(--color-surface-secondary)] rounded w-20" />
            </div>
          ))}
        </div>
      )}

      {/* Table */}
      {!loading && !error && (
        <>
          {zones.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-12 text-center">
              <MapPin className="w-10 h-10 text-[var(--color-text-secondary)] mx-auto mb-3 opacity-50" />
              <p className="text-sm font-semibold text-[var(--color-text-secondary)]">
                No delivery zones yet
              </p>
              <p className="text-xs text-[var(--color-text-secondary)] mt-1 mb-4">
                Add your first zone to start taking delivery orders.
              </p>
              <button
                onClick={() => {
                  setEditingZone(null);
                  setModalOpen(true);
                }}
                className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#BD7048] text-white text-sm font-semibold hover:bg-[#a55e3a] transition-colors"
              >
                <Plus className="w-4 h-4" />
                Add First Zone
              </button>
            </div>
          ) : (
            /* Desktop table / Mobile cards */
            <>
              {/* Desktop table */}
              <div className="hidden md:block rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] overflow-hidden">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-[var(--color-border)] bg-[var(--color-surface-secondary)]">
                      <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
                        Zone Name
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
                        Description
                      </th>
                      <th className="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
                        Delivery Fee
                      </th>
                      <th className="px-4 py-3 text-center text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
                        Status
                      </th>
                      <th className="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
                        Actions
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[var(--color-border)]">
                    {zones.map((zone) => (
                      <tr
                        key={zone.id}
                        className="hover:bg-[var(--color-surface-secondary)]/50 transition-colors"
                      >
                        <td className="px-4 py-4">
                          <span className="font-semibold text-[var(--color-text)]">
                            {zone.name}
                          </span>
                        </td>
                        <td className="px-4 py-4 text-[var(--color-text-secondary)] max-w-xs">
                          <span className="line-clamp-2">
                            {zone.description || (
                              <span className="italic opacity-50">No description</span>
                            )}
                          </span>
                        </td>
                        <td className="px-4 py-4 text-right font-mono font-bold text-[var(--color-text)]">
                          {formatCurrency(zone.delivery_fee)}
                        </td>
                        <td className="px-4 py-4 text-center">
                          {zone.is_active ? (
                            <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                              <CheckCircle className="w-3 h-3" /> Active
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                              Inactive
                            </span>
                          )}
                        </td>
                        <td className="px-4 py-4">
                          <div className="flex items-center justify-end gap-1">
                            {/* Edit */}
                            <button
                              onClick={() => {
                                setEditingZone(zone);
                                setModalOpen(true);
                              }}
                              className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[#BD7048] hover:bg-[var(--color-surface-secondary)] transition-colors"
                              title="Edit"
                            >
                              <Pencil className="w-4 h-4" />
                            </button>
                            {/* Toggle status */}
                            <button
                              onClick={() => handleToggleStatus(zone)}
                              disabled={togglingId === zone.id}
                              className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[#78866C] hover:bg-[var(--color-surface-secondary)] disabled:opacity-50 transition-colors"
                              title={zone.is_active ? "Deactivate" : "Activate"}
                            >
                              {togglingId === zone.id ? (
                                <Loader2 className="w-4 h-4 animate-spin" />
                              ) : zone.is_active ? (
                                <ToggleRight className="w-4 h-4 text-emerald-500" />
                              ) : (
                                <ToggleLeft className="w-4 h-4" />
                              )}
                            </button>
                            {/* Delete */}
                            <button
                              onClick={() => setDeleteTarget(zone)}
                              className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-red-600 hover:bg-red-50/50 dark:hover:bg-red-900/20 transition-colors"
                              title="Delete"
                            >
                              <Trash2 className="w-4 h-4" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Mobile cards */}
              <div className="md:hidden space-y-3">
                {zones.map((zone) => (
                  <div
                    key={zone.id}
                    className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 space-y-3"
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <p className="font-bold text-[var(--color-text)]">{zone.name}</p>
                        {zone.description && (
                          <p className="text-xs text-[var(--color-text-secondary)] mt-0.5 line-clamp-2">
                            {zone.description}
                          </p>
                        )}
                      </div>
                      {zone.is_active ? (
                        <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300 shrink-0">
                          Active
                        </span>
                      ) : (
                        <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 shrink-0">
                          Inactive
                        </span>
                      )}
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="font-mono font-bold text-[var(--color-text)]">
                        {formatCurrency(zone.delivery_fee)}
                      </span>
                      <div className="flex items-center gap-1">
                        <button
                          onClick={() => {
                            setEditingZone(zone);
                            setModalOpen(true);
                          }}
                          className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[#BD7048] hover:bg-[var(--color-surface-secondary)] transition-colors"
                        >
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => handleToggleStatus(zone)}
                          disabled={togglingId === zone.id}
                          className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[#78866C] hover:bg-[var(--color-surface-secondary)] disabled:opacity-50 transition-colors"
                        >
                          {togglingId === zone.id ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                          ) : zone.is_active ? (
                            <ToggleRight className="w-4 h-4 text-emerald-500" />
                          ) : (
                            <ToggleLeft className="w-4 h-4" />
                          )}
                        </button>
                        <button
                          onClick={() => setDeleteTarget(zone)}
                          className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-red-600 hover:bg-red-50/50 dark:hover:bg-red-900/20 transition-colors"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </>
          )}
        </>
      )}
    </div>
  );
}
