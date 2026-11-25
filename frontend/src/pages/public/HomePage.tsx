import { useEffect, useState, useCallback } from "react";
import { Link } from "react-router-dom";
import { ChefHat, Leaf, Star, UtensilsCrossed, ArrowRight, Image as ImageIcon } from "lucide-react";
import { cachedGet } from "@/lib/api";
import type { ApiResponse, MenuCategory, MenuItem } from "@/types/api";
import SectionHeading from "@/components/public/SectionHeading";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import EmptyState from "@/components/public/EmptyState";
import ErrorState from "@/components/public/ErrorState";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";
import heroBg from "@/assets/hero.png";

function formatPrice(price: string): string {
  const val = parseFloat(price);
  return isNaN(val) ? `GH₵${price}` : `GH₵${val.toFixed(2)}`;
}

export default function HomePage() {
  const { content } = useRestaurantContent();
  const [featuredItems, setFeaturedItems] = useState<MenuItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    document.title = "Special Delicacies | Fine Dining & Takeaway";
  }, []);

  useEffect(() => {
    let ignore = false;

    async function loadMenu() {
      try {
        const response = await cachedGet<ApiResponse<MenuCategory[]>>("/menu");
        if (ignore) return;
        if (response.data?.success && Array.isArray(response.data.data)) {
          const categories = response.data.data;
          // Collect up to 6 items (first 2 from each of first 3 categories, or first 6 overall)
          const selected: MenuItem[] = [];
          for (const cat of categories) {
            const items = cat.available_items || [];
            for (const item of items) {
              if (selected.length < 6 && !selected.some((i) => i.id === item.id)) {
                selected.push(item);
              }
            }
            if (selected.length >= 6) break;
          }
          setFeaturedItems(selected);
        } else {
          setFeaturedItems([]);
        }
      } catch {
        if (!ignore) {
          setError("Unable to load featured menu items. Please check your connection.");
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
        const categories = response.data.data;
        const selected: MenuItem[] = [];
        for (const cat of categories) {
          const items = cat.available_items || [];
          for (const item of items) {
            if (selected.length < 6 && !selected.some((i) => i.id === item.id)) {
              selected.push(item);
            }
          }
          if (selected.length >= 6) break;
        }
        setFeaturedItems(selected);
      } else {
        setFeaturedItems([]);
      }
    } catch {
      setError("Unable to load featured menu items. Please check your connection.");
    } finally {
      setLoading(false);
    }
  }, []);

  return (
    <div className="flex flex-col w-full">
      {/* 1. HERO SECTION */}
      <section
        className="relative w-full flex items-center justify-center text-center px-4 overflow-hidden"
        style={{
          minHeight: "clamp(360px, 60vh, 85vh)",
          backgroundImage: `linear-gradient(to bottom, rgba(48, 32, 25, 0.55) 0%, rgba(48, 32, 25, 0.78) 100%), url(${heroBg})`,
          backgroundSize: "cover",
          backgroundPosition: "center",
          backgroundRepeat: "no-repeat",
        }}
      >
        <div className="max-w-3xl mx-auto py-16 md:py-24 flex flex-col items-center">
          <span
            className="text-xs md:text-sm font-bold tracking-widest uppercase mb-3 inline-block"
            style={{ color: "var(--brand-gold)" }}
          >
            {content.hero_announcement || "WELCOME TO"}
          </span>
          <h1 className="text-3xl sm:text-4xl md:text-6xl font-extrabold text-white tracking-tight mb-4 leading-tight">
            {content.hero_title || "Special Delicacies"}
          </h1>
          <p className="text-base sm:text-lg md:text-xl text-white/90 font-normal mb-8 max-w-2xl leading-relaxed">
            {content.hero_subtitle || "Fine dining and takeaway crafted with passion, fresh ingredients, and flavours that bring people together."}
          </p>
          <div className="flex flex-wrap items-center justify-center gap-4">
            <Link to="/menu" className="btn-accent min-h-[44px]">
              Explore Our Menu
            </Link>
            <Link
              to="/about"
              className="inline-flex items-center gap-2 px-6 py-3 rounded-full font-semibold text-white border-2 border-white/70 hover:bg-white/10 hover:border-white transition-all min-h-[44px]"
            >
              About Us
            </Link>
          </div>
        </div>
      </section>

      {/* 2. FEATURES SECTION */}
      <section className="section-padding bg-[var(--color-background)]">
        <div className="section-container">
          <SectionHeading
            eyebrow="OUR COMMITMENT"
            title="Why Choose Special Delicacies"
            subtitle="Exceptional food prepared with integrity, warmth, and care."
            centered
          />
          <div className="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8">
            <div className="pub-card p-6 md:p-8 flex flex-col items-center text-center transition-transform hover:-translate-y-1">
              <div
                className="w-14 h-14 rounded-full flex items-center justify-center mb-5 shrink-0"
                style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
              >
                <ChefHat size={28} />
              </div>
              <h3 className="text-xl font-bold mb-3" style={{ color: "var(--color-text)" }}>
                Crafted With Passion
              </h3>
              <p className="text-sm md:text-base leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
                Every dish is prepared by our skilled chefs using time-honoured techniques and the finest ingredients.
              </p>
            </div>

            <div className="pub-card p-6 md:p-8 flex flex-col items-center text-center transition-transform hover:-translate-y-1">
              <div
                className="w-14 h-14 rounded-full flex items-center justify-center mb-5 shrink-0"
                style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
              >
                <Leaf size={28} />
              </div>
              <h3 className="text-xl font-bold mb-3" style={{ color: "var(--color-text)" }}>
                Fresh Ingredients
              </h3>
              <p className="text-sm md:text-base leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
                We source fresh, quality ingredients daily to ensure every meal is as nourishing as it is delicious.
              </p>
            </div>

            <div className="pub-card p-6 md:p-8 flex flex-col items-center text-center transition-transform hover:-translate-y-1">
              <div
                className="w-14 h-14 rounded-full flex items-center justify-center mb-5 shrink-0"
                style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
              >
                <Star size={28} />
              </div>
              <h3 className="text-xl font-bold mb-3" style={{ color: "var(--color-text)" }}>
                Memorable Experiences
              </h3>
              <p className="text-sm md:text-base leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
                From family meals to special occasions, we create moments worth savouring.
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* 3. FEATURED MENU SECTION */}
      <section className="section-padding bg-[var(--color-surface)] border-y border-[var(--color-border)]">
        <div className="section-container">
          <SectionHeading
            eyebrow="OUR MENU"
            title="Taste the Difference"
            subtitle="A selection of our most beloved dishes."
            centered
          />

          <div className="mt-12">
            {loading && <LoadingSpinner message="Loading our signature dishes..." />}
            {error && !loading && <ErrorState message={error} onRetry={handleRetry} />}
            {!loading && !error && featuredItems.length === 0 && (
              <EmptyState
                title="Our menu is coming soon"
                description="We are currently refreshing our kitchen offerings. Check back shortly."
              />
            )}
            {!loading && !error && featuredItems.length > 0 && (
              <>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                  {featuredItems.map((item) => (
                    <article
                      key={item.id}
                      className="pub-card flex flex-col transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                    >
                      <div className="relative w-full aspect-[4/3] bg-[var(--color-surface-secondary)] overflow-hidden flex items-center justify-center">
                        {item.image_path ? (
                          <img
                            src={assetUrl(item.image_path) ?? undefined}
                            alt={item.name}
                            loading="lazy"
                            className="w-full h-full object-cover"
                            onError={(e) => {
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
                          <span className="text-xs font-medium uppercase tracking-wider">Delicacy</span>
                        </div>
                      </div>
                      <div className="p-5 flex flex-col flex-1">
                        <div className="flex items-start justify-between gap-2 mb-2">
                          <h4 className="text-base font-semibold" style={{ color: "var(--color-text)" }}>
                            {item.name}
                          </h4>
                          <span
                            className="font-bold text-base shrink-0"
                            style={{ color: "var(--color-accent)" }}
                          >
                            {formatPrice(item.price)}
                          </span>
                        </div>
                        <p
                          className="text-sm line-clamp-2 mt-auto"
                          style={{ color: "var(--color-text-secondary)" }}
                        >
                          {item.description || "A delicious house specialty prepared fresh to order."}
                        </p>
                      </div>
                    </article>
                  ))}
                </div>

                <div className="mt-10 flex justify-center">
                  <Link to="/menu" className="btn-accent min-h-[44px]">
                    View Full Menu <ArrowRight size={18} />
                  </Link>
                </div>
              </>
            )}
          </div>
        </div>
      </section>

      {/* 4. ABOUT SNIPPET */}
      <section className="section-padding bg-[var(--color-surface-secondary)]">
        <div className="section-container">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
            <div>
              <span
                className="block mb-2 text-xs font-bold tracking-widest uppercase"
                style={{ color: "var(--color-accent)" }}
              >
                OUR HERITAGE
              </span>
              <h2
                className="text-3xl md:text-4xl font-bold mb-4"
                style={{ color: "var(--color-text)", letterSpacing: "-0.5px" }}
              >
                About Special Delicacies
              </h2>
              <p
                className="text-base md:text-lg mb-4 leading-relaxed"
                style={{ color: "var(--color-text-secondary)" }}
              >
                [PLACEHOLDER] Established with a passion for authentic culinary experiences, Special Delicacies brings together the rich flavors of traditional recipes with modern gastronomic finesse. We take immense pride in creating heartwarming meals that satisfy your palate and soothe your soul.
              </p>
              <p
                className="text-base md:text-lg mb-6 leading-relaxed"
                style={{ color: "var(--color-text-secondary)" }}
              >
                [PLACEHOLDER] Whether you are joining us for a relaxed dine-in evening or picking up a warm takeaway for your family, our commitment to hospitality and culinary excellence remains unwavering.
              </p>
              <Link to="/about" className="btn-accent min-h-[44px]">
                Our Story <ArrowRight size={18} />
              </Link>
            </div>

            <div className="w-full aspect-[4/3] rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-sm flex flex-col items-center justify-center text-center p-8">
              <UtensilsCrossed
                size={48}
                strokeWidth={1.5}
                className="mb-3"
                style={{ color: "var(--color-accent)" }}
              />
              <span className="font-semibold text-base" style={{ color: "var(--color-text)" }}>
                Restaurant Image [Placeholder]
              </span>
              <span className="text-xs mt-1" style={{ color: "var(--color-text-secondary)" }}>
                Authentic atmosphere and culinary excellence
              </span>
            </div>
          </div>
        </div>
      </section>

      {/* 5. GALLERY TEASER */}
      <section className="section-padding bg-[var(--color-background)]">
        <div className="section-container">
          <SectionHeading
            eyebrow="GALLERY"
            title="Moments & Flavours"
            subtitle="A glimpse into the Special Delicacies experience."
            centered
          />
          <div className="mt-12 grid grid-cols-2 md:grid-cols-4 gap-4">
            {[1, 2, 3, 4].map((item) => (
              <div
                key={item}
                className="pub-card aspect-square flex flex-col items-center justify-center p-4 text-center transition-transform hover:scale-[1.02]"
                style={{ background: "var(--color-surface)" }}
              >
                <div
                  className="w-12 h-12 rounded-full flex items-center justify-center mb-3"
                  style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
                >
                  <ImageIcon size={22} />
                </div>
                <span className="text-xs font-semibold" style={{ color: "var(--color-text)" }}>
                  Photo #{item} [Placeholder]
                </span>
                <span className="text-[11px] mt-1" style={{ color: "var(--color-text-secondary)" }}>
                  Special Moments
                </span>
              </div>
            ))}
          </div>
          <div className="mt-8 flex justify-center">
            <Link to="/gallery" className="btn-ghost min-h-[44px]">
              View Full Gallery <ArrowRight size={16} />
            </Link>
          </div>
        </div>
      </section>

      {/* 6. CTA SECTION */}
      <section
        className="w-full section-padding text-center px-4"
        style={{
          background: "var(--brand-espresso)",
          color: "var(--brand-cream)",
        }}
      >
        <div className="max-w-2xl mx-auto flex flex-col items-center">
          <h2 className="text-2xl sm:text-3xl md:text-4xl font-bold mb-4 text-white">
            Ready to Experience Special Delicacies?
          </h2>
          <p className="text-base md:text-lg mb-8 text-[#f5eee5]/80">
            Visit us today, order your favourite takeaway, or reach out to reserve your table.
          </p>
          <div className="flex flex-wrap items-center justify-center gap-4">
            <Link to="/menu" className="btn-accent min-h-[44px]">
              View Menu
            </Link>
            <Link
              to="/contact"
              className="inline-flex items-center gap-2 px-6 py-3 rounded-full font-semibold text-white border-2 border-white/70 hover:bg-white/10 hover:border-white transition-all min-h-[44px]"
            >
              Contact Us
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
