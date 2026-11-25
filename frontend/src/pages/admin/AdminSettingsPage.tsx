import { useState, useEffect, useCallback } from "react";
import {
  Building,
  Clock,
  Upload,
  CheckCircle,
  AlertCircle,
  RefreshCw,
  Save,
  Image as ImageIcon,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import type { ApiResponse } from "@/types/api";
import { applyFavicon, assetUrl } from "@/lib/assetUrl";
import PaymentGatewayManagement from "@/components/admin/PaymentGatewayManagement";

interface SettingItem {
  id: number;
  key: string;
  value: string | null;
  group: string;
}

export default function AdminSettingsPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploadingLogo, setUploadingLogo] = useState(false);
  const [uploadingFavicon, setUploadingFavicon] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Form values
  const [settings, setSettings] = useState({
    restaurant_name: "Special Delicacies",
    restaurant_phone: "+233 30 123 4567",
    restaurant_email: "contact@special-delicacies.local",
    restaurant_address: "100 Gourmet Boulevard, Culinary Quarter",
    operating_hours: "11:00 - 22:00",
    opening_hours_days: "Monday - Sunday: 11:00 AM - 10:00 PM",
    dine_in_enabled: "true",
    delivery_enabled: "true",
    restaurant_logo: null as string | null,
    favicon_url: null as string | null,
  });

  useEffect(() => {
    document.title = "Restaurant Settings | Special Delicacies Admin";
  }, []);

  const fetchSettings = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await cachedGet<ApiResponse<SettingItem[]>>("/admin/settings", 15_000);
      if (res.data.success && Array.isArray(res.data.data)) {
        const map: Record<string, string | null> = {};
        res.data.data.forEach((s) => {
          map[s.key] = s.value;
        });

        setSettings((prev) => ({
          ...prev,
          restaurant_name: map.restaurant_name ?? prev.restaurant_name,
          restaurant_phone: map.restaurant_phone ?? prev.restaurant_phone,
          restaurant_email: map.restaurant_email ?? prev.restaurant_email,
          restaurant_address: map.restaurant_address ?? prev.restaurant_address,
          operating_hours: map.operating_hours ?? prev.operating_hours,
          opening_hours_days: map.opening_hours_days ?? prev.opening_hours_days,
          dine_in_enabled: map.dine_in_enabled ?? "true",
          delivery_enabled: map.delivery_enabled ?? "true",
          restaurant_logo: map.restaurant_logo ?? null,
          favicon_url: map.favicon_url ?? null,
        }));
      }
    } catch (err: unknown) {
        const msg = (err as any)?.response?.data?.message || (err instanceof Error ? err.message : "Failed to load restaurant settings.");
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchSettings();
  }, [fetchSettings]);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setSuccessMessage(null);

    try {
      const payload = {
        settings: [
          { key: "restaurant_name", value: settings.restaurant_name, group: "general" },
          { key: "restaurant_phone", value: settings.restaurant_phone, group: "general" },
          { key: "restaurant_email", value: settings.restaurant_email, group: "general" },
          { key: "restaurant_address", value: settings.restaurant_address, group: "general" },
          { key: "operating_hours", value: settings.operating_hours, group: "operations" },
          { key: "opening_hours_days", value: settings.opening_hours_days, group: "content" },
          { key: "dine_in_enabled", value: settings.dine_in_enabled, group: "operations" },
          { key: "delivery_enabled", value: settings.delivery_enabled, group: "operations" },
          { key: "currency", value: "GHS", group: "general" },
          { key: "currency_symbol", value: "GH₵", group: "general" },
        ],
      };

      const res = await api.put<ApiResponse<SettingItem[]>>("/admin/settings", payload);
      if (res.data.success) {
        setSuccessMessage("Restaurant settings saved and audited successfully.");
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to update restaurant settings.");
    } finally {
      setSaving(false);
    }
  };

  const handleLogoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
      setError("Logo file size must not exceed 2MB.");
      return;
    }

    setUploadingLogo(true);
    setError(null);
    try {
      const formData = new FormData();
      formData.append("logo", file);

      const res = await api.post<ApiResponse<{ logo_url: string }>>("/admin/content/logo", formData);

      if (res.data.success) {
        setSettings((prev) => ({ ...prev, restaurant_logo: res.data.data.logo_url }));
        window.dispatchEvent(new Event("restaurant-content-updated"));
        setSuccessMessage("Restaurant logo uploaded and updated successfully.");
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to upload logo.");
    } finally {
      setUploadingLogo(false);
    }
  };

  const handleFaviconUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const acceptedTypes = ["image/x-icon", "image/vnd.microsoft.icon", "image/png", "image/svg+xml"];
    if (!acceptedTypes.includes(file.type) && !/\.(ico|png|svg)$/i.test(file.name)) {
      setError("Choose an ICO, PNG, or SVG image for the favicon.");
      e.target.value = "";
      return;
    }
    if (file.size > 1024 * 1024) {
      setError("Favicon file size must not exceed 1MB.");
      e.target.value = "";
      return;
    }
    setUploadingFavicon(true);
    setError(null);
    try {
      const formData = new FormData();
      formData.append("favicon", file);
      const res = await api.post<ApiResponse<{ favicon_url: string }>>("/admin/content/favicon", formData);
      if (res.data.success) {
        setSettings((prev) => ({ ...prev, favicon_url: res.data.data.favicon_url }));
        applyFavicon(res.data.data.favicon_url);
        window.dispatchEvent(new Event("restaurant-content-updated"));
        setSuccessMessage("Favicon uploaded and applied to the public website.");
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to upload favicon.");
    } finally {
      setUploadingFavicon(false);
      e.target.value = "";
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-[var(--color-border)]">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Restaurant Settings
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1">
            Core restaurant identity, operating hours, delivery controls, and currency settings
          </p>
        </div>

        <button
          type="button"
          onClick={() => fetchSettings()}
          className="p-2 border border-[var(--color-border)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] rounded-xl transition-colors self-start sm:self-auto"
          title="Refresh settings"
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
          <p className="text-sm font-medium">Loading restaurant configuration...</p>
        </div>
      ) : (
        <form onSubmit={handleSave} className="space-y-6">
          {/* 1. General Identity & Location */}
          <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs space-y-4">
            <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
              <Building size={18} className="text-[var(--brand-copper)]" />
              Restaurant Identity & Contact Information
            </h2>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Restaurant Name *
                </label>
                <input
                  type="text"
                  required
                  value={settings.restaurant_name}
                  onChange={(e) => setSettings({ ...settings, restaurant_name: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Contact Phone *
                </label>
                <input
                  type="text"
                  required
                  value={settings.restaurant_phone}
                  onChange={(e) => setSettings({ ...settings, restaurant_phone: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Contact Email *
                </label>
                <input
                  type="email"
                  required
                  value={settings.restaurant_email}
                  onChange={(e) => setSettings({ ...settings, restaurant_email: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Physical Location Address *
                </label>
                <input
                  type="text"
                  required
                  value={settings.restaurant_address}
                  onChange={(e) => setSettings({ ...settings, restaurant_address: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
              </div>
            </div>

            {/* Logo Upload */}
            <div className="pt-2 border-t border-[var(--color-border)] flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div className="flex items-center gap-3">
                {settings.restaurant_logo ? (
                  <img
                    src={assetUrl(settings.restaurant_logo) ?? undefined}
                    alt="Logo preview"
                    className="w-14 h-14 object-contain rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] p-1"
                  />
                ) : (
                  <div className="w-14 h-14 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] flex items-center justify-center text-[var(--color-text-secondary)]">
                    <ImageIcon size={24} />
                  </div>
                )}
                <div>
                  <p className="text-xs font-bold text-[var(--color-text)]">Restaurant Logo</p>
                  <p className="text-[11px] text-[var(--color-text-secondary)]">
                    PNG, JPG, SVG, or WEBP (max 2MB)
                  </p>
                </div>
              </div>

              <label className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)] text-xs font-semibold text-[var(--color-text)] cursor-pointer transition-colors self-start sm:self-auto">
                <Upload size={14} />
                {uploadingLogo ? "Uploading..." : "Upload Logo"}
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp,image/svg+xml"
                  onChange={handleLogoUpload}
                  disabled={uploadingLogo}
                  className="hidden"
                />
              </label>
            </div>

            <div className="pt-3 border-t border-[var(--color-border)] flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div className="flex items-center gap-3">
                {settings.favicon_url ? <img src={assetUrl(settings.favicon_url) ?? undefined} alt="Favicon preview" className="h-10 w-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-background)] object-contain p-1" /> : <div className="h-10 w-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-background)] flex items-center justify-center text-[var(--color-text-secondary)]"><ImageIcon size={18} /></div>}
                <div><p className="text-xs font-bold text-[var(--color-text)]">Website Favicon</p><p className="text-[11px] text-[var(--color-text-secondary)]">ICO, PNG, or SVG (max 1MB)</p></div>
              </div>
              <label className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)] text-xs font-semibold text-[var(--color-text)] cursor-pointer self-start sm:self-auto">
                <Upload size={14} /> {uploadingFavicon ? "Uploading..." : "Upload Favicon"}
                <input type="file" accept="image/x-icon,image/png,image/svg+xml,.ico" onChange={handleFaviconUpload} disabled={uploadingFavicon} className="hidden" />
              </label>
            </div>
          </div>

          {/* 2. Operations & Operating Hours */}
          <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs space-y-4">
            <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
              <Clock size={18} className="text-[var(--brand-gold)]" />
              Operational Controls & Opening Hours
            </h2>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Operating Hours (Kitchen / Service) *
                </label>
                <input
                  type="text"
                  required
                  value={settings.operating_hours}
                  onChange={(e) => setSettings({ ...settings, operating_hours: e.target.value })}
                  placeholder="11:00 - 22:00"
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                  Public Opening Hours Display *
                </label>
                <input
                  type="text"
                  required
                  value={settings.opening_hours_days}
                  onChange={(e) => setSettings({ ...settings, opening_hours_days: e.target.value })}
                  placeholder="Monday - Sunday: 11:00 AM - 10:00 PM"
                  className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-[var(--color-border)]">
              <div className="flex items-center justify-between p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)]">
                <div>
                  <p className="text-xs font-bold text-[var(--color-text)]">Customer Dine-In Ordering</p>
                  <p className="text-[11px] text-[var(--color-text-secondary)]">Allow dining table QR code orders</p>
                </div>
                <input
                  type="checkbox"
                  checked={settings.dine_in_enabled === "true"}
                  onChange={(e) =>
                    setSettings({ ...settings, dine_in_enabled: e.target.checked ? "true" : "false" })
                  }
                  className="w-5 h-5 rounded text-[var(--brand-copper)] focus:ring-[var(--brand-copper)]"
                />
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)]">
                <div>
                  <p className="text-xs font-bold text-[var(--color-text)]">Home Delivery Ordering</p>
                  <p className="text-[11px] text-[var(--color-text-secondary)]">Allow public online delivery orders</p>
                </div>
                <input
                  type="checkbox"
                  checked={settings.delivery_enabled === "true"}
                  onChange={(e) =>
                    setSettings({ ...settings, delivery_enabled: e.target.checked ? "true" : "false" })
                  }
                  className="w-5 h-5 rounded text-[var(--brand-copper)] focus:ring-[var(--brand-copper)]"
                />
              </div>
            </div>
          </div>

          {/* Save Button */}
          <div className="flex items-center justify-end">
            <button
              type="submit"
              disabled={saving}
              className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-[var(--brand-copper)] hover:bg-[var(--brand-copper)]/90 text-white font-semibold text-sm shadow-xs transition-colors disabled:opacity-50"
            >
              <Save size={16} />
              {saving ? "Saving Settings..." : "Save Settings"}
            </button>
          </div>
        </form>
      )}
      {!loading && <PaymentGatewayManagement />}
    </div>
  );
}
