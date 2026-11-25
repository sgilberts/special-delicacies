import { useEffect, useState, useCallback, useMemo } from "react";
import { Link } from "react-router-dom";
import { UtensilsCrossed } from "lucide-react";
import { cachedGet } from "@/lib/api";
import type { ApiResponse, MenuCategory, MenuItem } from "@/types/api";
import SectionHeading from "@/components/public/SectionHeading";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import EmptyState from "@/components/public/EmptyState";
import ErrorState from "@/components/public/ErrorState";
import { assetUrl } from "@/lib/assetUrl";

function formatPrice(price: string): string {
  const val = parseFloat(price);
  return isNaN(val) ? `GH₵${price}` : `GH₵${val.toFixed(2)}`;
}

export default function MenuPage() {
  const [categories, setCategories] = useState<MenuCategory[]>([]);
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    document.title = "Menu | Special Delicacies";
  }, []);

  useEffect(() => {
    let ignore = false;

    async function loadMenu() {
      try {
        const response = await cachedGet<ApiResponse<MenuCategory[]>>("/menu");
        if (ignore) return;
        if (response.data?.success && Array.isArray(response.data.data)) {
          setCategories(response.data.data);
        } else {
          setCategories([]);
        }
      } catch {
        if (!ignore) {
          setError("Unable to load the menu at this moment. Please check your connection and try again.");
        }
      } finally {
        if (!ignore) {
          setLoading(false);
        }
      }
    }

    loadMenu();

    return () => {
      ignore = true;
    };
  }, []);

  const handleRetry = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await cachedGet<ApiResponse<MenuCategory[]>>("/menu");
      if (response.data?.success && Array.isArray(response.data.data)) {
        setCategories(response.data.data);
      } else {
        setCategories([]);
      }
    } catch {
      setError("Unable to load the menu at this moment. Please check your connection and try again.");
    } finally {
      setLoading(false);
    }
  }, []);

  // Active categories that have active status
  const activeCategories = useMemo(() => {
    return categories.filter((c) => c.is_active);
  }, [categories]);

  // Filtered categories and items depending on selection
  const displayedCategories = useMemo(() => {
    if (selectedCategoryId === null) {
      return activeCategories;
    }
    return activeCategories.filter((c) => c.id === selectedCategoryId);
  }, [activeCategories, selectedCategoryId]);

  const totalAvailableItemsCount = useMemo(() => {
    return displayedCategories.reduce((acc, cat) => acc + (cat.available_items?.length || 0), 0);
  }, [displayedCategories]);

  return (
    <div className="flex flex-col w-full min-h-screen">
      {/* 1. PAGE HEADER */}
      <section className="section-padding-sm bg-[var(--color-surface)] border-b border-[var(--color-border)]">
        <div className="section-container text-center">
          <SectionHeading
            eyebrow="CULINARY DELIGHTS"
            title="Our Menu"
            subtitle="Carefully prepared dishes to satisfy every craving, from authentic local flavors to chef specialties."
            centered
          />
          <Link to="/delivery" className="btn-accent inline-flex items-center justify-center mt-5 px-6 py-3 rounded-xl font-bold text-sm min-h-[46px]">
            Order for delivery
          </Link>
          <div
            className="w-20 h-1 mx-auto mt-5 rounded-full"
            style={{ background: "var(--color-accent)" }}
          />
        </div>
      </section>

      {/* 2. CATEGORY NAV (STICKY / HORIZONTAL SCROLL) */}
      {!loading && !error && activeCategories.length > 0 && (
        <section className="sticky top-0 z-20 bg-[var(--color-background)]/95 backdrop-blur-md border-b border-[var(--color-border)] py-3">
          <div className="section-container">
            <div className="flex items-center gap-2 overflow-x-auto no-scrollbar md:justify-center md:flex-wrap py-1">
              <button
                type="button"
                onClick={() => setSelectedCategoryId(null)}
                className={`min-h-[44px] px-5 py-2.5 rounded-full text-sm font-semibold transition-all shrink-0 ${
                  selectedCategoryId === null
                    ? "bg-[var(--color-accent)] text-white shadow-sm"
                    : "btn-ghost"
                }`}
              >
                All
              </button>

              {activeCategories.map((cat) => (
                <button
                  key={cat.id}
                  type="button"
                  onClick={() => setSelectedCategoryId(cat.id)}
                  className={`min-h-[44px] px-5 py-2.5 rounded-full text-sm font-semibold transition-all shrink-0 ${
                    selectedCategoryId === cat.id
                      ? "bg-[var(--color-accent)] text-white shadow-sm"
                      : "btn-ghost"
                  }`}
                >
                  {cat.name}
                </button>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* 3. MENU CONTENT / GRID */}
      <section className="section-padding flex-1 bg-[var(--color-background)]">
        <div className="section-container">
          {loading && <LoadingSpinner message="Loading our fresh menu items..." />}

          {error && !loading && <ErrorState message={error} onRetry={handleRetry} />}

          {!loading && !error && totalAvailableItemsCount === 0 && (
            <EmptyState
              title={
                selectedCategoryId === null
                  ? "Our menu is being prepared. Check back soon."
                  : "No items available in this category."
              }
              description={
                selectedCategoryId === null
                  ? "Our culinary team is updating the available items. Please check back shortly."
                  : "Try choosing another category or selecting 'All' to browse all dishes."
              }
            />
          )}

          {!loading && !error && totalAvailableItemsCount > 0 && (
            <div className="space-y-12">
              {displayedCategories.map((category) => {
                const items: MenuItem[] = category.available_items || [];
                if (items.length === 0) return null;

                return (
                  <div key={category.id} className="space-y-6">
                    {/* Category Divider/Header when showing all */}
                    {selectedCategoryId === null && (
                      <div className="flex flex-col md:flex-row md:items-baseline justify-between border-b border-[var(--color-border)] pb-3 gap-2">
                        <div>
                          <h3
                            className="text-2xl font-bold tracking-tight"
                            style={{ color: "var(--color-text)" }}
                          >
                            {category.name}
                          </h3>
                          {category.description && (
                            <p
                              className="text-sm mt-1"
                              style={{ color: "var(--color-text-secondary)" }}
                            >
                              {category.description}
                            </p>
                          )}
                        </div>
                        <span
                          className="text-xs font-semibold uppercase tracking-wider px-3 py-1 rounded-full self-start md:self-auto"
                          style={{
                            background: "var(--color-surface-secondary)",
                            color: "var(--color-accent)",
                          }}
                        >
                          {items.length} {items.length === 1 ? "item" : "items"}
                        </span>
                      </div>
                    )}

                    {/* Items Grid */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                      {items.map((item) => (
                        <article
                          key={item.id}
                          className="pub-card flex flex-col transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                        >
                          {/* Image area */}
                          <div className="relative w-full aspect-[4/3] bg-[var(--color-surface-secondary)] overflow-hidden flex items-center justify-center">
                            {item.image_path ? (
                              <img
                                src={assetUrl(item.image_path) ?? undefined}
                                alt={item.name}
                                loading="lazy"
                                className="w-full h-full object-cover"
                                onError={(e) => {
                                  // Fallback to placeholder if image fails to load
                                  (e.currentTarget as HTMLImageElement).style.display = 'none';
                                  const parent = e.currentTarget.parentElement;
                                  if (parent) {
                                    const fallback = parent.querySelector('.img-fallback');
                                    if (fallback) fallback.classList.remove('hidden');
                                  }
                                }}
                              />
                            ) : null}
                            <div className={`img-fallback flex flex-col items-center justify-center text-[var(--color-text-secondary)] gap-2 ${item.image_path ? 'hidden' : ''}`}>
                              <UtensilsCrossed size={36} strokeWidth={1.5} />
                              <span className="text-xs font-medium uppercase tracking-wider">
                                {category.name}
                              </span>
                            </div>
                          </div>

                          {/* Card body */}
                          <div className="p-4 flex flex-col flex-1">
                            <div className="flex items-start justify-between gap-2 mb-1.5">
                              <h4
                                className="text-base font-semibold leading-snug"
                                style={{ color: "var(--color-text)" }}
                              >
                                {item.name}
                              </h4>
                              <span
                                className="font-bold text-[15px] shrink-0"
                                style={{ color: "var(--color-accent)" }}
                              >
                                {formatPrice(item.price)}
                              </span>
                            </div>

                            <p
                              className="text-sm line-clamp-2 mt-auto"
                              style={{ color: "var(--color-text-secondary)" }}
                            >
                              {item.description || "Prepared with fresh, premium ingredients."}
                            </p>
                          </div>
                        </article>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </section>
    </div>
  );
}
