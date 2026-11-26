import { useEffect, useMemo, useState } from "react";
import { Images, X, ZoomIn } from "lucide-react";
import SectionHeading from "@/components/public/SectionHeading";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";
import type { WebsiteGalleryItem } from "@/types/api";

type CategoryFilter = "All" | "Food" | "Restaurant" | "Events";

export default function GalleryPage() {
  const { content } = useRestaurantContent();
  const [filter, setFilter] = useState<CategoryFilter>("All");
  const [selectedItem, setSelectedItem] = useState<WebsiteGalleryItem | null>(null);
  const galleryItems = content.gallery_items ?? [];
  const filteredItems = useMemo(() => filter === "All" ? galleryItems : galleryItems.filter((item) => item.category === filter), [filter, galleryItems]);

  useEffect(() => { document.title = "Gallery | Special Delicacies"; }, []);
  useEffect(() => {
    const closeOnEscape = (event: KeyboardEvent) => { if (event.key === "Escape") setSelectedItem(null); };
    window.addEventListener("keydown", closeOnEscape);
    return () => window.removeEventListener("keydown", closeOnEscape);
  }, []);

  return (
    <div className="flex min-h-screen w-full flex-col">
      <section className="section-padding-sm border-b border-[var(--color-border)] bg-[var(--color-surface)]">
        <div className="section-container text-center"><SectionHeading eyebrow="MOMENTS IN FOCUS" title="Gallery" subtitle="A visual taste of Special Delicacies." centered /><div className="mx-auto mt-5 h-1 w-20 rounded-full bg-[var(--color-accent)]" /></div>
      </section>
      <section className="border-b border-[var(--color-border)] bg-[var(--color-background)] py-6">
        <div className="section-container flex flex-wrap items-center justify-center gap-2">
          {(["All", "Food", "Restaurant", "Events"] as CategoryFilter[]).map((category) => <button key={category} type="button" onClick={() => setFilter(category)} className={`min-h-[44px] rounded-full px-6 py-2.5 text-sm font-semibold transition-all ${filter === category ? "bg-[var(--color-accent)] text-white shadow-sm" : "btn-ghost"}`}>{category}</button>)}
        </div>
      </section>
      <section className="section-padding flex-1 bg-[var(--color-background)]">
        <div className="section-container">
          {filteredItems.length ? <div className="grid grid-cols-2 gap-4 md:grid-cols-3 md:gap-6 lg:grid-cols-4">
            {filteredItems.map((item) => <button key={item.id} type="button" onClick={() => setSelectedItem(item)} aria-label={`View ${item.title}`} className="group relative aspect-square overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] text-left shadow-sm transition duration-300 hover:scale-[1.02] hover:shadow-md focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] motion-reduce:transition-none">
              <img src={assetUrl(item.image_url ?? null) ?? undefined} alt={item.title} loading="lazy" decoding="async" className="h-full w-full object-cover" />
              <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent p-3 pt-14 text-white sm:p-4 sm:pt-20"><span className="block text-xs font-bold sm:text-sm">{item.title}</span><span className="mt-1 line-clamp-2 block text-[10px] text-white/80 sm:text-xs">{item.description}</span></div>
              <div className="absolute inset-0 flex items-center justify-center bg-black/30 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100 motion-reduce:transition-none"><span className="rounded-full bg-white/90 p-3 text-[var(--brand-espresso)]"><ZoomIn size={20} /></span></div>
            </button>)}
          </div> : <div className="mx-auto flex max-w-lg flex-col items-center py-20 text-center text-[var(--color-text-secondary)]"><Images size={42} className="mb-3 text-[var(--color-accent)]" /><p className="font-semibold">No published gallery photos yet.</p><p className="mt-1 text-sm">Please visit again to see moments from our restaurant.</p></div>}
        </div>
      </section>
      {selectedItem && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm" onClick={() => setSelectedItem(null)} role="dialog" aria-modal="true" aria-label={selectedItem.title}>
        <div className="pub-card relative flex w-full max-w-3xl flex-col items-center p-5 text-center shadow-2xl sm:p-8" style={{ background: "var(--color-surface)" }} onClick={(event) => event.stopPropagation()}>
          <button type="button" onClick={() => setSelectedItem(null)} className="absolute right-3 top-3 grid min-h-11 min-w-11 place-items-center rounded-full text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]" aria-label="Close gallery image"><X size={22} /></button>
          <img src={assetUrl(selectedItem.image_url ?? null) ?? undefined} alt={selectedItem.title} className="mb-5 max-h-[65vh] w-full rounded-xl border border-[var(--color-border)] object-contain" />
          <h2 className="text-xl font-bold text-[var(--color-text)]">{selectedItem.title}</h2>
          <p className="mt-1 text-xs font-semibold uppercase tracking-wider text-[var(--color-accent)]">{selectedItem.category}</p>
          {selectedItem.description && <p className="mt-3 max-w-xl text-sm leading-relaxed text-[var(--color-text-secondary)]">{selectedItem.description}</p>}
        </div>
      </div>}
    </div>
  );
}
