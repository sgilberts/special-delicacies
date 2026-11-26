import { useState, useEffect, useCallback } from "react";
import {
  FileText,
  Images,
  Upload,
  Plus,
  Trash2,
  CheckCircle,
  AlertCircle,
  RefreshCw,
  Save,
  Image as ImageIcon,
  Sparkles,
  ChevronUp,
  ChevronDown,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import { assetUrl } from "@/lib/assetUrl";
import type { WebsiteContentData, WebsiteGalleryItem, ApiResponse } from "@/types/api";

export default function AdminContentPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploadingIndex, setUploadingIndex] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const [content, setContent] = useState<WebsiteContentData>({
    hero_title: "Special Delicacies",
    hero_subtitle: "Fine Dining & Takeaway Experience",
    hero_announcement: "Authentic culinary craftsmanship, served daily",
    about_story: "",
    about_mission: "",
    opening_hours_days: "Monday - Sunday: 11:00 AM - 10:00 PM",
    restaurant_logo: null,
    favicon_url: null,
    gallery_items: [],
  });

  useEffect(() => {
    document.title = "Website Content | Special Delicacies Admin";
  }, []);

  const fetchContent = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await cachedGet<ApiResponse<WebsiteContentData>>("/admin/content", 15_000);
      if (res.data.success && res.data.data) {
        setContent({
          ...res.data.data,
          gallery_items: res.data.data.gallery_items.map((item, index) => ({
            ...item,
            display_order: item.display_order ?? index,
            is_published: item.is_published ?? true,
            show_in_hero: item.show_in_hero ?? false,
            hero_active: item.hero_active ?? true,
            hero_sort_order: item.hero_sort_order ?? index,
          })),
        });
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to load website content.";
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchContent();
  }, [fetchContent]);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setSuccessMessage(null);

    try {
      const res = await api.put<ApiResponse<WebsiteContentData>>("/admin/content", content);
      if (res.data.success) {
        setSuccessMessage("Website content updated and audited successfully.");
        setContent(res.data.data);
        window.dispatchEvent(new Event("restaurant-content-updated"));
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to update website content.");
    } finally {
      setSaving(false);
    }
  };

  const handleGalleryImageUpload = async (index: number, file: File) => {
    if (file.size > 2 * 1024 * 1024) {
      setError("Gallery image file size must not exceed 2MB.");
      return;
    }

    setUploadingIndex(index);
    setError(null);
    try {
      const formData = new FormData();
      formData.append("image", file);

      const res = await api.post<ApiResponse<{ image_url: string }>>(
        "/admin/content/gallery-image",
        formData,
        { headers: { "Content-Type": "multipart/form-data" } }
      );

      if (res.data.success) {
        const updatedItems = [...content.gallery_items];
        updatedItems[index].image_url = res.data.data.image_url;
        setContent({ ...content, gallery_items: updatedItems });
        setSuccessMessage("Gallery photograph uploaded successfully.");
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to upload gallery image.");
    } finally {
      setUploadingIndex(null);
    }
  };

  const handleAddGalleryItem = () => {
    const newItem: WebsiteGalleryItem = {
      id: Date.now(),
      category: "Food",
      title: "New Showcase Item",
      description: "Description of signature preparation.",
      tint: "rgba(189, 112, 72, 0.15)",
      image_url: null,
      display_order: content.gallery_items.length,
      is_published: true,
      show_in_hero: false,
      hero_active: true,
      hero_sort_order: content.gallery_items.length,
      hero_cta_text: null,
      hero_cta_url: null,
    };
    setContent({ ...content, gallery_items: [...content.gallery_items, newItem] });
  };

  const handleRemoveGalleryItem = (index: number) => {
    const updated = content.gallery_items.filter((_, i) => i !== index);
    setContent({ ...content, gallery_items: updated });
  };

  const updateGalleryItem = (index: number, changes: Partial<WebsiteGalleryItem>) => {
    const updated = [...content.gallery_items];
    updated[index] = { ...updated[index], ...changes };
    setContent({ ...content, gallery_items: updated });
  };

  const moveGalleryItem = (index: number, direction: -1 | 1) => {
    const destination = index + direction;
    if (destination < 0 || destination >= content.gallery_items.length) return;
    const updated = [...content.gallery_items];
    [updated[index], updated[destination]] = [updated[destination], updated[index]];
    setContent({ ...content, gallery_items: updated.map((item, position) => ({ ...item, display_order: position })) });
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-[var(--color-border)]">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Website Content Management
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1">
            Update public homepage hero banners, storytelling copy, and showcase gallery
          </p>
        </div>

        <button
          type="button"
          onClick={() => fetchContent()}
          className="p-2 border border-[var(--color-border)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] rounded-xl transition-colors self-start sm:self-auto"
          title="Refresh content"
        >
          <RefreshCw size={16} />
        </button>
      </div>

      {/* Notifications */}
      {successMessage && (
        <div className="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/50 flex items-center gap-3">
          <CheckCircle className="text-emerald-600 dark:text-emerald-400 shrink-0" size={20} />
          <p className="text-sm text-emerald-800 dark:text-emerald-300 font-medium">
            {successMessage}
          </p>
        </div>
      )}

      {error && (
        <div className="p-4 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/50 flex items-center gap-3">
          <AlertCircle className="text-rose-600 dark:text-rose-400 shrink-0" size={20} />
          <p className="text-sm text-rose-700 dark:text-rose-300">{error}</p>
        </div>
      )}

      {loading ? (
        <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3">
          <RefreshCw size={24} className="animate-spin mx-auto text-[var(--brand-copper)]" />
          <p className="text-sm font-medium">Loading website copy and media...</p>
        </div>
      ) : (
        <form onSubmit={handleSave} className="space-y-6">
          {/* 1. Homepage Hero Section */}
          <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs space-y-4">
            <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
              <Sparkles size={18} className="text-[var(--brand-gold)]" />
              Homepage Hero Banner & Announcement
            </h2>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Hero Headline *
                </label>
                <input
                  type="text"
                  required
                  value={content.hero_title}
                  onChange={(e) => setContent({ ...content, hero_title: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Hero Subtitle *
                </label>
                <input
                  type="text"
                  required
                  value={content.hero_subtitle}
                  onChange={(e) => setContent({ ...content, hero_subtitle: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                Announcement Tagline
              </label>
              <input
                type="text"
                value={content.hero_announcement || ""}
                onChange={(e) => setContent({ ...content, hero_announcement: e.target.value })}
                className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
              />
            </div>
          </div>

          {/* 2. About Story & Mission */}
          <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs space-y-4">
            <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
              <FileText size={18} className="text-[var(--brand-copper)]" />
              About Story & Mission Statements
            </h2>

            <div>
              <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                "Our Story" Narrative
              </label>
              <textarea
                rows={4}
                value={content.about_story || ""}
                onChange={(e) => setContent({ ...content, about_story: e.target.value })}
                className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                Mission & Culinary Philosophy
              </label>
              <textarea
                rows={2}
                value={content.about_mission || ""}
                onChange={(e) => setContent({ ...content, about_mission: e.target.value })}
                className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
              />
            </div>
          </div>

          {/* 3. Showcase Gallery Management */}
          <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
                <Images size={18} className="text-[var(--brand-sage)]" />
                Restaurant Showcase Gallery ({content.gallery_items.length} items)
              </h2>

              <button
                type="button"
                onClick={handleAddGalleryItem}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-[var(--color-surface-secondary)] hover:bg-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] transition-colors"
              >
                <Plus size={14} /> Add Gallery Item
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {content.gallery_items.map((item, idx) => (
                <div
                  key={item.id || idx}
                  className="p-4 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] space-y-3"
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-1">
                      <button type="button" disabled={idx === 0} onClick={() => moveGalleryItem(idx, -1)} aria-label={`Move ${item.title} earlier`} className="rounded p-1 text-[var(--color-text-secondary)] disabled:opacity-30"><ChevronUp size={16} /></button>
                      <button type="button" disabled={idx === content.gallery_items.length - 1} onClick={() => moveGalleryItem(idx, 1)} aria-label={`Move ${item.title} later`} className="rounded p-1 text-[var(--color-text-secondary)] disabled:opacity-30"><ChevronDown size={16} /></button>
                    </div>
                    <select
                      value={item.category}
                      onChange={(e) => {
                        const updated = [...content.gallery_items];
                        updated[idx].category = e.target.value as "Food" | "Restaurant" | "Events";
                        setContent({ ...content, gallery_items: updated });
                      }}
                      className="px-2.5 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-lg text-xs font-semibold text-[var(--color-text)]"
                    >
                      <option value="Food">Food</option>
                      <option value="Restaurant">Restaurant</option>
                      <option value="Events">Events</option>
                    </select>

                    <button
                      type="button"
                      onClick={() => handleRemoveGalleryItem(idx)}
                      className="p-1 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 rounded-lg transition-colors"
                      title="Remove item"
                    >
                      <Trash2 size={16} />
                    </button>
                  </div>

                  <div>
                    <label className="block text-[11px] font-semibold text-[var(--color-text-secondary)] mb-1">
                      Title
                    </label>
                    <input
                      type="text"
                      value={item.title}
                      onChange={(e) => {
                        const updated = [...content.gallery_items];
                        updated[idx].title = e.target.value;
                        setContent({ ...content, gallery_items: updated });
                      }}
                      className="w-full px-3 py-1.5 rounded-lg bg-[var(--color-surface)] border border-[var(--color-border)] text-xs text-[var(--color-text)]"
                    />
                  </div>

                  <div>
                    <label className="block text-[11px] font-semibold text-[var(--color-text-secondary)] mb-1">
                      Description
                    </label>
                    <textarea
                      rows={2}
                      value={item.description}
                      onChange={(e) => {
                        const updated = [...content.gallery_items];
                        updated[idx].description = e.target.value;
                        setContent({ ...content, gallery_items: updated });
                      }}
                      className="w-full px-3 py-1.5 rounded-lg bg-[var(--color-surface)] border border-[var(--color-border)] text-xs text-[var(--color-text)]"
                    />
                  </div>

                  <div className="grid gap-2 border-t border-[var(--color-border)] pt-3 sm:grid-cols-2">
                    <label className="flex items-start gap-2 text-xs font-semibold text-[var(--color-text)]">
                      <input type="checkbox" checked={item.is_published ?? true} onChange={(e) => updateGalleryItem(idx, { is_published: e.target.checked })} />
                      Published in Moments &amp; Flavours
                    </label>
                    <label className="flex items-start gap-2 text-xs font-semibold text-[var(--color-text)]">
                      <input type="checkbox" checked={item.show_in_hero ?? false} onChange={(e) => updateGalleryItem(idx, { show_in_hero: e.target.checked })} />
                      Select for homepage hero
                    </label>
                    {item.show_in_hero && <>
                      <label className="flex items-start gap-2 text-xs font-semibold text-[var(--color-text)]">
                        <input type="checkbox" checked={item.hero_active ?? true} onChange={(e) => updateGalleryItem(idx, { hero_active: e.target.checked })} />
                        Hero slide active
                      </label>
                      <label className="text-xs font-semibold text-[var(--color-text-secondary)]">Hero order
                        <input type="number" min={0} value={item.hero_sort_order ?? idx} onChange={(e) => updateGalleryItem(idx, { hero_sort_order: Number(e.target.value) || 0 })} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1.5 text-[var(--color-text)]" />
                      </label>
                      <label className="text-xs font-semibold text-[var(--color-text-secondary)]">Optional button label
                        <input type="text" maxLength={60} value={item.hero_cta_text ?? ""} onChange={(e) => updateGalleryItem(idx, { hero_cta_text: e.target.value || null })} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1.5 text-[var(--color-text)]" />
                      </label>
                      <label className="text-xs font-semibold text-[var(--color-text-secondary)]">Optional button path (same website)
                        <input type="text" maxLength={255} placeholder="/menu" value={item.hero_cta_url ?? ""} onChange={(e) => updateGalleryItem(idx, { hero_cta_url: e.target.value || null })} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1.5 text-[var(--color-text)]" />
                      </label>
                    </>}
                  </div>

                  <div className="flex items-center justify-between pt-2 border-t border-[var(--color-border)]/60">
                    <div className="flex items-center gap-2">
                      {item.image_url ? (
                        <img
                          src={assetUrl(item.image_url) ?? undefined}
                          alt={item.title}
                          className="w-10 h-10 object-cover rounded-lg border border-[var(--color-border)]"
                        />
                      ) : (
                        <div className="w-10 h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] flex items-center justify-center text-[var(--color-text-secondary)]">
                          <ImageIcon size={18} />
                        </div>
                      )}
                      <span className="text-[10px] text-[var(--color-text-secondary)]">
                        {item.image_url ? "Custom Photo" : "Brand Tint Placeholder"}
                      </span>
                    </div>

                    <label className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-surface)] text-[11px] font-semibold text-[var(--color-text)] cursor-pointer transition-colors">
                      <Upload size={12} />
                      {uploadingIndex === idx ? "Uploading..." : "Upload Photo"}
                      <input
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        onChange={(e) => {
                          const file = e.target.files?.[0];
                          if (file) handleGalleryImageUpload(idx, file);
                        }}
                        disabled={uploadingIndex === idx}
                        className="hidden"
                      />
                    </label>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Submit */}
          <div className="flex items-center justify-end">
            <button
              type="submit"
              disabled={saving}
              className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-[var(--brand-copper)] hover:bg-[var(--brand-copper)]/90 text-white font-semibold text-sm shadow-xs transition-colors disabled:opacity-50"
            >
              <Save size={16} />
              {saving ? "Saving Changes..." : "Publish Website Content"}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
