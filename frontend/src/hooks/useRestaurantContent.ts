import { useState, useEffect, useCallback } from "react";
import { cachedGet } from "@/lib/api";
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
  gallery_items: [
    {
      id: 1,
      category: "Food",
      title: "Signature Gourmet Jollof",
      description: "Slow-cooked fragrant rice with aromatic spices and tender grilled protein.",
      tint: "rgba(189, 112, 72, 0.15)",
      image_url: null,
    },
    {
      id: 2,
      category: "Restaurant",
      title: "Dining Room Atmosphere",
      description: "Intimate and comfortable seating crafted for memorable meals.",
      tint: "rgba(216, 170, 101, 0.15)",
      image_url: null,
    },
    {
      id: 3,
      category: "Events",
      title: "Private Celebration Setup",
      description: "Custom table settings and floral arrangements for intimate family gatherings.",
      tint: "rgba(120, 134, 108, 0.15)",
      image_url: null,
    },
    {
      id: 4,
      category: "Food",
      title: "Artisanal Charcoal Grill",
      description: "Succulent cuts infused with house-blended seasonings and natural smoke.",
      tint: "rgba(189, 112, 72, 0.15)",
      image_url: null,
    },
    {
      id: 5,
      category: "Restaurant",
      title: "Open Kitchen Theatre",
      description: "Watch our master chefs work magic in the bustling culinary station.",
      tint: "rgba(216, 170, 101, 0.15)",
      image_url: null,
    },
    {
      id: 6,
      category: "Events",
      title: "Catering Showcase",
      description: "Buffet presentation prepared for corporate banquets and celebrations.",
      tint: "rgba(120, 134, 108, 0.15)",
      image_url: null,
    },
  ],
};

let cachedContent: PublicContentResponse | null = null;

export function useRestaurantContent() {
  const [content, setContent] = useState<PublicContentResponse>(cachedContent || DEFAULT_CONTENT);
  const [loading, setLoading] = useState<boolean>(!cachedContent);
  const [error, setError] = useState<string | null>(null);

  const fetchContent = useCallback(async () => {
    try {
      const res = await cachedGet<ApiResponse<PublicContentResponse>>("/content");
      if (res.data.success && res.data.data) {
        cachedContent = res.data.data;
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
    return () => window.removeEventListener("restaurant-content-updated", fetchContent);
  }, [fetchContent]);

  return {
    content,
    loading,
    error,
    refresh: fetchContent,
  };
}
