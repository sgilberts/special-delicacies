import { useState, useEffect } from "react";
import { Images, X, ZoomIn } from "lucide-react";
import SectionHeading from "@/components/public/SectionHeading";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";

interface GalleryItem {
  id: number;
  category: "Food" | "Restaurant" | "Events";
  title: string;
  description: string;
  tint: string;
  image_url?: string | null;
}

const GALLERY_ITEMS: GalleryItem[] = [
  {
    id: 1,
    category: "Food",
    title: "Signature Gourmet Jollof [Placeholder]",
    description: "Slow-cooked fragrant rice with aromatic spices and tender grilled protein.",
    tint: "rgba(189, 112, 72, 0.15)",
  },
  {
    id: 2,
    category: "Restaurant",
    title: "Dining Room Atmosphere [Placeholder]",
    description: "Intimate and comfortable seating crafted for memorable meals.",
    tint: "rgba(216, 170, 101, 0.15)",
  },
  {
    id: 3,
    category: "Events",
    title: "Private Celebration Setup [Placeholder]",
    description: "Custom table settings and floral arrangements for intimate family gatherings.",
    tint: "rgba(120, 134, 108, 0.15)",
  },
  {
    id: 4,
    category: "Food",
    title: "Artisanal Charcoal Grill [Placeholder]",
    description: "Succulent cuts infused with house-blended seasonings and natural smoke.",
    tint: "rgba(189, 112, 72, 0.15)",
  },
  {
    id: 5,
    category: "Restaurant",
    title: "Open Kitchen Theatre [Placeholder]",
    description: "Watch our master chefs work magic in the bustling culinary station.",
    tint: "rgba(216, 170, 101, 0.15)",
  },
  {
    id: 6,
    category: "Events",
    title: "Catering Showcase [Placeholder]",
    description: "Buffet presentation prepared for corporate banquets and celebrations.",
    tint: "rgba(120, 134, 108, 0.15)",
  },
  {
    id: 7,
    category: "Food",
    title: "Fresh Harvest Salads & Sides [Placeholder]",
    description: "Crisp locally farmed produce tossed with signature house vinaigrettes.",
    tint: "rgba(189, 112, 72, 0.15)",
  },
  {
    id: 8,
    category: "Restaurant",
    title: "Warm Evening Ambience [Placeholder]",
    description: "Subtle accent lighting that elevates dinner into a special experience.",
    tint: "rgba(216, 170, 101, 0.15)",
  },
  {
    id: 9,
    category: "Events",
    title: "Festive Banquet Table [Placeholder]",
    description: "Generous tasting platters arranged for group dining and celebration.",
    tint: "rgba(120, 134, 108, 0.15)",
  },
  {
    id: 10,
    category: "Food",
    title: "Chef's Signature Stew [Placeholder]",
    description: "Rich, velvety traditional stew garnished with fresh mountain herbs.",
    tint: "rgba(189, 112, 72, 0.15)",
  },
  {
    id: 11,
    category: "Restaurant",
    title: "Welcome Reception & Lounge [Placeholder]",
    description: "A cozy entry lounge where patrons are warmly greeted upon arrival.",
    tint: "rgba(216, 170, 101, 0.15)",
  },
  {
    id: 12,
    category: "Events",
    title: "Weekend Chef's Special Evening [Placeholder]",
    description: "Exclusive curated tasting menus hosted on selected weekends.",
    tint: "rgba(120, 134, 108, 0.15)",
  },
];

type CategoryFilter = "All" | "Food" | "Restaurant" | "Events";

export default function GalleryPage() {
  const { content } = useRestaurantContent();
  const [filter, setFilter] = useState<CategoryFilter>("All");
  const [selectedItem, setSelectedItem] = useState<GalleryItem | null>(null);

  useEffect(() => {
    document.title = "Gallery | Special Delicacies";
  }, []);

  // Handle escape key to close lightbox
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        setSelectedItem(null);
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, []);

  const activeGalleryItems: GalleryItem[] = (content.gallery_items && content.gallery_items.length > 0)
    ? content.gallery_items.map((g) => ({
        id: g.id,
        category: (g.category as GalleryItem["category"]) ?? "Food",
        title: g.title,
        description: g.description ?? "",
        tint: g.tint ?? "rgba(189, 112, 72, 0.12)",
        image_url: g.image_url ?? null,
      }))
    : GALLERY_ITEMS;

  const filteredItems = filter === "All"
    ? activeGalleryItems
    : activeGalleryItems.filter((item) => item.category === filter);

  return (
    <div className="flex flex-col w-full min-h-screen">
      {/* 1. PAGE HEADER */}
      <section className="section-padding-sm bg-[var(--color-surface)] border-b border-[var(--color-border)]">
        <div className="section-container text-center">
          <SectionHeading
            eyebrow="MOMENTS IN FOCUS"
            title="Gallery"
            subtitle="A visual taste of Special Delicacies."
            centered
          />
          <div
            className="w-20 h-1 mx-auto mt-5 rounded-full"
            style={{ background: "var(--color-accent)" }}
          />
        </div>
      </section>

      {/* 2. CATEGORY FILTERS */}
      <section className="py-6 bg-[var(--color-background)] border-b border-[var(--color-border)]">
        <div className="section-container">
          <div className="flex items-center justify-center gap-2 flex-wrap">
            {(["All", "Food", "Restaurant", "Events"] as CategoryFilter[]).map((cat) => (
              <button
                key={cat}
                type="button"
                onClick={() => setFilter(cat)}
                className={`min-h-[44px] px-6 py-2.5 rounded-full text-sm font-semibold transition-all ${
                  filter === cat
                    ? "bg-[var(--color-accent)] text-white shadow-sm"
                    : "btn-ghost"
                }`}
              >
                {cat}
              </button>
            ))}
          </div>
        </div>
      </section>

      {/* 3. IMAGE GRID */}
      <section className="section-padding flex-1 bg-[var(--color-background)]">
        <div className="section-container">
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6">
            {filteredItems.map((item) => (
              <div
                key={item.id}
                role="button"
                tabIndex={0}
                onClick={() => setSelectedItem(item)}
                onKeyDown={(e) => {
                  if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
                    setSelectedItem(item);
                  }
                }}
                className="group relative aspect-square rounded-2xl border border-[var(--color-border)] shadow-sm overflow-hidden cursor-pointer transition-all duration-300 hover:scale-[1.02] hover:shadow-md focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] flex flex-col items-center justify-center p-4 text-center"
                style={{ backgroundColor: item.tint }}
              >
                {item.image_url ? (
                  <img
                    src={assetUrl(item.image_url) ?? undefined}
                    alt={item.title}
                    loading="lazy"
                    className="w-full h-full object-cover rounded-xl"
                  />
                ) : (
                  <div className="flex flex-col items-center justify-center w-full h-full p-2">
                    <div
                      className="w-12 h-12 rounded-full flex items-center justify-center mb-3 transition-transform group-hover:scale-110"
                      style={{ background: "var(--color-surface)", color: "var(--color-accent)" }}
                    >
                      <Images size={24} />
                    </div>
                    <span
                      className="text-xs md:text-sm font-semibold line-clamp-2"
                      style={{ color: "var(--color-text)" }}
                    >
                      {item.title}
                    </span>
                    <span
                      className="text-[11px] font-medium uppercase tracking-wider mt-1 px-2.5 py-0.5 rounded-full"
                      style={{
                        background: "var(--color-surface)",
                        color: "var(--color-text-secondary)",
                      }}
                    >
                      {item.category}
                    </span>
                  </div>
                )}

                {/* Subtle hover icon overlay */}
                <div className="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center rounded-2xl">
                  <div className="p-3 bg-white/90 text-[var(--brand-espresso)] rounded-full shadow-lg">
                    <ZoomIn size={20} />
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* 4. LIGHTBOX MODAL */}
      {selectedItem && (
        <div
          className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4"
          onClick={() => setSelectedItem(null)}
          role="dialog"
          aria-modal="true"
        >
          <div
            className="relative w-full max-w-xl pub-card p-6 md:p-8 flex flex-col items-center text-center shadow-2xl"
            style={{ background: "var(--color-surface)" }}
            onClick={(e) => e.stopPropagation()}
          >
            {/* Close button */}
            <button
              type="button"
              onClick={() => setSelectedItem(null)}
              className="absolute top-4 right-4 p-2.5 rounded-full hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center"
              aria-label="Close dialog"
            >
              <X size={22} />
            </button>

            {/* Modal Image Area */}
            <div
              className="w-full aspect-[4/3] rounded-xl flex flex-col items-center justify-center mb-6 border border-[var(--color-border)] p-6"
              style={{ backgroundColor: selectedItem.tint }}
            >
              {selectedItem.image_url ? (
                <img
                  src={assetUrl(selectedItem.image_url) ?? undefined}
                  alt={selectedItem.title}
                  className="w-full h-full object-contain"
                />
              ) : (
                <div className="flex flex-col items-center justify-center">
                  <Images
                    size={56}
                    strokeWidth={1.5}
                    className="mb-3"
                    style={{ color: "var(--color-accent)" }}
                  />
                  <span className="text-base font-semibold" style={{ color: "var(--color-text)" }}>
                    {selectedItem.title}
                  </span>
                  <span
                    className="text-xs uppercase tracking-wider mt-1 px-3 py-1 rounded-full"
                    style={{
                      background: "var(--color-surface)",
                      color: "var(--color-accent)",
                    }}
                  >
                    Category: {selectedItem.category}
                  </span>
                </div>
              )}
            </div>

            {/* Modal Text */}
            <h3 className="text-xl font-bold mb-2" style={{ color: "var(--color-text)" }}>
              {selectedItem.title}
            </h3>
            <p className="text-sm md:text-base leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
              {selectedItem.description}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
