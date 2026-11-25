import { useState, useEffect, useCallback } from "react";
import {
  ShieldCheck,
  Users,
  CheckCircle,
  Lock,
  Edit2,
  RefreshCw,
  AlertCircle,
  X,
  Check,
  Plus,
  Trash2,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import type { RoleItem, PermissionItem, ApiResponse } from "@/types/api";
import { useToast } from "@/components/ui/useToast";
import { useConfirm } from "@/components/ui/ConfirmDialog";
import { useAuth } from "@/app/providers/useAuth";

export default function AdminRolesPage() {
  const { refreshUser } = useAuth();
  const { error: showToastError } = useToast();
  const confirm = useConfirm();
  const [roles, setRoles] = useState<RoleItem[]>([]);
  const [allPermissions, setAllPermissions] = useState<PermissionItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Edit modal
  const [editingRole, setEditingRole] = useState<RoleItem | null>(null);
  const [selectedSlugs, setSelectedSlugs] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);
  const [roleName, setRoleName] = useState("");
  const [roleSlug, setRoleSlug] = useState("");
  const [roleDescription, setRoleDescription] = useState("");

  useEffect(() => {
    document.title = "Roles & Permissions | Special Delicacies Admin";
  }, []);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [rolesRes, permsRes] = await Promise.all([
        cachedGet<ApiResponse<RoleItem[]>>("/admin/roles", 15_000),
        cachedGet<ApiResponse<PermissionItem[]>>("/admin/permissions", 15_000),
      ]);

      if (rolesRes.data.success) {
        setRoles(rolesRes.data.data);
      }
      if (permsRes.data.success) {
        setAllPermissions(permsRes.data.data);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to load roles and permissions.";
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const handleOpenEdit = (role: RoleItem) => {
    setEditingRole(role);
    setRoleName(role.name);
    setRoleSlug(role.slug);
    setRoleDescription(role.description || "");
    setSelectedSlugs(role.permissions?.map((p) => p.slug) || []);
  };

  const handleOpenCreate = () => {
    setEditingRole({ id: 0, name: "", slug: "", description: "", permissions: [] });
    setRoleName("");
    setRoleSlug("");
    setRoleDescription("");
    setSelectedSlugs([]);
  };

  const handleDeleteRole = async (role: RoleItem) => {
    const approved = await confirm({ title: "Delete role?", message: `Delete “${role.name}”? Roles assigned to staff cannot be deleted.`, confirmLabel: "Delete role", tone: "danger" });
    if (!approved) return;
    try {
      await api.delete(`/admin/roles/${role.id}`);
      setSuccessMessage(`Role '${role.name}' deleted.`);
      fetchData();
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to delete role.");
    }
  };

  const togglePermission = (slug: string) => {
    // Super Admin permission authority is intrinsic and cannot be edited.
    if (editingRole?.slug === "super-admin") {
      showToastError(`The Super Admin role intrinsically has all permissions and cannot be edited.`);
      return;
    }

    setSelectedSlugs((prev) =>
      prev.includes(slug) ? prev.filter((s) => s !== slug) : [...prev, slug]
    );
  };

  const handleSavePermissions = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingRole) return;
    if (editingRole.slug === "super-admin") {
      setError("The Super Admin role intrinsically has all permissions and cannot be edited.");
      return;
    }
    setSaving(true);
    setError(null);

    try {
      let res;
      if (editingRole.id === 0) {
        res = await api.post<ApiResponse<RoleItem>>("/admin/roles", {
          name: roleName.trim(), slug: roleSlug.trim(), description: roleDescription.trim(), permission_slugs: selectedSlugs,
        });
      } else {
        if (editingRole.slug !== "super-admin") {
          await api.put(`/admin/roles/${editingRole.id}`, {
            name: roleName.trim(), slug: roleSlug.trim(), description: roleDescription.trim(),
          });
        }
        res = await api.put<ApiResponse<RoleItem>>(`/admin/roles/${editingRole.id}/permissions`, { permission_slugs: selectedSlugs });
      }

      if (res.data.success) {
        setSuccessMessage(editingRole.id === 0 ? `Role '${roleName}' created successfully.` : `Role '${roleName}' updated successfully.`);
        setEditingRole(null);
        await refreshUser();
        fetchData();
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to update permissions.");
    } finally {
      setSaving(false);
    }
  };

  // Group permissions by category
  const permissionsByGroup = allPermissions.reduce<Record<string, PermissionItem[]>>(
    (acc, perm) => {
      const grp = perm.group || "General";
      if (!acc[grp]) acc[grp] = [];
      acc[grp].push(perm);
      return acc;
    },
    {}
  );

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-[var(--color-border)]">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Roles & Permissions
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1">
            Role-based access control and operational privilege enforcement
          </p>
        </div>

        <button
          type="button"
          onClick={handleOpenCreate}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[var(--color-primary)] text-[var(--color-background)] text-sm font-semibold hover:bg-[var(--color-primary-hover)]"
        >
          <Plus size={16} /> Add Role
        </button>
        <button
          type="button"
          onClick={() => fetchData()}
          className="p-2 border border-[var(--color-border)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] rounded-xl transition-colors self-start sm:self-auto"
          title="Refresh roles"
        >
          <RefreshCw size={16} />
        </button>
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

      {/* Role Cards Grid */}
      {loading ? (
        <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3">
          <RefreshCw size={24} className="animate-spin mx-auto text-[var(--brand-copper)]" />
          <p className="text-sm font-medium">Loading roles and permissions matrix...</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {roles.map((role) => {
            const isAdmin = role.slug === "super-admin";
            const rolePerms = role.permissions || [];

            return (
              <div
                key={role.id}
                className="rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs overflow-hidden flex flex-col justify-between"
              >
                <div className="p-5 space-y-4">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div
                        className={`w-10 h-10 rounded-xl flex items-center justify-center font-bold ${
                          isAdmin
                            ? "bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400"
                            : "bg-[var(--brand-copper)]/10 text-[var(--brand-copper)]"
                        }`}
                      >
                        <ShieldCheck size={20} />
                      </div>
                      <div>
                        <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
                          {role.name}
                          {isAdmin && (
                            <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300">
                              Primary Admin
                            </span>
                          )}
                        </h2>
                        <span className="text-xs font-mono text-[var(--color-text-secondary)]">
                          {role.slug}
                        </span>
                      </div>
                    </div>

                    <div className="flex items-center gap-2">
                      <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-[var(--color-background)] border border-[var(--color-border)] text-[var(--color-text)]">
                        <Users size={12} />
                        {role.users_count ?? 0} staff
                      </span>
                    </div>
                  </div>

                  <p className="text-xs text-[var(--color-text-secondary)] leading-relaxed">
                    {role.description}
                  </p>

                  {/* Permissions sample pills */}
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
                        Assigned Permissions ({isAdmin ? "All" : rolePerms.length})
                      </span>
                    </div>

                    {isAdmin ? (
                      <div className="p-3 rounded-xl bg-rose-50/50 dark:bg-rose-950/20 border border-rose-200/50 dark:border-rose-900/30 text-xs text-rose-800 dark:text-rose-300">
                        <p className="font-semibold">Super-Admin Capabilities:</p>
                        <p className="text-[11px] mt-0.5 text-rose-700 dark:text-rose-400">
                          The Super Admin role intrinsically possesses all system permissions across
                          management, operations, settings, and audit logs.
                        </p>
                      </div>
                    ) : (
                      <div className="flex flex-wrap gap-1.5 max-h-36 overflow-y-auto p-1">
                        {rolePerms.length === 0 ? (
                          <span className="text-xs text-[var(--color-text-secondary)] italic">
                            No permissions assigned.
                          </span>
                        ) : (
                          rolePerms.map((p) => (
                            <span
                              key={p.id}
                              className="px-2 py-1 rounded-lg bg-[var(--color-background)] border border-[var(--color-border)] text-[11px] font-medium text-[var(--color-text)]"
                              title={p.description}
                            >
                              {p.name}
                            </span>
                          ))
                        )}
                      </div>
                    )}
                  </div>
                </div>

                <div className="p-4 bg-[var(--color-background)]/50 border-t border-[var(--color-border)] flex items-center justify-between">
                  <span className="text-[11px] text-[var(--color-text-secondary)]">
                    Protected operational boundaries
                  </span>
                  <button
                    type="button"
                    onClick={() => handleOpenEdit(role)}
                    disabled={isAdmin}
                    title={isAdmin ? "Super Admin permissions are intrinsic and cannot be edited" : undefined}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)] text-xs font-semibold text-[var(--color-text)] transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    <Edit2 size={13} />
                    {isAdmin ? "System Role" : "Edit Permissions"}
                  </button>
                  {!isAdmin && (
                    <button type="button" onClick={() => handleDeleteRole(role)} disabled={(role.users_count ?? 0) > 0} title={(role.users_count ?? 0) > 0 ? "Reassign staff before deleting this role" : "Delete role"} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-[var(--color-danger)] hover:bg-[var(--color-surface-secondary)] disabled:opacity-40 disabled:cursor-not-allowed">
                      <Trash2 size={13} /> Delete
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* EDIT PERMISSIONS MODAL */}
      {editingRole && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="w-full max-w-2xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden max-h-[90vh] flex flex-col">
            <div className="flex items-center justify-between p-5 border-b border-[var(--color-border)]">
              <div>
                <h2 className="text-lg font-bold text-[var(--color-text)] flex items-center gap-2">
                  <ShieldCheck size={20} className="text-[var(--brand-copper)]" />
                  Configure Permissions for {editingRole.name}
                </h2>
                <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">
                  Select granted capabilities for users assigned the {editingRole.name} role
                </p>
              </div>
              <button
                type="button"
                onClick={() => setEditingRole(null)}
                className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
              >
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleSavePermissions} className="flex flex-col flex-1 overflow-hidden">
              <div className="p-5 overflow-y-auto space-y-6">
                {editingRole.slug !== "super-admin" && (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label className="text-xs font-semibold text-[var(--color-text-secondary)]">Role name
                      <input required maxLength={100} value={roleName} onChange={(event) => setRoleName(event.target.value)} className="mt-1 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 py-2 text-sm text-[var(--color-text)]" />
                    </label>
                    <label className="text-xs font-semibold text-[var(--color-text-secondary)]">Role key
                      <input required maxLength={100} pattern="[A-Za-z0-9_-]+" value={roleSlug} onChange={(event) => setRoleSlug(event.target.value)} className="mt-1 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 py-2 text-sm text-[var(--color-text)]" />
                    </label>
                    <label className="text-xs font-semibold text-[var(--color-text-secondary)] sm:col-span-2">Description
                      <textarea maxLength={500} value={roleDescription} onChange={(event) => setRoleDescription(event.target.value)} className="mt-1 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 py-2 text-sm text-[var(--color-text)]" rows={2} />
                    </label>
                  </div>
                )}
                {Object.entries(permissionsByGroup).map(([groupName, groupPerms]) => (
                  <div key={groupName} className="space-y-2.5">
                    <h3 className="text-xs font-extrabold uppercase tracking-wider text-[var(--brand-copper)] flex items-center gap-2">
                      <span>{groupName}</span>
                      <span className="text-[10px] font-normal text-[var(--color-text-secondary)]">
                        ({groupPerms.filter((p) => selectedSlugs.includes(p.slug)).length} /{" "}
                        {groupPerms.length})
                      </span>
                    </h3>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      {groupPerms.map((perm) => {
                        const isSelected = selectedSlugs.includes(perm.slug);
                        const isCriticalAdmin =
                          editingRole.slug === "super-admin" &&
                          [
                            "staff.manage",
                            "settings.manage",
                            "roles.manage",
                            "orders.view",
                            "orders.complete",
                            "audit.view",
                            "content.manage",
                          ].includes(perm.slug);

                        return (
                          <div
                            key={perm.id}
                            onClick={() => !isCriticalAdmin && togglePermission(perm.slug)}
                            className={`p-2.5 rounded-xl border text-xs transition-colors flex items-start gap-2.5 ${
                              isCriticalAdmin
                                ? "bg-rose-50/50 dark:bg-rose-950/20 border-rose-200 dark:border-rose-900 cursor-not-allowed"
                                : isSelected
                                ? "bg-[var(--brand-copper)]/10 border-[var(--brand-copper)] cursor-pointer"
                                : "bg-[var(--color-background)] border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)] cursor-pointer"
                            }`}
                          >
                            <div
                              className={`w-4 h-4 rounded mt-0.5 flex items-center justify-center shrink-0 border ${
                                isSelected
                                  ? "bg-[var(--brand-copper)] border-[var(--brand-copper)] text-white"
                                  : "border-[var(--color-border)]"
                              }`}
                            >
                              {isSelected && <Check size={12} />}
                            </div>

                            <div className="flex-1">
                              <p className="font-semibold text-[var(--color-text)] flex items-center gap-1.5">
                                {perm.name}
                                {isCriticalAdmin && (
                                  <Lock size={10} className="text-rose-600 dark:text-rose-400" />
                                )}
                              </p>
                              <p className="text-[11px] text-[var(--color-text-secondary)] mt-0.5">
                                {perm.description}
                              </p>
                              <span className="text-[9px] font-mono text-[var(--color-text-secondary)] block mt-1">
                                {perm.slug}
                              </span>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </div>

              <div className="p-4 border-t border-[var(--color-border)] flex items-center justify-between bg-[var(--color-surface)]">
                <span className="text-xs text-[var(--color-text-secondary)]">
                  {selectedSlugs.length} permissions enabled
                </span>
                <div className="flex items-center gap-3">
                  <button
                    type="button"
                    onClick={() => setEditingRole(null)}
                    className="px-4 py-2 rounded-xl border border-[var(--color-border)] text-xs font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={saving}
                    className="px-4 py-2 rounded-xl bg-[var(--brand-copper)] hover:bg-[var(--brand-copper)]/90 text-white text-xs font-semibold shadow-xs disabled:opacity-50"
                  >
                    {saving ? "Saving..." : "Save Permissions"}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
