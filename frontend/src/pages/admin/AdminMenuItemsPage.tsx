import { useEffect, useState, useCallback, useRef } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  Utensils,
  Plus,
  Search,
  Edit2,
  Trash2,
  X,
  Loader2,
  AlertTriangle,
  RefreshCw,
  Power,
  Upload,
  ChevronLeft,
  ChevronRight,
  ImageIcon,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import { assetUrl } from "@/lib/assetUrl";
import { useToast } from "@/components/ui/useToast";
import type { ApiResponse, MenuCategory, MenuItem, PaginatedResponse } from "@/types/api";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ErrorState from "@/components/public/ErrorState";

const itemSchema = z.object({
  name: z.string().min(1, "Dish name is required").max(150, "Name must not exceed 150 characters"),
  slug: z.string().max(150, "Slug must not exceed 150 characters").optional().or(z.literal("")),
  category_id: z.number().min(1, "Please select a menu category"),
  price: z
    .string()
    .min(1, "Price is required")
    .regex(/^\d+(\.\d{1,2})?$/, "Enter a valid positive price with up to 2 decimal places")
    .refine((val) => parseFloat(val) > 0, "Price must be greater than zero"),
  description: z.string().max(2000, "Description must not exceed 2000 characters").optional().or(z.literal("")),
  sort_order: z.number().min(0, "Order must be 0 or greater"),
  is_available: z.boolean(),
});

type ItemFormValues = z.infer<typeof itemSchema>;

function formatPriceGhs(price: string): string {
  const val = parseFloat(price);
  return isNaN(val) ? `GH₵${price}` : `GH₵${val.toFixed(2)}`;
}

export default function AdminMenuItemsPage() {
  const { success, error: toastError } = useToast();

  const [items, setItems] = useState<MenuItem[]>([]);
  const [categories, setCategories] = useState<MenuCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Filters & Pagination
  const [search, setSearch] = useState("");
  const [selectedCategory, setSelectedCategory] = useState<string>("all");
  const [availabilityFilter, setAvailabilityFilter] = useState<string>("all");
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalItemsCount, setTotalItemsCount] = useState(0);

  // Modal states
  const [isFormOpen, setIsFormOpen] = useState(false);
  const [editingItem, setEditingItem] = useState<MenuItem | null>(null);
  const [deletingItem, setDeletingItem] = useState<MenuItem | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  // Image upload state
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<ItemFormValues>({
    resolver: zodResolver(itemSchema),
    defaultValues: {
      name: "",
      slug: "",
      category_id: 0,
      price: "",
      description: "",
      sort_order: 0,
      is_available: true,
    },
  });

  // Fetch categories for select dropdown
  const fetchCategories = useCallback(async () => {
    try {
      const resp = await cachedGet<ApiResponse<MenuCategory[]>>("/admin/menu/categories", 15_000);
      if (resp.data?.success && Array.isArray(resp.data.data)) {
        setCategories(resp.data.data);
      }
    } catch {
      // non-blocking
    }
  }, []);

  // Fetch Menu Items
  const fetchItems = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      params.append("page", page.toString());
      params.append("per_page", "12");

      if (search.trim()) {
        params.append("search", search.trim());
      }
      if (selectedCategory !== "all") {
        params.append("category_id", selectedCategory);
      }
      if (availabilityFilter !== "all") {
        params.append("is_available", availabilityFilter === "available" ? "1" : "0");
      }

      const resp = await cachedGet<PaginatedResponse<MenuItem>>(`/admin/menu/items?${params.toString()}`, 15_000);
      if (resp.data?.success && Array.isArray(resp.data.data)) {
        setItems(resp.data.data);
        if (resp.data.meta) {
          setTotalPages(resp.data.meta.last_page);
          setTotalItemsCount(resp.data.meta.total);
        }
      } else {
        setError("Failed to load menu items.");
      }
    } catch {
      setError("Unable to connect to the menu items service.");
    } finally {
      setLoading(false);
    }
  }, [page, search, selectedCategory, availabilityFilter]);

  useEffect(() => {
    document.title = "Menu Items | Menu Management";
    fetchCategories();
  }, [fetchCategories]);

  useEffect(() => {
    fetchItems();
  }, [fetchItems]);

  // Handle Image File Selection
  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
      toastError("Please select a valid image (JPEG, PNG, or WebP).");
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      toastError("Image size must be 5MB or less.");
      return;
    }

    setSelectedFile(file);
    const reader = new FileReader();
    reader.onload = () => {
      setImagePreview(reader.result as string);
    };
    reader.readAsDataURL(file);
  };

  // Open Create Modal
  const openCreateModal = () => {
    reset({
      name: "",
      slug: "",
      category_id: categories[0]?.id || 0,
      price: "",
      description: "",
      sort_order: items.length > 0 ? Math.max(...items.map((i) => i.sort_order)) + 1 : 0,
      is_available: true,
    });
    setSelectedFile(null);
    setImagePreview(null);
    setEditingItem(null);
    setIsFormOpen(true);
  };

  // Open Edit Modal
  const openEditModal = (item: MenuItem) => {
    reset({
      name: item.name,
      slug: item.slug,
      category_id: item.category_id,
      price: item.price,
      description: item.description || "",
      sort_order: item.sort_order,
      is_available: item.is_available,
    });
    setSelectedFile(null);
    setImagePreview(item.image_path || null);
    setEditingItem(item);
    setIsFormOpen(true);
  };

  // Submit Form (Create or Update with FormData)
  const onSubmit = async (values: ItemFormValues) => {
    try {
      const formData = new FormData();
      formData.append("name", values.name);
      formData.append("category_id", values.category_id.toString());
      formData.append("price", values.price);
      formData.append("sort_order", values.sort_order.toString());
      formData.append("is_available", values.is_available ? "1" : "0");

      if (values.slug) {
        formData.append("slug", values.slug);
      }
      if (values.description) {
        formData.append("description", values.description);
      }
      if (selectedFile) {
        formData.append("image", selectedFile);
      }

      if (editingItem) {
        // Send POST to /admin/menu/items/{id} for multipart update
        const resp = await api.post<ApiResponse<MenuItem>>(
          `/admin/menu/items/${editingItem.id}`,
          formData
        );
        if (resp.data?.success) {
          success(`Menu item '${values.name}' updated successfully.`);
          setIsFormOpen(false);
          setEditingItem(null);
          fetchItems();
        }
      } else {
        const resp = await api.post<ApiResponse<MenuItem>>("/admin/menu/items", formData);
        if (resp.data?.success) {
          success(`Menu item '${values.name}' created successfully.`);
          setIsFormOpen(false);
          fetchItems();
        }
      }
    } catch (err: unknown) {
      if (err && typeof err === "object" && "response" in err) {
        const axErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
        const msg =
          axErr.response?.data?.message ||
          (axErr.response?.data?.errors
            ? Object.values(axErr.response.data.errors).flat().join(" ")
            : "Failed to save menu item.");
        toastError(msg);
      } else {
        toastError("A network error occurred while saving the item.");
      }
    }
  };

  // Quick Toggle Availability
  const handleToggleAvailability = async (item: MenuItem) => {
    const nextAvailability = !item.is_available;
    try {
      const resp = await api.patch<ApiResponse<MenuItem>>(
        `/admin/menu/items/${item.id}/availability`,
        { is_available: nextAvailability }
      );
      if (resp.data?.success) {
        success(
          `'${item.name}' is now ${nextAvailability ? "Available (Live)" : "Unavailable (Hidden)"}.`
        );
        setItems((prev) =>
          prev.map((i) => (i.id === item.id ? { ...i, is_available: nextAvailability } : i))
        );
      }
    } catch {
      toastError(`Failed to update availability for '${item.name}'.`);
    }
  };

  // Remove photograph
  const handleRemoveImage = async () => {
    if (!editingItem) {
      setSelectedFile(null);
      setImagePreview(null);
      return;
    }

    try {
      const resp = await api.delete<ApiResponse<MenuItem>>(
        `/admin/menu/items/${editingItem.id}/image`
      );
      if (resp.data?.success) {
        success("Photograph removed successfully.");
        setSelectedFile(null);
        setImagePreview(null);
        setEditingItem(resp.data.data);
        fetchItems();
      }
    } catch {
      toastError("Failed to remove photograph.");
    }
  };

  // Delete item
  const confirmDelete = async () => {
    if (!deletingItem) return;
    setIsDeleting(true);
    try {
      const resp = await api.delete<ApiResponse<unknown>>(`/admin/menu/items/${deletingItem.id}`);
      if (resp.data?.success) {
        success(`Dish '${deletingItem.name}' removed successfully.`);
        setDeletingItem(null);
        fetchItems();
      }
    } catch (err: unknown) {
      if (err && typeof err === "object" && "response" in err) {
        const axErr = err as { response?: { data?: { message?: string } } };
        toastError(axErr.response?.data?.message || "Failed to delete item.");
      } else {
        toastError("Server error while deleting item.");
      }
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <div className="space-y-6 animate-in fade-in duration-200">
      {/* 1. HEADER */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[var(--color-border)] pb-5">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Menu Items
          </h1>
          <p className="text-xs sm:text-sm text-[var(--color-text-secondary)] mt-1">
            Configure dishes, Ghana Cedi pricing (GH₵), food photographs, and live availability.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={fetchItems}
            disabled={loading}
            className="btn-ghost flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold min-h-[44px]"
            title="Refresh menu items"
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
            <span>Add Menu Item</span>
          </button>
        </div>
      </div>

      {/* 2. SEARCH & FILTER CONTROLS */}
      <div className="grid grid-cols-1 sm:grid-cols-12 gap-3">
        {/* Search Input */}
        <div className="sm:col-span-5 relative">
          <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--color-text-secondary)]" />
          <input
            type="text"
            placeholder="Search dish by name or description..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="w-full pl-10 pr-4 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] min-h-[44px]"
          />
        </div>

        {/* Category Filter */}
        <div className="sm:col-span-4">
          <select
            value={selectedCategory}
            onChange={(e) => {
              setSelectedCategory(e.target.value);
              setPage(1);
            }}
            className="w-full px-3.5 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] min-h-[44px]"
          >
            <option value="all">All Categories ({categories.length})</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </div>

        {/* Availability Filter */}
        <div className="sm:col-span-3">
          <select
            value={availabilityFilter}
            onChange={(e) => {
              setAvailabilityFilter(e.target.value);
              setPage(1);
            }}
            className="w-full px-3.5 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] min-h-[44px]"
          >
            <option value="all">All Availability</option>
            <option value="available">Available (Live)</option>
            <option value="unavailable">Unavailable (Hidden)</option>
          </select>
        </div>
      </div>

      {/* 3. LOADING / ERROR / EMPTY STATES */}
      {loading && !items.length && (
        <div className="py-12">
          <LoadingSpinner message="Loading menu items..." />
        </div>
      )}

      {error && !items.length && (
        <div className="py-10">
          <ErrorState message={error} onRetry={fetchItems} />
        </div>
      )}

      {!loading && !error && items.length === 0 && (
        <div className="bg-[var(--color-surface)] border border-dashed border-[var(--color-border)] rounded-2xl p-10 text-center">
          <Utensils className="w-10 h-10 mx-auto text-[var(--color-text-secondary)] mb-3 opacity-60" />
          <h3 className="text-base font-bold text-[var(--color-text)]">
            {search || selectedCategory !== "all" || availabilityFilter !== "all"
              ? "No dishes match your filters"
              : "No menu items configured"}
          </h3>
          <p className="text-xs text-[var(--color-text-secondary)] mt-1 max-w-sm mx-auto">
            {search || selectedCategory !== "all" || availabilityFilter !== "all"
              ? "Try resetting filters or adjusting search keywords."
              : "Create dishes with prices in GH₵ and optional photographs to populate the menu."}
          </p>
          <div className="mt-4">
            <button
              type="button"
              onClick={openCreateModal}
              className="btn-accent inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold min-h-[44px]"
            >
              <Plus className="w-4 h-4" />
              <span>Add Menu Item</span>
            </button>
          </div>
        </div>
      )}

      {/* 4. ITEMS LISTING */}
      {items.length > 0 && (
        <>
          {/* Desktop & Tablet Table */}
          <div className="hidden md:block bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl overflow-hidden shadow-xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-[var(--color-surface-secondary)]/60 text-xs uppercase tracking-wider text-[var(--color-text-secondary)] border-b border-[var(--color-border)]">
                  <tr>
                    <th scope="col" className="px-4 py-3.5 font-bold">Photo</th>
                    <th scope="col" className="px-4 py-3.5 font-bold">Dish Name</th>
                    <th scope="col" className="px-4 py-3.5 font-bold">Category</th>
                    <th scope="col" className="px-4 py-3.5 font-bold">Price (GH₵)</th>
                    <th scope="col" className="px-4 py-3.5 font-bold text-center">Availability</th>
                    <th scope="col" className="px-4 py-3.5 font-bold text-center">Order</th>
                    <th scope="col" className="px-4 py-3.5 font-bold text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[var(--color-border)]">
                  {items.map((item) => (
                    <tr
                      key={item.id}
                      className="hover:bg-[var(--color-surface-secondary)]/30 transition-colors"
                    >
                      {/* Photo Thumbnail */}
                      <td className="px-4 py-3.5">
                        <div className="w-12 h-12 rounded-xl bg-[var(--color-surface-secondary)] overflow-hidden shrink-0 flex items-center justify-center border border-[var(--color-border)]">
                          {item.image_path ? (
                            <img
                              src={assetUrl(item.image_path) ?? undefined}
                              alt={item.name}
                              className="w-full h-full object-cover"
                            />
                          ) : (
                            <ImageIcon className="w-5 h-5 text-[var(--color-text-secondary)] opacity-60" />
                          )}
                        </div>
                      </td>

                      {/* Dish Name & Description */}
                      <td className="px-4 py-3.5">
                        <div className="font-bold text-[var(--color-text)] leading-snug">
                          {item.name}
                        </div>
                        <div className="text-xs text-[var(--color-text-secondary)] line-clamp-1 max-w-sm mt-0.5">
                          {item.description || <span className="italic opacity-60">No description</span>}
                        </div>
                      </td>

                      {/* Category */}
                      <td className="px-4 py-3.5">
                        <span className="inline-block px-2.5 py-1 rounded-full text-xs font-semibold bg-[var(--color-surface-secondary)] text-[var(--color-text)]">
                          {item.category?.name || "Uncategorized"}
                        </span>
                      </td>

                      {/* Price in GH₵ */}
                      <td className="px-4 py-3.5 font-extrabold text-[var(--color-accent)] font-mono text-sm">
                        {formatPriceGhs(item.price)}
                      </td>

                      {/* Availability Toggle */}
                      <td className="px-4 py-3.5 text-center">
                        <button
                          type="button"
                          onClick={() => handleToggleAvailability(item)}
                          className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all min-h-[32px] ${
                            item.is_available
                              ? "bg-[var(--color-success)]/15 text-[var(--color-success)] hover:bg-[var(--color-success)]/25"
                              : "bg-[var(--color-danger)]/15 text-[var(--color-danger)] hover:bg-[var(--color-danger)]/25"
                          }`}
                          title={`Click to mark as ${item.is_available ? "unavailable" : "available"}`}
                        >
                          <Power className="w-3 h-3" />
                          <span>{item.is_available ? "Available" : "Unavailable"}</span>
                        </button>
                      </td>

                      {/* Display Order */}
                      <td className="px-4 py-3.5 text-center font-mono text-xs text-[var(--color-text-secondary)]">
                        #{item.sort_order}
                      </td>

                      {/* Actions */}
                      <td className="px-4 py-3.5 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            type="button"
                            onClick={() => openEditModal(item)}
                            className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center"
                            title="Edit Menu Item"
                            aria-label={`Edit ${item.name}`}
                          >
                            <Edit2 className="w-4 h-4" />
                          </button>
                          <button
                            type="button"
                            onClick={() => setDeletingItem(item)}
                            className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-danger)] hover:bg-[var(--color-surface-secondary)] transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center"
                            title="Delete Menu Item"
                            aria-label={`Delete ${item.name}`}
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

          {/* Mobile Card Layout (No horizontal table squashing) */}
          <div className="md:hidden space-y-3">
            {items.map((item) => (
              <div
                key={item.id}
                className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-4 shadow-xs space-y-3"
              >
                <div className="flex items-start gap-3">
                  <div className="w-16 h-16 rounded-xl bg-[var(--color-surface-secondary)] overflow-hidden shrink-0 flex items-center justify-center border border-[var(--color-border)]">
                    {item.image_path ? (
                      <img
                        src={assetUrl(item.image_path) ?? undefined}
                        alt={item.name}
                        className="w-full h-full object-cover"
                      />
                    ) : (
                      <ImageIcon className="w-6 h-6 text-[var(--color-text-secondary)] opacity-60" />
                    )}
                  </div>

                  <div className="flex-1 min-w-0">
                    <div className="flex items-start justify-between gap-2">
                      <h3 className="text-base font-bold text-[var(--color-text)] leading-snug truncate">
                        {item.name}
                      </h3>
                      <span className="font-extrabold text-[var(--color-accent)] font-mono text-sm shrink-0">
                        {formatPriceGhs(item.price)}
                      </span>
                    </div>

                    <div className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)] mt-1">
                      <span className="bg-[var(--color-surface-secondary)] px-2 py-0.5 rounded-md font-semibold">
                        {item.category?.name || "General"}
                      </span>
                      <span>Order #{item.sort_order}</span>
                    </div>
                  </div>
                </div>

                {item.description && (
                  <p className="text-xs text-[var(--color-text-secondary)] line-clamp-2 leading-relaxed">
                    {item.description}
                  </p>
                )}

                <div className="pt-2 border-t border-[var(--color-border)] flex items-center justify-between">
                  <button
                    type="button"
                    onClick={() => handleToggleAvailability(item)}
                    className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all min-h-[38px] ${
                      item.is_available
                        ? "bg-[var(--color-success)]/15 text-[var(--color-success)]"
                        : "bg-[var(--color-danger)]/15 text-[var(--color-danger)]"
                    }`}
                  >
                    <Power className="w-3 h-3" />
                    <span>{item.is_available ? "Available" : "Unavailable"}</span>
                  </button>

                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      onClick={() => openEditModal(item)}
                      className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] min-h-[44px] flex items-center gap-1"
                    >
                      <Edit2 className="w-3.5 h-3.5" />
                      <span>Edit</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => setDeletingItem(item)}
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

          {/* 5. PAGINATION CONTROLS */}
          {totalPages > 1 && (
            <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-[var(--color-border)] text-xs text-[var(--color-text-secondary)]">
              <div>
                Showing page <strong className="text-[var(--color-text)]">{page}</strong> of{" "}
                <strong className="text-[var(--color-text)]">{totalPages}</strong> ({totalItemsCount}{" "}
                total dishes)
              </div>

              <div className="flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={() => setPage((p) => Math.max(p - 1, 1))}
                  disabled={page <= 1}
                  className="btn-ghost px-3 py-2 rounded-xl flex items-center gap-1 min-h-[44px] disabled:opacity-40"
                >
                  <ChevronLeft className="w-4 h-4" />
                  <span>Previous</span>
                </button>

                <div className="px-3 py-2 font-mono font-bold text-[var(--color-text)]">
                  {page} / {totalPages}
                </div>

                <button
                  type="button"
                  onClick={() => setPage((p) => Math.min(p + 1, totalPages))}
                  disabled={page >= totalPages}
                  className="btn-ghost px-3 py-2 rounded-xl flex items-center gap-1 min-h-[44px] disabled:opacity-40"
                >
                  <span>Next</span>
                  <ChevronRight className="w-4 h-4" />
                </button>
              </div>
            </div>
          )}
        </>
      )}

      {/* 6. CREATE / EDIT MODAL */}
      {isFormOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="dish-modal-title"
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs overflow-y-auto animate-in fade-in"
        >
          <div className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl w-full max-w-xl p-6 shadow-2xl space-y-5 my-8 animate-in zoom-in-95">
            <div className="flex items-center justify-between border-b border-[var(--color-border)] pb-3">
              <h2 id="dish-modal-title" className="text-lg font-bold text-[var(--color-text)]">
                {editingItem ? `Edit '${editingItem.name}'` : "New Menu Dish"}
              </h2>
              <button
                type="button"
                onClick={() => setIsFormOpen(false)}
                className="p-1 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text)] min-h-[44px] min-w-[44px] flex items-center justify-center"
                aria-label="Close dialog"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
              {/* Dish Name */}
              <div>
                <label
                  htmlFor="item-name"
                  className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1"
                >
                  Dish Name *
                </label>
                <input
                  id="item-name"
                  type="text"
                  placeholder="e.g. Grilled Tilapia with Banku"
                  {...register("name")}
                  className="w-full px-3.5 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] min-h-[44px]"
                />
                {errors.name && (
                  <p className="mt-1 text-xs text-[var(--color-danger)]">{errors.name.message}</p>
                )}
              </div>

              {/* Category & Price in GH₵ */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label
                    htmlFor="item-cat"
                    className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1"
                  >
                    Category *
                  </label>
                  <select
                    id="item-cat"
                    {...register("category_id", { valueAsNumber: true })}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] min-h-[44px]"
                  >
                    <option value="">Select Category...</option>
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                  {errors.category_id && (
                    <p className="mt-1 text-xs text-[var(--color-danger)]">
                      {errors.category_id.message}
                    </p>
                  )}
                </div>

                <div>
                  <label
                    htmlFor="item-price"
                    className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1"
                  >
                    Price in Ghana Cedis (GH₵) *
                  </label>
                  <div className="relative">
                    <span className="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-xs text-[var(--color-text-secondary)]">
                      GH₵
                    </span>
                    <input
                      id="item-price"
                      type="text"
                      step="0.01"
                      placeholder="45.00"
                      {...register("price")}
                      className="w-full pl-12 pr-4 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] font-mono min-h-[44px]"
                    />
                  </div>
                  {errors.price && (
                    <p className="mt-1 text-xs text-[var(--color-danger)]">{errors.price.message}</p>
                  )}
                </div>
              </div>

              {/* Description */}
              <div>
                <label
                  htmlFor="item-desc"
                  className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1"
                >
                  Description
                </label>
                <textarea
                  id="item-desc"
                  rows={3}
                  placeholder="Ingredients, culinary details, spicy level, or sides..."
                  {...register("description")}
                  className="w-full px-3.5 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                />
                {errors.description && (
                  <p className="mt-1 text-xs text-[var(--color-danger)]">
                    {errors.description.message}
                  </p>
                )}
              </div>

              {/* Photograph Upload & Preview */}
              <div>
                <span className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-2">
                  Food Photograph (JPEG, PNG, WebP &bull; Max 5MB)
                </span>

                <div className="flex flex-col sm:flex-row items-center gap-4 p-4 border border-[var(--color-border)] rounded-2xl bg-[var(--color-surface-secondary)]/40">
                  {/* Image Preview Area */}
                  <div className="w-24 h-24 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border)] overflow-hidden shrink-0 flex items-center justify-center relative shadow-xs">
                    {imagePreview ? (
                      <img
                        src={assetUrl(imagePreview) ?? undefined}
                        alt="Preview"
                        className="w-full h-full object-cover"
                      />
                    ) : (
                      <ImageIcon className="w-8 h-8 text-[var(--color-text-secondary)] opacity-50" />
                    )}
                  </div>

                  {/* Upload Controls */}
                  <div className="flex-1 space-y-2 text-center sm:text-left">
                    <input
                      ref={fileInputRef}
                      type="file"
                      accept="image/jpeg,image/png,image/webp"
                      onChange={handleFileChange}
                      className="hidden"
                      id="food-photo-upload"
                    />

                    <div className="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                      <button
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        className="btn-ghost flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold border border-[var(--color-border)] min-h-[44px]"
                      >
                        <Upload className="w-3.5 h-3.5" />
                        <span>{imagePreview ? "Change Photo" : "Upload Photograph"}</span>
                      </button>

                      {imagePreview && (
                        <button
                          type="button"
                          onClick={handleRemoveImage}
                          className="px-3 py-2 rounded-xl text-xs font-semibold text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 min-h-[44px]"
                        >
                          Remove Photo
                        </button>
                      )}
                    </div>

                    <p className="text-[11px] text-[var(--color-text-secondary)]">
                      {selectedFile
                        ? `Selected: ${selectedFile.name} (${(selectedFile.size / 1024).toFixed(0)} KB)`
                        : "High-resolution photos increase customer appetite on the public website."}
                    </p>
                  </div>
                </div>
              </div>

              {/* Display Order & Availability Switch */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label
                    htmlFor="item-sort"
                    className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1"
                  >
                    Display Order
                  </label>
                  <input
                    id="item-sort"
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
                      {...register("is_available")}
                      className="w-4 h-4 rounded text-[var(--color-accent)] focus:ring-[var(--color-accent)]"
                    />
                    <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text)]">
                      Available (Orderable)
                    </span>
                  </label>
                </div>
              </div>

              {/* Actions */}
              <div className="flex items-center justify-end gap-2 pt-4 border-t border-[var(--color-border)]">
                <button
                  type="button"
                  onClick={() => setIsFormOpen(false)}
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
                    <span>{editingItem ? "Update Dish" : "Create Dish"}</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 7. SAFE DELETION DIALOG */}
      {deletingItem && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="delete-item-title"
          className="action-modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
        >
          <div className="action-modal-panel bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl w-full max-w-md p-6 shadow-2xl space-y-4">
            <div className="flex items-center gap-3 text-[var(--color-danger)]">
              <div className="w-10 h-10 rounded-full bg-[var(--color-danger)]/15 flex items-center justify-center shrink-0">
                <AlertTriangle className="w-5 h-5 text-[var(--color-danger)]" />
              </div>
              <h2 id="delete-item-title" className="text-base font-bold text-[var(--color-text)]">
                Delete Dish &lsquo;{deletingItem.name}&rsquo;?
              </h2>
            </div>

            <p className="text-xs sm:text-sm text-[var(--color-text-secondary)] leading-relaxed">
              Are you sure you want to delete this menu dish? If it has an uploaded photograph, the
              image will also be permanently removed from disk.
            </p>

            <div className="flex items-center justify-end gap-2 pt-3 border-t border-[var(--color-border)]">
              <button
                type="button"
                onClick={() => setDeletingItem(null)}
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
                  <span>Delete Dish</span>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
