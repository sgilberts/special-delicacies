import { useEffect, useState, useCallback, useMemo } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  FolderTree,
  Plus,
  Search,
  Edit2,
  Trash2,
  X,
  Loader2,
  AlertTriangle,
  RefreshCw,
  Power,
  Utensils,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import { useToast } from "@/components/ui/useToast";
import type { ApiResponse, MenuCategory } from "@/types/api";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ErrorState from "@/components/public/ErrorState";

const categorySchema = z.object({
  name: z.string().min(1, "Category name is required").max(100, "Name must not exceed 100 characters"),
  slug: z.string().max(100, "Slug must not exceed 100 characters").optional().or(z.literal("")),
  description: z.string().max(1000, "Description must not exceed 1000 characters").optional().or(z.literal("")),
  sort_order: z.number().min(0, "Order must be 0 or greater"),
  is_active: z.boolean(),
});

type CategoryFormValues = z.infer<typeof categorySchema>;

export default function AdminCategoriesPage() {
  const { success, error: toastError } = useToast();
  const [categories, setCategories] = useState<MenuCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Search & Filter
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState<"all" | "active" | "inactive">("all");

  // Modals state
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [editingCategory, setEditingCategory] = useState<MenuCategory | null>(null);
  const [deletingCategory, setDeletingCategory] = useState<MenuCategory | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  // Form for create/edit
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<CategoryFormValues>({
    resolver: zodResolver(categorySchema),
    defaultValues: {
      name: "",
      slug: "",
      description: "",
      sort_order: 0,
      is_active: true,
    },
  });

  const fetchCategories = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const resp = await cachedGet<ApiResponse<MenuCategory[]>>("/admin/menu/categories", 15_000);
      if (resp.data?.success && Array.isArray(resp.data.data)) {
        setCategories(resp.data.data);
      } else {
        setError("Failed to load categories.");
      }
    } catch {
      setError("Unable to connect to the categories service.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    document.title = "Categories | Menu Management";
    fetchCategories();
  }, [fetchCategories]);

  // Open Create Modal
  const openCreateModal = () => {
    reset({
      name: "",
      slug: "",
      description: "",
      sort_order: categories.length > 0 ? Math.max(...categories.map((c) => c.sort_order)) + 1 : 0,
      is_active: true,
    });
    setEditingCategory(null);
    setIsCreateOpen(true);
  };

  // Open Edit Modal
  const openEditModal = (cat: MenuCategory) => {
    reset({
      name: cat.name,
      slug: cat.slug,
      description: cat.description || "",
      sort_order: cat.sort_order,
      is_active: cat.is_active,
    });
    setEditingCategory(cat);
    setIsCreateOpen(true);
  };

  // Handle Create or Update Submit
  const onSubmit = async (values: CategoryFormValues) => {
    try {
      if (editingCategory) {
        const resp = await api.put<ApiResponse<MenuCategory>>(
          `/admin/menu/categories/${editingCategory.id}`,
          values
        );
        if (resp.data?.success) {
          success(`Category '${values.name}' updated successfully.`);
          setIsCreateOpen(false);
          setEditingCategory(null);
          fetchCategories();
        }
      } else {
        const resp = await api.post<ApiResponse<MenuCategory>>("/admin/menu/categories", values);
        if (resp.data?.success) {
          success(`Category '${values.name}' created successfully.`);
          setIsCreateOpen(false);
          fetchCategories();
        }
      }
    } catch (err: unknown) {
      if (err && typeof err === "object" && "response" in err) {
        const axErr = err as { response?: { data?: { message?: string } } };
        toastError(axErr.response?.data?.message || "Failed to save category.");
      } else {
        toastError("A network error occurred while saving.");
      }
    }
  };

  // Quick Toggle Active/Inactive
  const handleToggleStatus = async (cat: MenuCategory) => {
    const nextStatus = !cat.is_active;
    try {
      const resp = await api.patch<ApiResponse<MenuCategory>>(
        `/admin/menu/categories/${cat.id}/status`,
        { is_active: nextStatus }
      );
      if (resp.data?.success) {
        success(`Category '${cat.name}' ${nextStatus ? "activated" : "deactivated"}.`);
        setCategories((prev) =>
          prev.map((c) => (c.id === cat.id ? { ...c, is_active: nextStatus } : c))
        );
      }
    } catch {
      toastError(`Failed to update status for '${cat.name}'.`);
    }
  };

  // Handle Safe Delete
  const confirmDelete = async () => {
    if (!deletingCategory) return;
    setIsDeleting(true);
    try {
      const resp = await api.delete<ApiResponse<unknown>>(
        `/admin/menu/categories/${deletingCategory.id}`
      );
      if (resp.data?.success) {
        success(`Category '${deletingCategory.name}' deleted safely.`);
        setDeletingCategory(null);
        fetchCategories();
      }
    } catch (err: unknown) {
      if (err && typeof err === "object" && "response" in err) {
        const axErr = err as { response?: { data?: { message?: string } } };
        toastError(
          axErr.response?.data?.message ||
            "Cannot delete category. Ensure no menu items are associated with it."
        );
      } else {
        toastError("Unable to delete category due to a server error.");
      }
    } finally {
      setIsDeleting(false);
    }
  };

  // Filtered categories
  const filteredCategories = useMemo(() => {
    return categories.filter((cat) => {
      const matchesSearch =
        cat.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (cat.description && cat.description.toLowerCase().includes(searchQuery.toLowerCase()));

      const matchesStatus =
        statusFilter === "all"
          ? true
          : statusFilter === "active"
          ? cat.is_active
          : !cat.is_active;

      return matchesSearch && matchesStatus;
    });
  }, [categories, searchQuery, statusFilter]);

  return (
    <div className="space-y-6 animate-in fade-in duration-200">
      {/* 1. HEADER */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[var(--color-border)] pb-5">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Menu Categories
          </h1>
          <p className="text-xs sm:text-sm text-[var(--color-text-secondary)] mt-1">
            Organize dishes into customer-facing culinary groupings (e.g. Soups, Grills, Beverages).
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={fetchCategories}
            disabled={loading}
            className="btn-ghost flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold min-h-[44px]"
            title="Refresh categories"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
            <span className="hidden sm:inline">Refresh</span>
          </button>

          <button
            type="button"
            onClick={openCreateModal}
            className="btn-accent flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold shadow-sm min-h-[44px]"
          >
            <Plus className="w-4 h-4" />
            <span>Add Category</span>
          </button>
        </div>
      </div>

      {/* 2. SEARCH & FILTER BAR */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
        <div className="relative flex-1">
          <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--color-text-secondary)]" />
          <input
            type="text"
            placeholder="Search categories by name or description..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-10 pr-4 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] min-h-[44px]"
          />
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setStatusFilter("all")}
            className={`px-3 py-2 rounded-xl text-xs font-semibold transition-all min-h-[44px] ${
              statusFilter === "all"
                ? "bg-[var(--color-accent)] text-white shadow-xs"
                : "btn-ghost"
            }`}
          >
            All ({categories.length})
          </button>
          <button
            type="button"
            onClick={() => setStatusFilter("active")}
            className={`px-3 py-2 rounded-xl text-xs font-semibold transition-all min-h-[44px] ${
              statusFilter === "active"
                ? "bg-[var(--color-accent)] text-white shadow-xs"
                : "btn-ghost"
            }`}
          >
            Active ({categories.filter((c) => c.is_active).length})
          </button>
          <button
            type="button"
            onClick={() => setStatusFilter("inactive")}
            className={`px-3 py-2 rounded-xl text-xs font-semibold transition-all min-h-[44px] ${
              statusFilter === "inactive"
                ? "bg-[var(--color-accent)] text-white shadow-xs"
                : "btn-ghost"
            }`}
          >
            Inactive ({categories.filter((c) => !c.is_active).length})
          </button>
        </div>
      </div>

      {/* 3. CONTENT AREA */}
      {loading && !categories.length && (
        <div className="py-12">
          <LoadingSpinner message="Loading menu categories..." />
        </div>
      )}

      {error && !categories.length && (
        <div className="py-10">
          <ErrorState message={error} onRetry={fetchCategories} />
        </div>
      )}

      {!loading && !error && filteredCategories.length === 0 && (
        <div className="bg-[var(--color-surface)] border border-dashed border-[var(--color-border)] rounded-2xl p-10 text-center">
          <FolderTree className="w-10 h-10 mx-auto text-[var(--color-text-secondary)] mb-3 opacity-60" />
          <h3 className="text-base font-bold text-[var(--color-text)]">
            {searchQuery || statusFilter !== "all" ? "No categories matched your criteria" : "No categories configured"}
          </h3>
          <p className="text-xs text-[var(--color-text-secondary)] mt-1 max-w-sm mx-auto">
            {searchQuery || statusFilter !== "all"
              ? "Try clearing filters or search terms."
              : "Create your first food category to begin organizing menu dishes."}
          </p>
          {!searchQuery && statusFilter === "all" && (
            <button
              type="button"
              onClick={openCreateModal}
              className="mt-4 btn-accent inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold min-h-[44px]"
            >
              <Plus className="w-4 h-4" />
              <span>Create Category</span>
            </button>
          )}
        </div>
      )}

      {/* 4. RESPONSIVE CATEGORIES DISPLAY */}
      {filteredCategories.length > 0 && (
        <>
          {/* Desktop & Tablet Table */}
          <div className="hidden md:block bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl overflow-hidden shadow-xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-[var(--color-surface-secondary)]/60 text-xs uppercase tracking-wider text-[var(--color-text-secondary)] border-b border-[var(--color-border)]">
                  <tr>
                    <th scope="col" className="px-5 py-3.5 font-bold">Order</th>
                    <th scope="col" className="px-5 py-3.5 font-bold">Category Name</th>
                    <th scope="col" className="px-5 py-3.5 font-bold">Slug</th>
                    <th scope="col" className="px-5 py-3.5 font-bold">Description</th>
                    <th scope="col" className="px-5 py-3.5 font-bold text-center">Items</th>
                    <th scope="col" className="px-5 py-3.5 font-bold text-center">Status</th>
                    <th scope="col" className="px-5 py-3.5 font-bold text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[var(--color-border)]">
                  {filteredCategories.map((cat) => (
                    <tr
                      key={cat.id}
                      className="hover:bg-[var(--color-surface-secondary)]/30 transition-colors"
                    >
                      <td className="px-5 py-4 font-mono text-xs text-[var(--color-text-secondary)]">
                        #{cat.sort_order}
                      </td>
                      <td className="px-5 py-4 font-bold text-[var(--color-text)]">
                        {cat.name}
                      </td>
                      <td className="px-5 py-4 font-mono text-xs text-[var(--color-text-secondary)]">
                        {cat.slug}
                      </td>
                      <td className="px-5 py-4 text-xs text-[var(--color-text-secondary)] max-w-xs truncate">
                        {cat.description || <span className="italic opacity-60">No description</span>}
                      </td>
                      <td className="px-5 py-4 text-center">
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-[var(--color-surface-secondary)] text-[var(--color-accent)]">
                          <Utensils className="w-3 h-3" />
                          <span>{cat.items_count ?? 0}</span>
                        </span>
                      </td>
                      <td className="px-5 py-4 text-center">
                        <button
                          type="button"
                          onClick={() => handleToggleStatus(cat)}
                          className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all min-h-[32px] ${
                            cat.is_active
                              ? "bg-[var(--color-success)]/15 text-[var(--color-success)] hover:bg-[var(--color-success)]/25"
                              : "bg-[var(--color-danger)]/15 text-[var(--color-danger)] hover:bg-[var(--color-danger)]/25"
                          }`}
                          title={`Click to ${cat.is_active ? "deactivate" : "activate"}`}
                        >
                          <Power className="w-3 h-3" />
                          <span>{cat.is_active ? "Active" : "Inactive"}</span>
                        </button>
                      </td>
                      <td className="px-5 py-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            type="button"
                            onClick={() => openEditModal(cat)}
                            className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center"
                            title="Edit Category"
                            aria-label={`Edit ${cat.name}`}
                          >
                            <Edit2 className="w-4 h-4" />
                          </button>
                          <button
                            type="button"
                            onClick={() => setDeletingCategory(cat)}
                            className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-danger)] hover:bg-[var(--color-surface-secondary)] transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center"
                            title="Delete Category"
                            aria-label={`Delete ${cat.name}`}
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
          </div>

          {/* Mobile Card List (No horizontal overflow) */}
          <div className="md:hidden space-y-3">
            {filteredCategories.map((cat) => (
              <div
                key={cat.id}
                className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-4 shadow-xs space-y-3"
              >
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <span className="text-[10px] font-mono font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
                      Order #{cat.sort_order}
                    </span>
                    <h3 className="text-base font-bold text-[var(--color-text)] mt-0.5">
                      {cat.name}
                    </h3>
                    <span className="text-xs font-mono text-[var(--color-text-secondary)] block">
                      /{cat.slug}
                    </span>
                  </div>

                  <button
                    type="button"
                    onClick={() => handleToggleStatus(cat)}
                    className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold transition-all min-h-[36px] ${
                      cat.is_active
                        ? "bg-[var(--color-success)]/15 text-[var(--color-success)]"
                        : "bg-[var(--color-danger)]/15 text-[var(--color-danger)]"
                    }`}
                  >
                    <Power className="w-3 h-3" />
                    <span>{cat.is_active ? "Active" : "Inactive"}</span>
                  </button>
                </div>

                {cat.description && (
                  <p className="text-xs text-[var(--color-text-secondary)] leading-relaxed">
                    {cat.description}
                  </p>
                )}

                <div className="pt-2 border-t border-[var(--color-border)] flex items-center justify-between">
                  <span className="inline-flex items-center gap-1.5 text-xs text-[var(--color-text-secondary)]">
                    <Utensils className="w-3.5 h-3.5 text-[var(--color-accent)]" />
                    <strong>{cat.items_count ?? 0}</strong> associated dishes
                  </span>

                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      onClick={() => openEditModal(cat)}
                      className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] min-h-[44px] flex items-center gap-1"
                    >
                      <Edit2 className="w-3.5 h-3.5" />
                      <span>Edit</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => setDeletingCategory(cat)}
                      className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-xs font-semibold text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 min-h-[44px] flex items-center gap-1"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                      <span>Delete</span>
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      {/* 5. CREATE / EDIT MODAL */}
      {isCreateOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="modal-title"
          className="action-modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
        >
          <div className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl w-full max-w-lg p-6 shadow-2xl space-y-5 animate-in zoom-in-95">
            <div className="flex items-center justify-between border-b border-[var(--color-border)] pb-3">
              <h2 id="modal-title" className="text-lg font-bold text-[var(--color-text)]">
                {editingCategory ? "Edit Category" : "New Food Category"}
              </h2>
              <button
                type="button"
                onClick={() => setIsCreateOpen(false)}
                className="p-1 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text)] min-h-[44px] min-w-[44px] flex items-center justify-center"
                aria-label="Close dialog"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
              <div>
                <label
                  htmlFor="cat-name"
                  className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1"
                >
                  Category Name *
                </label>
                <input
                  id="cat-name"
                  type="text"
                  placeholder="e.g. Rice Dishes, Soups, Grills"
                  {...register("name")}
                  className="w-full px-3.5 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] min-h-[44px]"
                />
                {errors.name && (
                  <p className="mt-1 text-xs text-[var(--color-danger)]">{errors.name.message}</p>
                )}
              </div>

              <div>
                <label
                  htmlFor="cat-slug"
                  className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1"
                >
                  Custom Slug (Optional)
                </label>
                <input
                  id="cat-slug"
                  type="text"
                  placeholder="Auto-generated if left empty (e.g. rice-dishes)"
                  {...register("slug")}
                  className="w-full px-3.5 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] min-h-[44px]"
                />
                {errors.slug && (
                  <p className="mt-1 text-xs text-[var(--color-danger)]">{errors.slug.message}</p>
                )}
              </div>

              <div>
                <label
                  htmlFor="cat-desc"
                  className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1"
                >
                  Description
                </label>
                <textarea
                  id="cat-desc"
                  rows={3}
                  placeholder="A brief culinary description shown on public menu..."
                  {...register("description")}
                  className="w-full px-3.5 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                />
                {errors.description && (
                  <p className="mt-1 text-xs text-[var(--color-danger)]">
                    {errors.description.message}
                  </p>
                )}
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label
                    htmlFor="cat-order"
                    className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1"
                  >
                    Display Order
                  </label>
                  <input
                    id="cat-order"
                    type="number"
                    min="0"
                    {...register("sort_order", { valueAsNumber: true })}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] min-h-[44px]"
                  />
                </div>

                <div className="flex flex-col justify-end">
                  <label className="flex items-center gap-2 cursor-pointer min-h-[44px]">
                    <input
                      type="checkbox"
                      {...register("is_active")}
                      className="w-4 h-4 rounded text-[var(--color-accent)] focus:ring-[var(--color-accent)]"
                    />
                    <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text)]">
                      Active Category
                    </span>
                  </label>
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-[var(--color-border)]">
                <button
                  type="button"
                  onClick={() => setIsCreateOpen(false)}
                  disabled={isSubmitting}
                  className="btn-ghost px-4 py-2.5 rounded-xl text-xs font-semibold min-h-[44px]"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="btn-accent flex items-center gap-1.5 px-5 py-2.5 rounded-xl text-xs font-bold min-h-[44px] shadow-sm disabled:opacity-60"
                >
                  {isSubmitting ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      <span>Saving...</span>
                    </>
                  ) : (
                    <span>{editingCategory ? "Update Category" : "Create Category"}</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 6. SAFE DELETION CONFIRMATION DIALOG */}
      {deletingCategory && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="delete-dialog-title"
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in"
        >
          <div className="action-modal-panel bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl w-full max-w-md p-6 shadow-2xl space-y-4">
            <div className="flex items-center gap-3 text-[var(--color-danger)]">
              <div className="w-10 h-10 rounded-full bg-[var(--color-danger)]/15 flex items-center justify-center shrink-0">
                <AlertTriangle className="w-5 h-5 text-[var(--color-danger)]" />
              </div>
              <h2 id="delete-dialog-title" className="text-base font-bold text-[var(--color-text)]">
                Delete Category &lsquo;{deletingCategory.name}&rsquo;?
              </h2>
            </div>

            <p className="text-xs sm:text-sm text-[var(--color-text-secondary)] leading-relaxed">
              Are you sure you want to delete this category? This action cannot be undone.
            </p>

            {deletingCategory.items_count && deletingCategory.items_count > 0 ? (
              <div className="p-3 rounded-xl border border-[var(--color-danger)] bg-[var(--color-danger)]/10 text-xs text-[var(--color-danger)] font-medium leading-snug">
                Warning: This category currently contains {deletingCategory.items_count} dish(es).
                Restaurant safety rules prohibit deleting non-empty categories. You must reassign or
                delete those items first.
              </div>
            ) : null}

            <div className="flex items-center justify-end gap-2 pt-3 border-t border-[var(--color-border)]">
              <button
                type="button"
                onClick={() => setDeletingCategory(null)}
                disabled={isDeleting}
                className="btn-ghost px-4 py-2.5 rounded-xl text-xs font-semibold min-h-[44px]"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={confirmDelete}
                disabled={isDeleting}
                className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-[var(--color-danger)] hover:opacity-90 transition-all min-h-[44px] flex items-center gap-1.5 shadow-sm disabled:opacity-60"
              >
                {isDeleting ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    <span>Deleting...</span>
                  </>
                ) : (
                  <span>Delete Category</span>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
