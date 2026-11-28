import { useCallback, useEffect, useState } from "react";
import {
  Building2,
  Plus,
  Pencil,
  Trash2,
  Settings2,
  Loader2,
  AlertTriangle,
  X,
  RefreshCw,
  Clock,
  CalendarDays,
  Wrench,
  Users,
} from "lucide-react";
import api from "@/lib/api";
import { useToast } from "@/components/ui/useToast";
import { useAuth } from "@/app/providers/useAuth";
import { hasAnyPermission } from "@/lib/permissionAccess";
import type { ApiResponse, PaginationMeta, Venue, VenueClosure, VenueOperatingHour, VenueSpecialHour } from "@/types/api";

const VENUE_TYPES = [
  { value: "dining_area", label: "Dining area" },
  { value: "private_room", label: "Private room" },
  { value: "terrace", label: "Terrace" },
  { value: "event_space", label: "Event space" },
  { value: "other", label: "Other" },
];

const VENUE_STATUSES = [
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
  { value: "maintenance", label: "Maintenance" },
];

const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

interface VenueFormState {
  name: string;
  code: string;
  description: string;
  venue_type: string;
  location: string;
  max_capacity: string;
  operational_status: string;
  reservation_enabled: boolean;
  exclusive_reservation_enabled: boolean;
  default_reservation_duration_minutes: string;
  reservation_slot_minutes: string;
  internal_notes: string;
}

const emptyForm: VenueFormState = {
  name: "",
  code: "",
  description: "",
  venue_type: "dining_area",
  location: "",
  max_capacity: "",
  operational_status: "active",
  reservation_enabled: true,
  exclusive_reservation_enabled: false,
  default_reservation_duration_minutes: "120",
  reservation_slot_minutes: "30",
  internal_notes: "",
};

function toForm(venue: Venue): VenueFormState {
  return {
    name: venue.name,
    code: venue.code,
    description: venue.description ?? "",
    venue_type: venue.venue_type,
    location: venue.location ?? "",
    max_capacity: venue.max_capacity == null ? "" : String(venue.max_capacity),
    operational_status: venue.operational_status,
    reservation_enabled: venue.reservation_enabled,
    exclusive_reservation_enabled: venue.exclusive_reservation_enabled,
    default_reservation_duration_minutes: String(venue.default_reservation_duration_minutes),
    reservation_slot_minutes: String(venue.reservation_slot_minutes),
    internal_notes: venue.internal_notes ?? "",
  };
}

function toPayload(form: VenueFormState) {
  return {
    name: form.name.trim(),
    code: form.code.trim().toUpperCase(),
    description: form.description.trim() || null,
    venue_type: form.venue_type,
    location: form.location.trim() || null,
    max_capacity: form.max_capacity === "" ? null : Number(form.max_capacity),
    operational_status: form.operational_status,
    reservation_enabled: form.reservation_enabled,
    exclusive_reservation_enabled: form.exclusive_reservation_enabled,
    default_reservation_duration_minutes: Number(form.default_reservation_duration_minutes),
    reservation_slot_minutes: Number(form.reservation_slot_minutes),
    internal_notes: form.internal_notes.trim() || null,
  };
}

function formatDateTime(value?: string | null) {
  if (!value) return "—";
  return new Date(value).toLocaleString("en-GB", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export default function AdminVenuesPage() {
  const { success, error: toastError } = useToast();
  const { user } = useAuth();
  const canManage = hasAnyPermission(user?.permissions, ["venues.create", "venues.update", "venues.delete"], user?.roles);
  const canConfigure = hasAnyPermission(user?.permissions, ["venues.configuration.manage"], user?.roles);

  const [venues, setVenues] = useState<Venue[]>([]);
  const [meta, setMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [page, setPage] = useState(1);

  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<Venue | null>(null);
  const [form, setForm] = useState<VenueFormState>(emptyForm);
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState<Venue | null>(null);
  const [deletingBusy, setDeletingBusy] = useState(false);

  const [configVenue, setConfigVenue] = useState<Venue | null>(null);
  const [configTab, setConfigTab] = useState<"hours" | "special" | "closures">("hours");
  const [configLoading, setConfigLoading] = useState(false);
  const [configBusy, setConfigBusy] = useState(false);
  const [hours, setHours] = useState<VenueOperatingHour[]>([]);
  const [specialForm, setSpecialForm] = useState({ date: "", opens_at: "11:00", closes_at: "22:00", is_closed: false, reason: "" });
  const [closureForm, setClosureForm] = useState({ closure_type: "closure", starts_at: "", ends_at: "", reason: "" });

  const fetchVenues = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string | number> = { page };
      if (search) params.search = search;
      if (statusFilter !== "all") params.operational_status = statusFilter;
      const resp = await api.get<{ success: boolean; data: Venue[]; meta?: PaginationMeta }>("/admin/venues", { params });
      if (resp.data?.success) {
        setVenues(Array.isArray(resp.data.data) ? resp.data.data : []);
        if (resp.data.meta) setMeta(resp.data.meta);
      } else {
        setError("Failed to load venues.");
      }
    } catch {
      setError("Unable to connect to the venues service.");
    } finally {
      setLoading(false);
    }
  }, [page, search, statusFilter]);

  useEffect(() => {
    document.title = "Venues | Special Delicacies Admin";
    fetchVenues();
  }, [fetchVenues]);

  useEffect(() => {
    setPage(1);
  }, [search, statusFilter]);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setFormError(null);
    setShowForm(true);
  };

  const openEdit = (venue: Venue) => {
    setEditing(venue);
    setForm(toForm(venue));
    setFormError(null);
    setShowForm(true);
  };

  const submitForm = async (event: React.FormEvent) => {
    event.preventDefault();
    setFormError(null);
    setSaving(true);
    try {
      if (editing) {
        await api.put(`/admin/venues/${editing.id}`, toPayload(form));
        success("Venue updated successfully.");
      } else {
        await api.post("/admin/venues", toPayload(form));
        success("Venue created successfully.");
      }
      setShowForm(false);
      fetchVenues();
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
      const firstError = e?.response?.data?.errors ? Object.values(e.response.data.errors)[0]?.[0] : undefined;
      setFormError(firstError || e?.response?.data?.message || "Failed to save venue.");
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!deleting) return;
    setDeletingBusy(true);
    try {
      await api.delete(`/admin/venues/${deleting.id}`);
      success(`Venue ${deleting.name} deleted.`);
      setDeleting(null);
      fetchVenues();
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to delete venue.");
    } finally {
      setDeletingBusy(false);
    }
  };

  const openConfig = async (venue: Venue) => {
    setConfigVenue(venue);
    setConfigTab("hours");
    setConfigLoading(true);
    try {
      const resp = await api.get<ApiResponse<Venue>>(`/admin/venues/${venue.id}`);
      const detail = resp.data?.data;
      if (detail) {
        setConfigVenue(detail);
        const existing = detail.operating_hours ?? [];
        setHours(
          DAYS.map((_, day) => {
            const row = existing.find((hour) => hour.day_of_week === day);
            return {
              day_of_week: day,
              opens_at: row?.opens_at?.slice(0, 5) ?? "11:00",
              closes_at: row?.closes_at?.slice(0, 5) ?? "22:00",
              is_closed: row?.is_closed ?? false,
            };
          })
        );
      }
    } catch {
      toastError("Unable to load venue configuration.");
    } finally {
      setConfigLoading(false);
    }
  };

  const refreshConfig = async (venueId: number) => {
    const resp = await api.get<ApiResponse<Venue>>(`/admin/venues/${venueId}`);
    if (resp.data?.data) setConfigVenue(resp.data.data);
  };

  const saveHours = async () => {
    if (!configVenue) return;
    setConfigBusy(true);
    try {
      await api.put(`/admin/venues/${configVenue.id}/operating-hours`, { hours });
      await refreshConfig(configVenue.id);
      success("Operating hours saved.");
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to save operating hours.");
    } finally {
      setConfigBusy(false);
    }
  };

  const addSpecialHour = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!configVenue) return;
    setConfigBusy(true);
    try {
      await api.post(`/admin/venues/${configVenue.id}/special-hours`, {
        date: specialForm.date,
        opens_at: specialForm.is_closed ? null : specialForm.opens_at,
        closes_at: specialForm.is_closed ? null : specialForm.closes_at,
        is_closed: specialForm.is_closed,
        reason: specialForm.reason || null,
      });
      setSpecialForm({ date: "", opens_at: "11:00", closes_at: "22:00", is_closed: false, reason: "" });
      await refreshConfig(configVenue.id);
      success("Special hours saved.");
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
      const firstError = e?.response?.data?.errors ? Object.values(e.response.data.errors)[0]?.[0] : undefined;
      toastError(firstError || e?.response?.data?.message || "Failed to save special hours.");
    } finally {
      setConfigBusy(false);
    }
  };

  const removeSpecialHour = async (specialHour: VenueSpecialHour) => {
    if (!configVenue) return;
    setConfigBusy(true);
    try {
      await api.delete(`/admin/venues/${configVenue.id}/special-hours/${specialHour.id}`);
      await refreshConfig(configVenue.id);
      success("Special hours removed.");
    } catch {
      toastError("Failed to remove special hours.");
    } finally {
      setConfigBusy(false);
    }
  };

  const addClosure = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!configVenue) return;
    setConfigBusy(true);
    try {
      await api.post(`/admin/venues/${configVenue.id}/closures`, {
        closure_type: closureForm.closure_type,
        starts_at: closureForm.starts_at,
        ends_at: closureForm.ends_at || null,
        reason: closureForm.reason || null,
      });
      setClosureForm({ closure_type: "closure", starts_at: "", ends_at: "", reason: "" });
      await refreshConfig(configVenue.id);
      success("Closure saved.");
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
      const firstError = e?.response?.data?.errors ? Object.values(e.response.data.errors)[0]?.[0] : undefined;
      toastError(firstError || e?.response?.data?.message || "Failed to save closure.");
    } finally {
      setConfigBusy(false);
    }
  };

  const removeClosure = async (closure: VenueClosure) => {
    if (!configVenue) return;
    setConfigBusy(true);
    try {
      await api.delete(`/admin/venues/${configVenue.id}/closures/${closure.id}`);
      await refreshConfig(configVenue.id);
      success("Closure removed.");
    } catch {
      toastError("Failed to remove closure.");
    } finally {
      setConfigBusy(false);
    }
  };

  const inputClass = "w-full px-3 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]/40";

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-extrabold flex items-center gap-2 text-[var(--color-text)]">
            <Building2 className="w-7 h-7 text-[var(--color-accent)]" />
            Venues &amp; Booking Configuration
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-0.5">
            Manage reservable venues, operating hours, closures and capacity settings
          </p>
        </div>
        {canManage && (
          <button
            type="button"
            onClick={openCreate}
            className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition-all hover:opacity-90 active:scale-95"
            style={{ background: "var(--color-accent)" }}
          >
            <Plus className="w-4 h-4" /> Add Venue
          </button>
        )}
      </div>

      <div className="flex flex-col sm:flex-row gap-3">
        <input
          type="text"
          placeholder="Search venues by name or code…"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          className={inputClass}
        />
        <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className={`${inputClass} sm:max-w-[220px]`}>
          <option value="all">All statuses</option>
          {VENUE_STATUSES.map((status) => (
            <option key={status.value} value={status.value}>{status.label}</option>
          ))}
        </select>
        <button
          type="button"
          onClick={fetchVenues}
          className="p-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
          title="Refresh"
        >
          <RefreshCw className="w-4 h-4" />
        </button>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-16"><Loader2 className="w-6 h-6 animate-spin text-[var(--color-accent)]" /></div>
      ) : error ? (
        <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6 text-sm text-[var(--color-text-secondary)]">
          <AlertTriangle className="w-5 h-5 text-[var(--color-danger)] mb-2" />
          {error}
          <button type="button" onClick={fetchVenues} className="ml-3 font-bold text-[var(--color-accent)]">Retry</button>
        </div>
      ) : venues.length === 0 ? (
        <div className="text-center py-16 border border-dashed border-[var(--color-border)] rounded-xl bg-[var(--color-surface)]">
          <Building2 className="w-12 h-12 mx-auto mb-3 text-[var(--color-text-secondary)]" />
          <p className="text-lg font-bold text-[var(--color-text)]">No venues configured</p>
          <p className="text-sm text-[var(--color-text-secondary)]">Add a venue to start configuring reservable spaces.</p>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {venues.map((venue) => (
            <div key={venue.id} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 flex flex-col gap-3">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="font-bold text-[var(--color-text)]">{venue.name}</p>
                  <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{venue.code}</p>
                </div>
                <span className="rounded-full px-2 py-1 text-[11px] font-bold" style={{ background: "var(--color-surface-secondary)", color: venue.operational_status === "active" ? "var(--color-success)" : "var(--color-danger)" }}>
                  {venue.operational_status}
                </span>
              </div>
              <div className="text-xs text-[var(--color-text-secondary)] space-y-1">
                <p>{VENUE_TYPES.find((type) => type.value === venue.venue_type)?.label ?? venue.venue_type}{venue.location ? ` · ${venue.location}` : ""}</p>
                <p className="flex items-center gap-1.5"><Users className="w-3.5 h-3.5" /> {venue.tables_count ?? 0} tables{venue.max_capacity ? ` · max ${venue.max_capacity} guests` : ""}</p>
                <p>Reservations {venue.reservation_enabled ? "enabled" : "disabled"}{venue.exclusive_reservation_enabled ? " · exclusive venue available" : ""}</p>
              </div>
              <div className="flex items-center gap-2 pt-2 border-t border-[var(--color-border)]">
                {canConfigure && (
                  <button type="button" onClick={() => openConfig(venue)} className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)]">
                    <Settings2 className="w-3.5 h-3.5" /> Configure
                  </button>
                )}
                {canManage && (
                  <button type="button" onClick={() => openEdit(venue)} className="p-1.5 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-accent)]" title="Edit venue">
                    <Pencil className="w-4 h-4" />
                  </button>
                )}
                {canManage && (
                  <button type="button" onClick={() => setDeleting(venue)} className="p-1.5 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-danger)]" title="Delete venue">
                    <Trash2 className="w-4 h-4" />
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {meta.last_page > 1 && (
        <div className="flex items-center justify-between gap-2">
          <span className="text-xs text-[var(--color-text-secondary)]">Page {meta.current_page} of {meta.last_page} ({meta.total} total)</span>
          <div className="flex gap-2">
            <button type="button" onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={page <= 1} className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-sm font-semibold disabled:opacity-40">Previous</button>
            <button type="button" onClick={() => setPage((current) => Math.min(meta.last_page, current + 1))} disabled={page >= meta.last_page} className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-sm font-semibold disabled:opacity-40">Next</button>
          </div>
        </div>
      )}

      {showForm && (
        <Modal title={editing ? `Edit Venue ${editing.name}` : "Add Venue"} onClose={() => setShowForm(false)}>
          <form onSubmit={submitForm} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Venue name" required>
                <input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} className={inputClass} placeholder="e.g. Main Dining Room" />
              </Field>
              <Field label="Code" required>
                <input required value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} className={inputClass} placeholder="e.g. MAIN" />
              </Field>
              <Field label="Venue type">
                <select value={form.venue_type} onChange={(event) => setForm({ ...form, venue_type: event.target.value })} className={inputClass}>
                  {VENUE_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                </select>
              </Field>
              <Field label="Operational status">
                <select value={form.operational_status} onChange={(event) => setForm({ ...form, operational_status: event.target.value })} className={inputClass}>
                  {VENUE_STATUSES.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                </select>
              </Field>
              <Field label="Location / area">
                <input value={form.location} onChange={(event) => setForm({ ...form, location: event.target.value })} className={inputClass} placeholder="Ground floor" />
              </Field>
              <Field label="Maximum capacity">
                <input type="number" min={1} value={form.max_capacity} onChange={(event) => setForm({ ...form, max_capacity: event.target.value })} className={inputClass} placeholder="e.g. 60" />
              </Field>
              <Field label="Default reservation duration (minutes)">
                <input type="number" min={15} max={720} value={form.default_reservation_duration_minutes} onChange={(event) => setForm({ ...form, default_reservation_duration_minutes: event.target.value })} className={inputClass} />
              </Field>
              <Field label="Reservation slot size (minutes)">
                <input type="number" min={5} max={240} value={form.reservation_slot_minutes} onChange={(event) => setForm({ ...form, reservation_slot_minutes: event.target.value })} className={inputClass} />
              </Field>
            </div>
            <Field label="Description">
              <textarea rows={2} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} className={inputClass} />
            </Field>
            <div className="flex flex-col gap-2">
              <label className="flex items-center gap-3 text-sm font-semibold text-[var(--color-text)]">
                <input type="checkbox" checked={form.reservation_enabled} onChange={(event) => setForm({ ...form, reservation_enabled: event.target.checked })} className="w-4 h-4 rounded" />
                Reservations enabled for this venue
              </label>
              <label className="flex items-center gap-3 text-sm font-semibold text-[var(--color-text)]">
                <input type="checkbox" checked={form.exclusive_reservation_enabled} onChange={(event) => setForm({ ...form, exclusive_reservation_enabled: event.target.checked })} className="w-4 h-4 rounded" />
                Allow exclusive venue reservations
              </label>
            </div>
            <Field label="Internal notes">
              <textarea rows={2} value={form.internal_notes} onChange={(event) => setForm({ ...form, internal_notes: event.target.value })} className={inputClass} />
            </Field>
            {formError && <p className="text-sm text-[var(--color-danger)]">{formError}</p>}
            <div className="flex gap-3 pt-2">
              <button type="submit" disabled={saving} className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: "var(--color-accent)" }}>
                {saving && <Loader2 className="w-4 h-4 animate-spin" />}
                {editing ? "Save Changes" : "Create Venue"}
              </button>
              <button type="button" onClick={() => setShowForm(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold border border-[var(--color-border)] text-[var(--color-text-secondary)]">Cancel</button>
            </div>
          </form>
        </Modal>
      )}

      {configVenue && (
        <Modal title={`Configure ${configVenue.name}`} onClose={() => setConfigVenue(null)} wide>
          <div className="flex flex-wrap gap-2 mb-4">
            {[
              { id: "hours", label: "Operating hours", icon: Clock },
              { id: "special", label: "Special hours", icon: CalendarDays },
              { id: "closures", label: "Closures & maintenance", icon: Wrench },
            ].map((tab) => {
              const Icon = tab.icon;
              const active = configTab === tab.id;
              return (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => setConfigTab(tab.id as typeof configTab)}
                  className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold border"
                  style={{
                    borderColor: active ? "var(--color-accent)" : "var(--color-border)",
                    color: active ? "var(--color-accent)" : "var(--color-text-secondary)",
                    background: active ? "var(--color-surface-secondary)" : "transparent",
                  }}
                >
                  <Icon className="w-3.5 h-3.5" /> {tab.label}
                </button>
              );
            })}
          </div>

          {configLoading ? (
            <div className="flex items-center justify-center py-10"><Loader2 className="w-5 h-5 animate-spin text-[var(--color-accent)]" /></div>
          ) : configTab === "hours" ? (
            <div className="space-y-3">
              <p className="text-xs text-[var(--color-text-secondary)]">Times use the application timezone (Africa/Accra).</p>
              {hours.map((hour) => (
                <div key={hour.day_of_week} className="grid grid-cols-2 sm:grid-cols-4 gap-2 items-center">
                  <span className="text-sm font-semibold text-[var(--color-text)]">{DAYS[hour.day_of_week]}</span>
                  <input type="time" value={hour.opens_at ?? ""} disabled={hour.is_closed} onChange={(event) => setHours((current) => current.map((row) => row.day_of_week === hour.day_of_week ? { ...row, opens_at: event.target.value } : row))} className={inputClass} />
                  <input type="time" value={hour.closes_at ?? ""} disabled={hour.is_closed} onChange={(event) => setHours((current) => current.map((row) => row.day_of_week === hour.day_of_week ? { ...row, closes_at: event.target.value } : row))} className={inputClass} />
                  <label className="flex items-center gap-2 text-xs font-semibold text-[var(--color-text-secondary)]">
                    <input type="checkbox" checked={hour.is_closed} onChange={(event) => setHours((current) => current.map((row) => row.day_of_week === hour.day_of_week ? { ...row, is_closed: event.target.checked } : row))} className="w-4 h-4 rounded" />
                    Closed
                  </label>
                </div>
              ))}
              <button type="button" onClick={saveHours} disabled={configBusy} className="mt-2 flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: "var(--color-accent)" }}>
                {configBusy && <Loader2 className="w-4 h-4 animate-spin" />} Save operating hours
              </button>
            </div>
          ) : configTab === "special" ? (
            <div className="space-y-4">
              <form onSubmit={addSpecialHour} className="grid gap-2 sm:grid-cols-5 items-end">
                <Field label="Date"><input type="date" required value={specialForm.date} onChange={(event) => setSpecialForm({ ...specialForm, date: event.target.value })} className={inputClass} /></Field>
                <Field label="Opens"><input type="time" value={specialForm.opens_at} disabled={specialForm.is_closed} onChange={(event) => setSpecialForm({ ...specialForm, opens_at: event.target.value })} className={inputClass} /></Field>
                <Field label="Closes"><input type="time" value={specialForm.closes_at} disabled={specialForm.is_closed} onChange={(event) => setSpecialForm({ ...specialForm, closes_at: event.target.value })} className={inputClass} /></Field>
                <Field label="Reason"><input value={specialForm.reason} onChange={(event) => setSpecialForm({ ...specialForm, reason: event.target.value })} className={inputClass} placeholder="Optional" /></Field>
                <div className="flex items-center gap-2">
                  <label className="flex items-center gap-2 text-xs font-semibold text-[var(--color-text-secondary)]">
                    <input type="checkbox" checked={specialForm.is_closed} onChange={(event) => setSpecialForm({ ...specialForm, is_closed: event.target.checked })} className="w-4 h-4 rounded" /> Closed
                  </label>
                  <button type="submit" disabled={configBusy} className="px-3 py-2 rounded-lg text-xs font-bold text-white disabled:opacity-60" style={{ background: "var(--color-accent)" }}>Add</button>
                </div>
              </form>
              <div className="space-y-2">
                {(configVenue.special_hours ?? []).length === 0 && <p className="text-sm text-[var(--color-text-secondary)]">No special hours configured.</p>}
                {(configVenue.special_hours ?? []).map((row) => (
                  <div key={row.id} className="flex items-center justify-between gap-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm">
                    <span className="text-[var(--color-text)]">{new Date(row.date).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" })}{row.is_closed ? " · Closed" : ` · ${row.opens_at?.slice(0, 5)}–${row.closes_at?.slice(0, 5)}`}{row.reason ? ` · ${row.reason}` : ""}</span>
                    <button type="button" onClick={() => removeSpecialHour(row)} className="text-[var(--color-text-secondary)] hover:text-[var(--color-danger)]" title="Remove"><X className="w-4 h-4" /></button>
                  </div>
                ))}
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <form onSubmit={addClosure} className="grid gap-2 sm:grid-cols-5 items-end">
                <Field label="Type">
                  <select value={closureForm.closure_type} onChange={(event) => setClosureForm({ ...closureForm, closure_type: event.target.value })} className={inputClass}>
                    <option value="closure">Closure</option>
                    <option value="maintenance">Maintenance</option>
                  </select>
                </Field>
                <Field label="Starts"><input type="datetime-local" required value={closureForm.starts_at} onChange={(event) => setClosureForm({ ...closureForm, starts_at: event.target.value })} className={inputClass} /></Field>
                <Field label="Ends"><input type="datetime-local" value={closureForm.ends_at} onChange={(event) => setClosureForm({ ...closureForm, ends_at: event.target.value })} className={inputClass} /></Field>
                <Field label="Reason"><input value={closureForm.reason} onChange={(event) => setClosureForm({ ...closureForm, reason: event.target.value })} className={inputClass} placeholder="Optional" /></Field>
                <button type="submit" disabled={configBusy} className="px-3 py-2.5 rounded-lg text-xs font-bold text-white disabled:opacity-60" style={{ background: "var(--color-accent)" }}>Add</button>
              </form>
              <div className="space-y-2">
                {(configVenue.closures ?? []).length === 0 && <p className="text-sm text-[var(--color-text-secondary)]">No closures or maintenance periods configured.</p>}
                {(configVenue.closures ?? []).map((row) => (
                  <div key={row.id} className="flex items-center justify-between gap-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm">
                    <span className="text-[var(--color-text)] capitalize">{row.closure_type} · {formatDateTime(row.starts_at)} → {formatDateTime(row.ends_at)}{row.reason ? ` · ${row.reason}` : ""}</span>
                    <button type="button" onClick={() => removeClosure(row)} className="text-[var(--color-text-secondary)] hover:text-[var(--color-danger)]" title="Remove"><X className="w-4 h-4" /></button>
                  </div>
                ))}
              </div>
            </div>
          )}
        </Modal>
      )}

      {deleting && (
        <Modal title="Delete Venue" onClose={() => setDeleting(null)}>
          <div className="space-y-4">
            <div className="flex items-start gap-3 p-3 rounded-xl border border-[var(--color-border)]">
              <AlertTriangle className="w-5 h-5 text-[var(--color-danger)] flex-shrink-0 mt-0.5" />
              <p className="text-sm text-[var(--color-text)]">Delete venue "{deleting.name}"? Venues with tables or reservations cannot be deleted — deactivate them instead.</p>
            </div>
            <div className="flex gap-3">
              <button type="button" onClick={handleDelete} disabled={deletingBusy} className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white bg-[var(--color-danger)] disabled:opacity-60">
                {deletingBusy && <Loader2 className="w-4 h-4 animate-spin" />} Delete
              </button>
              <button type="button" onClick={() => setDeleting(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold border border-[var(--color-border)] text-[var(--color-text-secondary)]">Cancel</button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}

function Field({ label, required, children }: { label: string; required?: boolean; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="block text-xs font-semibold mb-1 text-[var(--color-text-secondary)]">{label}{required && <span className="text-[var(--color-danger)]"> *</span>}</span>
      {children}
    </label>
  );
}

function Modal({ title, onClose, children, wide }: { title: string; onClose: () => void; children: React.ReactNode; wide?: boolean }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
      <div className={`relative z-10 w-full ${wide ? "max-w-3xl" : "max-w-2xl"} max-h-[90vh] overflow-y-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6 shadow-xl`}>
        <div className="flex items-center justify-between gap-3 mb-4">
          <h2 className="text-lg font-bold text-[var(--color-text)]">{title}</h2>
          <button type="button" onClick={onClose} className="p-1.5 rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]" aria-label="Close">
            <X className="w-4 h-4" />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
