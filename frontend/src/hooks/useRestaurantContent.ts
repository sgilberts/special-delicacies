import { useState, useEffect, useCallback } from "react";
import api from "@/lib/api";
import type { PublicContentResponse, ApiResponse } from "@/types/api";
import { applyFavicon } from "@/lib/assetUrl";

const DEFAULT_CONTENT: PublicContentResponse = {
  restaurant_name: "Special Delicacies",
  restaurant_phone: "+233 30 123 4567",
  restaurant_email: "contact@special-delicacies.local",
  restaurant_address: "100 Gourmet Boulevard, Culinary Quarter",
  currency: "GHS",
  currency_symbol: "GH₵",
  operating_hours: "11:00 - 22:00",
  opening_hours_days: "Monday - Sunday: 11:00 AM - 10:00 PM",
  dine_in_enabled: true,
  delivery_enabled: true,
  restaurant_logo: null,
  favicon_url: null,
  hero_title: "Special Delicacies",
  hero_subtitle: "Fine Dining & Takeaway Experience",
  hero_announcement: "Authentic culinary craftsmanship, served daily",
  about_story:
    "Special Delicacies was founded with a singular conviction: that exceptional food brings communities together. What began as a humble dream has grown into a destination where guests can delight in authentic, thoughtfully prepared meals made from the freshest ingredients.",
  about_mission: "Quality Food, Community First, Excellence Always",
  gallery_items: [],
  hero_items: [],
};

let cachedContent: PublicContentResponse | null = null;
let cachedEtag: string | null = null;

export function useRestaurantContent() {
  const [content, setContent] = useState<PublicContentResponse>(cachedContent || DEFAULT_CONTENT);
  const [loading, setLoading] = useState<boolean>(!cachedContent);
  const [error, setError] = useState<string | null>(null);

  const fetchContent = useCallback(async () => {
    try {
      const res = await api.get<ApiResponse<PublicContentResponse>>("/content", {
        headers: cachedEtag ? { "If-None-Match": cachedEtag } : undefined,
        validateStatus: (status) => status === 200 || status === 304,
      });
      if (res.status === 304) return;
      if (res.data.success && res.data.data) {
        cachedContent = res.data.data;
        cachedEtag = res.headers.etag ?? cachedEtag;
        setContent(res.data.data);
        applyFavicon(res.data.data.favicon_url);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to load content";
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchContent();
    window.addEventListener("restaurant-content-updated", fetchContent);
    const refreshWhenVisible = () => { if (!document.hidden) void fetchContent(); };
    document.addEventListener("visibilitychange", refreshWhenVisible);
    const timer = window.setInterval(() => { if (!document.hidden) void fetchContent(); }, 30_000);
    return () => {
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", refreshWhenVisible);
      window.removeEventListener("restaurant-content-updated", fetchContent);
    };
  }, [fetchContent]);

  return {
    content,
    loading,
    error,
    refresh: fetchContent,
  };
}
