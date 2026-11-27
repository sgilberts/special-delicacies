import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { KeyboardEvent } from "react";
import { Link } from "react-router-dom";
import { ChevronLeft, ChevronRight } from "lucide-react";
import type { WebsiteGalleryItem } from "@/types/api";
import { assetUrl } from "@/lib/assetUrl";

type Props = {
  slides: WebsiteGalleryItem[];
  title: string;
  subtitle: string;
  announcement: string;
};

export default function HomepageHeroCarousel({ slides, title, subtitle, announcement }: Props) {
  const activeSlides = useMemo(
    () => slides.filter((slide) => slide.image_url && slide.is_published !== false && slide.hero_active !== false),
    [slides],
  );
  const [index, setIndex] = useState(0);
  const [loaded, setLoaded] = useState(false);
  const [imageFailed, setImageFailed] = useState(false);
  const [pointerPaused, setPointerPaused] = useState(false);
  const [focusPaused, setFocusPaused] = useState(false);
  const [manualPaused, setManualPaused] = useState(false);
  const [visible, setVisible] = useState(() => typeof document === "undefined" || !document.hidden);
  const [reducedMotion, setReducedMotion] = useState(false);
  const touchStartX = useRef<number | null>(null);
  const current = activeSlides[index];

  useEffect(() => {
    const media = window.matchMedia("(prefers-reduced-motion: reduce)");
    const sync = () => setReducedMotion(media.matches);
    sync();
    media.addEventListener("change", sync);
    return () => media.removeEventListener("change", sync);
  }, []);

  useEffect(() => {
    const sync = () => setVisible(!document.hidden);
    document.addEventListener("visibilitychange", sync);
    return () => document.removeEventListener("visibilitychange", sync);
  }, []);

  useEffect(() => {
    setIndex((value) => activeSlides.length ? value % activeSlides.length : 0);
  }, [activeSlides.length]);

  useEffect(() => {
    setLoaded(false);
    setImageFailed(false);
  }, [current?.id]);

  const move = useCallback((delta: number, manual = true) => {
    if (activeSlides.length < 2) return;
    setIndex((value) => (value + delta + activeSlides.length) % activeSlides.length);
    if (manual) {
      setManualPaused(true);
      window.setTimeout(() => setManualPaused(false), 5000);
    }
  }, [activeSlides.length]);

  useEffect(() => {
    if (activeSlides.length < 2 || reducedMotion || !visible || pointerPaused || focusPaused || manualPaused) return;
    const timer = window.setInterval(() => move(1, false), 7000);
    return () => window.clearInterval(timer);
  }, [activeSlides.length, focusPaused, manualPaused, move, pointerPaused, reducedMotion, visible]);

  useEffect(() => {
    if (activeSlides.length < 2) return;
    const next = activeSlides[(index + 1) % activeSlides.length];
    const src = assetUrl(next?.image_url ?? null);
    if (!src) return;
    const image = new Image();
    image.src = src;
  }, [activeSlides, index]);

  const handleKeyDown = (event: KeyboardEvent<HTMLElement>) => {
    if (event.key === "ArrowLeft") { event.preventDefault(); move(-1); }
    if (event.key === "ArrowRight") { event.preventDefault(); move(1); }
  };

  return (
    <section
      aria-label="Featured restaurant images"
      aria-roledescription={activeSlides.length > 1 ? "carousel" : undefined}
      tabIndex={activeSlides.length > 1 ? 0 : undefined}
      onKeyDown={handleKeyDown}
      onMouseEnter={() => setPointerPaused(true)}
      onMouseLeave={() => setPointerPaused(false)}
      onFocus={() => setFocusPaused(true)}
      onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setFocusPaused(false); }}
      onTouchStart={(event) => { touchStartX.current = event.changedTouches[0]?.clientX ?? null; }}
      onTouchEnd={(event) => {
        if (touchStartX.current === null) return;
        const delta = event.changedTouches[0].clientX - touchStartX.current;
        if (Math.abs(delta) > 45) move(delta < 0 ? 1 : -1);
        touchStartX.current = null;
      }}
      className="relative isolate flex min-h-[clamp(420px,68vh,760px)] w-full items-center justify-center overflow-hidden bg-[var(--color-primary)] px-4 text-center"
    >
      {current && !imageFailed && (
        <img
          key={current.id}
          src={assetUrl(current.image_url ?? null) ?? undefined}
          alt=""
          aria-hidden="true"
          fetchPriority={index === 0 ? "high" : "low"}
          loading={index === 0 ? "eager" : "lazy"}
          decoding="async"
          onLoad={() => setLoaded(true)}
          onError={() => setImageFailed(true)}
          className={`absolute inset-0 z-0 h-full w-full object-cover transition-opacity duration-700 ${loaded ? "opacity-100" : "opacity-0"}`}
        />
      )}
      <div className="absolute inset-0 z-0 bg-gradient-to-b from-black/55 via-black/55 to-black/80" />
      <div className="relative z-10 mx-auto flex w-full max-w-4xl flex-col items-center py-20 md:py-28">
        {announcement && <span className="mb-3 inline-block text-xs font-bold uppercase tracking-[0.22em] text-[var(--brand-gold)] md:text-sm">{announcement}</span>}
        <h1 className="mb-4 text-3xl font-extrabold leading-tight tracking-tight !text-white sm:text-4xl md:text-6xl">{title}</h1>
        <p className="mb-8 max-w-2xl text-base leading-relaxed text-white/90 sm:text-lg md:text-xl">{current?.description || subtitle}</p>
        <div className="flex flex-wrap items-center justify-center gap-4">
          {current?.hero_cta_text && current.hero_cta_url ? (
            <Link to={current.hero_cta_url} className="btn-accent min-h-[44px]">{current.hero_cta_text}</Link>
          ) : <Link to="/menu" className="btn-accent min-h-[44px]">Explore Our Menu</Link>}
          <Link to="/about" className="inline-flex min-h-[44px] items-center gap-2 rounded-full border-2 border-white/70 px-6 py-3 font-semibold text-white transition-all hover:border-white hover:bg-white/10">About Us</Link>
        </div>
      </div>
      {activeSlides.length > 1 && (
        <>
          <button type="button" aria-label="Previous featured image" onClick={() => move(-1)} className="absolute left-3 top-1/2 z-20 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-white/60 bg-black/35 text-white transition hover:bg-black/60 sm:left-6">
            <ChevronLeft aria-hidden="true" />
          </button>
          <button type="button" aria-label="Next featured image" onClick={() => move(1)} className="absolute right-3 top-1/2 z-20 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-white/60 bg-black/35 text-white transition hover:bg-black/60 sm:right-6">
            <ChevronRight aria-hidden="true" />
          </button>
          <div className="absolute bottom-5 left-1/2 z-20 flex -translate-x-1/2 gap-2" role="group" aria-label="Choose featured image">
            {activeSlides.map((slide, slideIndex) => (
              <button key={slide.id} type="button" aria-label={`Show image ${slideIndex + 1} of ${activeSlides.length}`} aria-current={slideIndex === index ? "true" : undefined} onClick={() => { setIndex(slideIndex); setManualPaused(true); window.setTimeout(() => setManualPaused(false), 5000); }} className={`h-3 rounded-full border border-white transition-all ${slideIndex === index ? "w-8 bg-white" : "w-3 bg-white/35 hover:bg-white/70"}`} />
            ))}
          </div>
        </>
      )}
      {current && <span className="sr-only" aria-live="polite">Featured image {index + 1} of {activeSlides.length}: {current.title}</span>}
    </section>
  );
}
