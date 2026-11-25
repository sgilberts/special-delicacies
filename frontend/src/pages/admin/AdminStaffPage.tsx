import { useState, useEffect, useCallback } from "react";
import {
  Users,
  UserPlus,
  Search,
  CheckCircle,
  XCircle,
  Shield,
  KeyRound,
  Edit2,
  RefreshCw,
  AlertCircle,
  X,
  Eye,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import type { StaffUser, RoleItem, PaginatedResponse, ApiResponse } from "@/types/api";
import PasswordInput from "@/components/ui/PasswordInput";
import { hasAnyPermission } from "@/lib/permissionAccess";

export default function AdminStaffPage() {
  const { user: currentAuthUser, refreshUser } = useAuth();
  const canManageStaff = hasAnyPermission(currentAuthUser?.permissions, ["staff.manage"]);

  const [staff, setStaff] = useState<StaffUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [availableRoles, setAvailableRoles] = useState<RoleItem[]>([]);
  const [rolesLoading, setRolesLoading] = useState(false);
  const [rolesError, setRolesError] = useState<string | null>(null);

  // Filters
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  // Modals state
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [editUser, setEditUser] = useState<StaffUser | null>(null);
  const [resetPasswordUser, setResetPasswordUser] = useState<StaffUser | null>(null);
  const [viewPermissionsUser, setViewPermissionsUser] = useState<StaffUser | null>(null);
  const [statusConfirmUser, setStatusConfirmUser] = useState<StaffUser | null>(null);

  // Form states
  const [formData, setFormData] = useState({
    name: "",
    email: "",
    phone: "",
    role: "",
    password: "",
    password_confirmation: "",
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [formSubmitting, setFormSubmitting] = useState(false);

  useEffect(() => {
    document.title = "Staff Management | Special Delicacies Admin";
  }, []);

  const fetchAssignableRoles = useCallback(async (): Promise<RoleItem[]> => {
    setRolesLoading(true);
    setRolesError(null);
    try {
      const response = await api.get<ApiResponse<RoleItem[]>>("/admin/staff/roles");
      setAvailableRoles(response.data.data);
      return response.data.data;
    } catch (cause) {
      setRolesError((cause as { response?: { data?: { message?: string } } })?.response?.data?.message || "Available staff roles could not be loaded.");
      return [];
    } finally {
      setRolesLoading(false);
    }
  }, []);

  const fetchStaff = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (search) params.append("search", search);
      if (roleFilter) params.append("role", roleFilter);
      if (statusFilter !== "") params.append("is_active", statusFilter);
      params.append("page", page.toString());
      params.append("per_page", "15");

      const response = await cachedGet<PaginatedResponse<StaffUser>>(`/admin/staff?${params.toString()}`, 10_000);
      if (response.data.success) {
        setStaff(response.data.data);
        setTotalPages(response.data.meta.last_page);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to load staff accounts.";
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, [search, roleFilter, statusFilter, page]);

  useEffect(() => {
    fetchStaff();
  }, [fetchStaff]);

  // Open Create Modal
  const handleOpenCreate = async () => {
    const roles = await fetchAssignableRoles();
    setFormData({
      name: "",
      email: "",
      phone: "",
      role: roles[0]?.slug || "",
      password: "",
      password_confirmation: "",
    });
    setFormErrors({});
    setCreateModalOpen(true);
  };

  // Submit Create
  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormErrors({});

    if (formData.password !== formData.password_confirmation) {
      setFormErrors({ password_confirmation: "Passwords do not match." });
      return;
    }

    setFormSubmitting(true);
    try {
      const payload = {
        name: formData.name,
        email: formData.email,
        phone: formData.phone || null,
        role: formData.role,
        password: formData.password,
      };

      const res = await api.post<ApiResponse<StaffUser>>("/admin/staff", payload);
      if (res.data.success) {
        setSuccessMessage(`Staff account ${res.data.data.name} created successfully.`);
        setCreateModalOpen(false);
        fetchStaff();
      }
    } catch (err: any) {
      if (err.response?.status === 422 && err.response?.data?.errors) {
        const errors: Record<string, string> = {};
        Object.entries(err.response.data.errors).forEach(([k, v]) => {
          errors[k] = Array.isArray(v) ? (v[0] as string) : String(v);
        });
        setFormErrors(errors);
      } else {
        setError(err.response?.data?.message || "Failed to create staff account.");
      }
    } finally {
      setFormSubmitting(false);
    }
  };

  // Open Edit Modal
  const handleOpenEdit = async (user: StaffUser) => {
    const roles = await fetchAssignableRoles();
    setEditUser(user);
    setFormData({
      name: user.name,
      email: user.email,
      phone: user.phone || "",
      role: user.roles?.[0]?.slug || roles[0]?.slug || "",
      password: "",
      password_confirmation: "",
    });
    setFormErrors({});
  };

  // Submit Edit
  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editUser) return;
    setFormErrors({});
    setFormSubmitting(true);

    try {
      const payload = {
        name: formData.name,
        email: formData.email,
        phone: formData.phone || null,
        role: formData.role,
      };

      const res = await api.put<ApiResponse<StaffUser>>(`/admin/staff/${editUser.id}`, payload);
      if (res.data.success) {
        setSuccessMessage(`Staff account ${res.data.data.name} updated successfully.`);
        setEditUser(null);
        if (editUser.id === currentAuthUser?.id) await refreshUser();
        fetchStaff();
      }
    } catch (err: any) {
      if (err.response?.status === 422 && err.response?.data?.errors) {
        const errors: Record<string, string> = {};
        Object.entries(err.response.data.errors).forEach(([k, v]) => {
          errors[k] = Array.isArray(v) ? (v[0] as string) : String(v);
        });
        setFormErrors(errors);
      } else {
        setError(err.response?.data?.message || "Failed to update staff account.");
      }
    } finally {
      setFormSubmitting(false);
    }
  };

  // Submit Password Reset
  const handleResetPasswordSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!resetPasswordUser) return;
    setFormErrors({});

    if (formData.password.length < 8) {
      setFormErrors({ password: "Password must be at least 8 characters." });
      return;
    }

    if (formData.password !== formData.password_confirmation) {
      setFormErrors({ password_confirmation: "Passwords do not match." });
      return;
    }

    setFormSubmitting(true);
    try {
      const res = await api.post<ApiResponse<void>>(`/admin/staff/${resetPasswordUser.id}/reset-password`, {
        password: formData.password,
      });
      if (res.data.success) {
        setSuccessMessage(`Password for ${resetPasswordUser.name} reset successfully.`);
        setResetPasswordUser(null);
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to reset password.");
    } finally {
      setFormSubmitting(false);
    }
  };

  // Toggle Active Status
  const handleToggleStatus = async () => {
    if (!statusConfirmUser) return;
    setFormSubmitting(true);
    try {
      const res = await api.patch<ApiResponse<StaffUser>>(`/admin/staff/${statusConfirmUser.id}/status`);
      if (res.data.success) {
        const action = res.data.data.is_active ? "activated" : "deactivated";
        setSuccessMessage(`Staff account ${statusConfirmUser.name} ${action} successfully.`);
        setStatusConfirmUser(null);
        fetchStaff();
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to change account status.");
      setStatusConfirmUser(null);
    } finally {
      setFormSubmitting(false);
    }
  };

  const getRoleBadge = (roleSlug: string | undefined) => {
    switch (roleSlug) {
      case "super-admin":
        return "bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border-rose-200 dark:border-rose-900";
      case "waiter-waitress":
        return "bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border-blue-200 dark:border-blue-900";
      case "kitchen-staff":
        return "bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 border-purple-200 dark:border-purple-900";
      case "delivery-staff":
        return "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border-amber-200 dark:border-amber-900";
      default:
        return "bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300 border-zinc-200 dark:border-zinc-700";
    }
  };

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-[var(--color-border)]">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Staff Management
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1">
            Manage restaurant staff accounts, roles, and security access
          </p>
        </div>

        {canManageStaff && (
          <button
            type="button"
            onClick={handleOpenCreate}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[var(--brand-copper)] hover:bg-[var(--brand-copper)]/90 text-white font-semibold text-sm shadow-xs transition-colors self-start sm:self-auto"
          >
            <UserPlus size={18} />
            Add Staff Member
          </button>
        )}
      </div>

      {/* Notifications */}
      {successMessage && (
        <div className="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/50 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <CheckCircle className="text-emerald-600 dark:text-emerald-400 shrink-0" size={20} />
            <p className="text-sm text-emerald-800 dark:text-emerald-300 font-medium">
              {successMessage}
            </p>
          </div>
          <button
            type="button"
            onClick={() => setSuccessMessage(null)}
            className="text-emerald-600 hover:text-emerald-800"
          >
            <X size={18} />
          </button>
        </div>
      )}

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

      {/* Search and Filters Bar */}
      <div className="p-4 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
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
            placeholder="Search by name, email, or phone..."
            className="w-full pl-10 pr-4 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-sm text-[var(--color-text)] placeholder-[var(--color-text-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {canManageStaff && (
            <select
              value={roleFilter}
              onChange={(e) => {
                setRoleFilter(e.target.value);
                setPage(1);
              }}
              className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            >
              <option value="">All Roles</option>
              {availableRoles.map((r) => (
                <option key={r.slug} value={r.slug}>
                  {r.name}
                </option>
              ))}
            </select>
          )}

          {/* Status filter */}
          <select
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value);
              setPage(1);
            }}
            className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          >
            <option value="">All Statuses</option>
            <option value="1">Active Only</option>
            <option value="0">Deactivated Only</option>
          </select>

          <button
            type="button"
            onClick={() => fetchStaff()}
            className="p-2 border border-[var(--color-border)] bg-[var(--color-background)] hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] rounded-xl transition-colors"
            title="Refresh list"
          >
            <RefreshCw size={16} />
          </button>
        </div>
      </div>

      {/* Staff Table / List */}
      <div className="rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3">
            <RefreshCw size={24} className="animate-spin mx-auto text-[var(--brand-copper)]" />
            <p className="text-sm font-medium">Loading staff accounts...</p>
          </div>
        ) : staff.length === 0 ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3">
            <Users size={32} className="mx-auto text-[var(--color-text-secondary)]/50" />
            <p className="text-base font-semibold text-[var(--color-text)]">No staff members found</p>
            <p className="text-xs">Adjust your search filter or click Add Staff Member to register someone.</p>
          </div>
        ) : (
          <>
            {/* Desktop Table View */}
            <div className="hidden md:block overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-[var(--color-border)] text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)] bg-[var(--color-background)]/50">
                    <th className="py-3 px-5">Staff Member</th>
                    <th className="py-3 px-5">Contact Details</th>
                    <th className="py-3 px-5">Assigned Role</th>
                    <th className="py-3 px-5">Account Status</th>
                    <th className="py-3 px-5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[var(--color-border)]">
                  {staff.map((u) => {
                    const primaryRole = u.roles?.[0];
                    const isSelf = currentAuthUser?.id === u.id;
                    const isProtectedAdmin = u.roles?.some((role) => role.slug === "super-admin") && !currentAuthUser?.roles.includes("super-admin");

                    return (
                      <tr key={u.id} className="hover:bg-[var(--color-surface-secondary)]/40 transition-colors">
                        <td className="py-3.5 px-5">
                          <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-full bg-[var(--brand-copper)]/10 text-[var(--brand-copper)] font-bold flex items-center justify-center text-sm">
                              {u.name.charAt(0).toUpperCase()}
                            </div>
                            <div>
                              <p className="font-semibold text-[var(--color-text)] flex items-center gap-1.5">
                                {u.name}
                                {isSelf && (
                                  <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                    You
                                  </span>
                                )}
                              </p>
                            </div>
                          </div>
                        </td>
                        <td className="py-3.5 px-5">
                          <p className="text-xs text-[var(--color-text)] font-mono">{u.email}</p>
                          <p className="text-xs text-[var(--color-text-secondary)]">{u.phone || "No phone recorded"}</p>
                        </td>
                        <td className="py-3.5 px-5">
                          <span
                            className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border ${getRoleBadge(
                              primaryRole?.slug
                            )}`}
                          >
                            <Shield size={12} />
                            {primaryRole?.name || "No Role"}
                          </span>
                        </td>
                        <td className="py-3.5 px-5">
                          <span
                            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold ${
                              u.is_active
                                ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                                : "bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                            }`}
                          >
                            {u.is_active ? <CheckCircle size={12} /> : <XCircle size={12} />}
                            {u.is_active ? "Active" : "Deactivated"}
                          </span>
                        </td>
                        <td className="py-3.5 px-5 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            <button
                              type="button"
                              onClick={() => setViewPermissionsUser(u)}
                              className="p-1.5 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors"
                              title="View permissions"
                            >
                              <Eye size={16} />
                            </button>
                            {canManageStaff && (
                              <>
                                <button
                                  type="button"
                                  onClick={() => handleOpenEdit(u)}
                                  disabled={isProtectedAdmin}
                                  className="p-1.5 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors"
                                  title="Edit user details"
                                >
                                  <Edit2 size={16} />
                                </button>
                                <button
                                  type="button"
                                  onClick={() => {
                                    setResetPasswordUser(u);
                                    setFormData((prev) => ({ ...prev, password: "", password_confirmation: "" }));
                                    setFormErrors({});
                                  }}
                                  disabled={isProtectedAdmin}
                                  className="p-1.5 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--brand-gold)] hover:bg-[var(--color-surface-secondary)] transition-colors"
                                  title="Reset password"
                                >
                                  <KeyRound size={16} />
                                </button>
                                <button
                                  type="button"
                                  onClick={() => setStatusConfirmUser(u)}
                                  disabled={isSelf || isProtectedAdmin}
                                  className={`p-1.5 rounded-lg transition-colors ${
                                    isSelf
                                      ? "opacity-30 cursor-not-allowed"
                                      : u.is_active
                                      ? "text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40"
                                      : "text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
                                  }`}
                                  title={
                                    isSelf
                                      ? "Cannot deactivate your own account"
                                      : u.is_active
                                      ? "Deactivate staff account"
                                      : "Activate staff account"
                                  }
                                >
                                  {u.is_active ? <XCircle size={16} /> : <CheckCircle size={16} />}
                                </button>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            {/* Mobile Card View */}
            <div className="block md:hidden divide-y divide-[var(--color-border)]">
              {staff.map((u) => {
                const primaryRole = u.roles?.[0];
                const isSelf = currentAuthUser?.id === u.id;
                const isProtectedAdmin = u.roles?.some((role) => role.slug === "super-admin") && !currentAuthUser?.roles.includes("super-admin");

                return (
                  <div key={u.id} className="p-4 space-y-3">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2.5">
                        <div className="w-9 h-9 rounded-full bg-[var(--brand-copper)]/10 text-[var(--brand-copper)] font-bold flex items-center justify-center text-sm">
                          {u.name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                          <p className="font-semibold text-sm text-[var(--color-text)] flex items-center gap-1.5">
                            {u.name}
                            {isSelf && (
                              <span className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                You
                              </span>
                            )}
                          </p>
                          <p className="text-xs text-[var(--color-text-secondary)] font-mono">{u.email}</p>
                        </div>
                      </div>

                      <span
                        className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold border ${getRoleBadge(
                          primaryRole?.slug
                        )}`}
                      >
                        {primaryRole?.name || "No Role"}
                      </span>
                    </div>

                    <div className="flex items-center justify-between text-xs pt-1">
                      <span className="text-[var(--color-text-secondary)]">{u.phone || "No phone"}</span>
                      <span
                        className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-semibold ${
                          u.is_active
                            ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                            : "bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                        }`}
                      >
                        {u.is_active ? "Active" : "Deactivated"}
                      </span>
                    </div>

                    <div className="flex items-center justify-end gap-2 pt-2 border-t border-[var(--color-border)]">
                      <button
                        type="button"
                        onClick={() => setViewPermissionsUser(u)}
                        className="px-2.5 py-1.5 rounded-lg border border-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] flex items-center gap-1"
                      >
                        <Eye size={14} /> Permissions
                      </button>
                      {canManageStaff && (
                        <>
                          <button
                            type="button"
                            onClick={() => handleOpenEdit(u)}
                            disabled={isProtectedAdmin}
                            className="px-2.5 py-1.5 rounded-lg border border-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] flex items-center gap-1"
                          >
                            <Edit2 size={14} /> Edit
                          </button>
                          <button
                            type="button"
                            onClick={() => {
                              setResetPasswordUser(u);
                              setFormData((prev) => ({ ...prev, password: "", password_confirmation: "" }));
                              setFormErrors({});
                            }}
                            disabled={isProtectedAdmin}
                            className="px-2.5 py-1.5 rounded-lg border border-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] flex items-center gap-1"
                          >
                            <KeyRound size={14} /> Password
                          </button>
                          <button
                            type="button"
                            onClick={() => setStatusConfirmUser(u)}
                            disabled={isSelf || isProtectedAdmin}
                            className={`px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1 ${
                              isSelf
                                ? "opacity-30 cursor-not-allowed border border-[var(--color-border)]"
                                : u.is_active
                                ? "bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/40 dark:border-rose-900"
                                : "bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-900"
                            }`}
                          >
                            {u.is_active ? "Deactivate" : "Activate"}
                          </button>
                        </>
                      )}
                    </div>
                  </div>
                );
              })}
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

      {/* CREATE STAFF MODAL */}
      {createModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
          <div className="w-full max-w-lg rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden my-8">
            <div className="flex items-center justify-between p-5 border-b border-[var(--color-border)]">
              <h2 className="text-lg font-bold text-[var(--color-text)] flex items-center gap-2">
                <UserPlus size={20} className="text-[var(--brand-copper)]" />
                Register New Staff Member
              </h2>
              <button
                type="button"
                onClick={() => setCreateModalOpen(false)}
                className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
              >
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleCreateSubmit} className="p-5 space-y-4">
              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Full Name *
                </label>
                <input
                  type="text"
                  required
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g. Ama Mensah"
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
                {formErrors.name && <p className="text-xs text-rose-600 mt-1">{formErrors.name}</p>}
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                    Email Address *
                  </label>
                  <input
                    type="email"
                    required
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    placeholder="staff@special-delicacies.local"
                    className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                  />
                  {formErrors.email && <p className="text-xs text-rose-600 mt-1">{formErrors.email}</p>}
                </div>

                <div>
                  <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                    Phone Number
                  </label>
                  <input
                    type="text"
                    value={formData.phone}
                    onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                    placeholder="+233 24 000 0000"
                    className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                  />
                  {formErrors.phone && <p className="text-xs text-rose-600 mt-1">{formErrors.phone}</p>}
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Operational Role *
                </label>
                <select
                  value={formData.role}
                  onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                  disabled={rolesLoading || availableRoles.length === 0}
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                >
                  {rolesLoading && <option value="">Loading roles…</option>}
                  {!rolesLoading && availableRoles.length === 0 && <option value="">No assignable roles available</option>}
                  {availableRoles.map((r) => (
                    <option key={r.slug} value={r.slug}>
                      {r.name}
                    </option>
                  ))}
                </select>
                {rolesError && <p role="alert" className="text-xs text-rose-600 mt-1">{rolesError}</p>}
                {formErrors.role && <p className="text-xs text-rose-600 mt-1">{formErrors.role}</p>}
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                    Initial Password (min 8 chars) *
                  </label>
                  <PasswordInput
                    required
                    minLength={8}
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    placeholder="••••••••"
                    className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                  />
                  {formErrors.password && (
                    <p className="text-xs text-rose-600 mt-1">{formErrors.password}</p>
                  )}
                </div>

                <div>
                  <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                    Confirm Password *
                  </label>
                  <PasswordInput
                    required
                    minLength={8}
                    value={formData.password_confirmation}
                    onChange={(e) => setFormData({ ...formData, password_confirmation: e.target.value })}
                    placeholder="••••••••"
                    className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                  />
                  {formErrors.password_confirmation && (
                    <p className="text-xs text-rose-600 mt-1">{formErrors.password_confirmation}</p>
                  )}
                </div>
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-[var(--color-border)]">
                <button
                  type="button"
                  onClick={() => setCreateModalOpen(false)}
                  className="px-4 py-2 rounded-xl border border-[var(--color-border)] text-sm font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={formSubmitting || rolesLoading || !availableRoles.some((role) => role.slug === formData.role)}
                  className="px-4 py-2 rounded-xl bg-[var(--brand-copper)] hover:bg-[var(--brand-copper)]/90 text-white text-sm font-semibold shadow-xs disabled:opacity-50"
                >
                  {formSubmitting ? "Creating..." : "Create Account"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* EDIT STAFF MODAL */}
      {editUser && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
          <div className="w-full max-w-lg rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden my-8">
            <div className="flex items-center justify-between p-5 border-b border-[var(--color-border)]">
              <h2 className="text-lg font-bold text-[var(--color-text)] flex items-center gap-2">
                <Edit2 size={20} className="text-[var(--brand-copper)]" />
                Edit Staff Details
              </h2>
              <button
                type="button"
                onClick={() => setEditUser(null)}
                className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
              >
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleEditSubmit} className="p-5 space-y-4">
              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Full Name *
                </label>
                <input
                  type="text"
                  required
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
                {formErrors.name && <p className="text-xs text-rose-600 mt-1">{formErrors.name}</p>}
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                    Email Address *
                  </label>
                  <input
                    type="email"
                    required
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                  />
                  {formErrors.email && <p className="text-xs text-rose-600 mt-1">{formErrors.email}</p>}
                </div>

                <div>
                  <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                    Phone Number
                  </label>
                  <input
                    type="text"
                    value={formData.phone}
                    onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                    className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                  />
                  {formErrors.phone && <p className="text-xs text-rose-600 mt-1">{formErrors.phone}</p>}
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Operational Role *
                </label>
                <select
                  value={formData.role}
                  onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                  disabled={rolesLoading || availableRoles.length === 0}
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                >
                  {rolesLoading && <option value="">Loading roles…</option>}
                  {!rolesLoading && availableRoles.length === 0 && <option value="">No assignable roles available</option>}
                  {availableRoles.map((r) => (
                    <option key={r.slug} value={r.slug}>
                      {r.name}
                    </option>
                  ))}
                </select>
                {rolesError && <p role="alert" className="text-xs text-rose-600 mt-1">{rolesError}</p>}
                {formErrors.role && <p className="text-xs text-rose-600 mt-1">{formErrors.role}</p>}
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-[var(--color-border)]">
                <button
                  type="button"
                  onClick={() => setEditUser(null)}
                  className="px-4 py-2 rounded-xl border border-[var(--color-border)] text-sm font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={formSubmitting || rolesLoading || !availableRoles.some((role) => role.slug === formData.role)}
                  className="px-4 py-2 rounded-xl bg-[var(--brand-copper)] hover:bg-[var(--brand-copper)]/90 text-white text-sm font-semibold shadow-xs disabled:opacity-50"
                >
                  {formSubmitting ? "Saving..." : "Save Changes"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* RESET PASSWORD MODAL */}
      {resetPasswordUser && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="w-full max-w-md rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden">
            <div className="flex items-center justify-between p-5 border-b border-[var(--color-border)]">
              <h2 className="text-lg font-bold text-[var(--color-text)] flex items-center gap-2">
                <KeyRound size={20} className="text-[var(--brand-gold)]" />
                Reset Password
              </h2>
              <button
                type="button"
                onClick={() => setResetPasswordUser(null)}
                className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
              >
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleResetPasswordSubmit} className="p-5 space-y-4">
              <p className="text-xs text-[var(--color-text-secondary)]">
                Resetting password for <strong className="text-[var(--color-text)]">{resetPasswordUser.name}</strong> ({resetPasswordUser.email}).
                All active login sessions for this account will be invalidated immediately.
              </p>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  New Password (min 8 chars) *
                </label>
                <PasswordInput
                  required
                  minLength={8}
                  value={formData.password}
                  onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                  placeholder="••••••••"
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
                {formErrors.password && (
                  <p className="text-xs text-rose-600 mt-1">{formErrors.password}</p>
                )}
              </div>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Confirm New Password *
                </label>
                <PasswordInput
                  required
                  minLength={8}
                  value={formData.password_confirmation}
                  onChange={(e) => setFormData({ ...formData, password_confirmation: e.target.value })}
                  placeholder="••••••••"
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
                {formErrors.password_confirmation && (
                  <p className="text-xs text-rose-600 mt-1">{formErrors.password_confirmation}</p>
                )}
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-[var(--color-border)]">
                <button
                  type="button"
                  onClick={() => setResetPasswordUser(null)}
                  className="px-4 py-2 rounded-xl border border-[var(--color-border)] text-sm font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={formSubmitting}
                  className="px-4 py-2 rounded-xl bg-[var(--brand-copper)] hover:bg-[var(--brand-copper)]/90 text-white text-sm font-semibold shadow-xs disabled:opacity-50"
                >
                  {formSubmitting ? "Resetting..." : "Reset Password"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* VIEW PERMISSIONS MODAL */}
      {viewPermissionsUser && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="w-full max-w-lg rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden max-h-[85vh] flex flex-col">
            <div className="flex items-center justify-between p-5 border-b border-[var(--color-border)]">
              <div>
                <h2 className="text-lg font-bold text-[var(--color-text)] flex items-center gap-2">
                  <Shield size={20} className="text-[var(--brand-copper)]" />
                  Staff Role & Permissions
                </h2>
                <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">
                  {viewPermissionsUser.name} ({viewPermissionsUser.roles?.[0]?.name})
                </p>
              </div>
              <button
                type="button"
                onClick={() => setViewPermissionsUser(null)}
                className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
              >
                <X size={20} />
              </button>
            </div>

            <div className="p-5 overflow-y-auto space-y-4">
              <div className="p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)]">
                <span className="text-xs text-[var(--color-text-secondary)] block">Assigned Role:</span>
                <span className="text-sm font-bold text-[var(--color-text)]">
                  {viewPermissionsUser.roles?.[0]?.name}
                </span>
                <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                  {viewPermissionsUser.roles?.[0]?.description ||
                    "Role grants specific permissions for operational workflows."}
                </p>
              </div>

              <div>
                <h3 className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-2">
                  Assigned Capabilities
                </h3>
                {viewPermissionsUser.roles?.[0]?.slug === "super-admin" ? (
                  <div className="p-3 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 text-xs text-rose-800 dark:text-rose-300">
                    <strong>Super Admin:</strong> Has full access to all system capabilities,
                    settings, audit logs, menus, staff accounts, tables, and operational workflows.
                  </div>
                ) : (
                  <div className="space-y-1.5">
                    {viewPermissionsUser.roles?.[0]?.permissions &&
                    viewPermissionsUser.roles[0].permissions.length > 0 ? (
                      viewPermissionsUser.roles[0].permissions.map((p) => (
                        <div
                          key={p.id}
                          className="px-3 py-2 rounded-lg bg-[var(--color-background)] border border-[var(--color-border)] text-xs flex items-center justify-between"
                        >
                          <span className="font-semibold text-[var(--color-text)]">{p.name}</span>
                          <span className="font-mono text-[10px] text-[var(--color-text-secondary)]">{p.slug}</span>
                        </div>
                      ))
                    ) : (
                      <p className="text-xs text-[var(--color-text-secondary)] italic">
                        Standard operational permissions assigned by role.
                      </p>
                    )}
                  </div>
                )}
              </div>
            </div>

            <div className="p-4 border-t border-[var(--color-border)] text-right">
              <button
                type="button"
                onClick={() => setViewPermissionsUser(null)}
                className="px-4 py-2 rounded-xl bg-[var(--color-surface-secondary)] hover:bg-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      {/* STATUS TOGGLE CONFIRMATION MODAL */}
      {statusConfirmUser && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="w-full max-w-md rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden p-5 space-y-4">
            <div className="flex items-center gap-3 text-amber-600 dark:text-amber-400">
              <AlertCircle size={24} />
              <h2 className="text-base font-bold text-[var(--color-text)]">
                {statusConfirmUser.is_active ? "Deactivate Account?" : "Activate Account?"}
              </h2>
            </div>

            <p className="text-xs text-[var(--color-text-secondary)] leading-relaxed">
              Are you sure you want to {statusConfirmUser.is_active ? "deactivate" : "activate"} the account for{" "}
              <strong className="text-[var(--color-text)]">{statusConfirmUser.name}</strong> ({statusConfirmUser.email})?
              {statusConfirmUser.is_active &&
                " Deactivated users are blocked from logging in, and all active sessions are terminated immediately."}
            </p>

            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={() => setStatusConfirmUser(null)}
                className="px-4 py-2 rounded-xl border border-[var(--color-border)] text-xs font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={formSubmitting}
                onClick={handleToggleStatus}
                className={`px-4 py-2 rounded-xl text-xs font-semibold text-white shadow-xs disabled:opacity-50 ${
                  statusConfirmUser.is_active ? "bg-rose-600 hover:bg-rose-700" : "bg-emerald-600 hover:bg-emerald-700"
                }`}
              >
                {formSubmitting
                  ? "Processing..."
                  : statusConfirmUser.is_active
                  ? "Yes, Deactivate"
                  : "Yes, Activate"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
