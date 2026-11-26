import { useState } from "react";
import { NavLink, Link } from "react-router-dom";
import { Menu, UserRound } from "lucide-react";
import ThemeToggle from "./ThemeToggle";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";

const navLinks = [
  { to: "/", label: "Home", end: true },
  { to: "/menu", label: "Menu" },
  { to: "/gallery", label: "Gallery" },
  { to: "/about", label: "About" },
  { to: "/contact", label: "Contact" },
];

export default function PublicHeader() {
  const [_mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const { content } = useRestaurantContent();
  const logoUrl = assetUrl(content.restaurant_logo);

  const navLinkClass = ({ isActive }: { isActive: boolean }) =>
    `text-sm font-semibold transition-colors px-3 py-2 rounded-lg ${
      isActive
        ? "text-[var(--color-accent)] bg-[var(--color-surface-secondary)]"
        : "text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]"
    }`;

  return (
    <header className="sticky top-0 z-40 w-full border-b"
      style={{ background: "var(--color-surface)", borderColor: "var(--color-border)" }}>
      <div className="max-w-[1440px] mx-auto px-4 md:px-6 lg:px-10">
        {/* Mobile header — shown below md */}
        <div className="flex items-center justify-between h-[60px] md:hidden">
          <Link to="/" className="flex flex-col leading-none no-underline"
            style={{ color: "inherit" }}>
            {logoUrl ? <img src={logoUrl} alt={content.restaurant_name} className="h-9 w-9 rounded-lg object-contain" /> : null}
            <span className="text-xs font-bold tracking-widest uppercase"
              style={{ color: "var(--color-accent)" }}>Special</span>
            <span className="text-lg font-bold"
              style={{ color: "var(--color-text)", letterSpacing: "-0.5px" }}>{content.restaurant_name}</span>
          </Link>
          <div className="flex items-center gap-2">
            <Link to="/account" aria-label="Customer account" className="grid h-10 w-10 place-items-center rounded-full" style={{ color: "var(--color-text-secondary)", background: "var(--color-surface-secondary)" }}><UserRound size={18} /></Link>
            <ThemeToggle />
            <button
              onClick={() => setMobileMenuOpen(o => !o)}
              className="flex items-center justify-center w-10 h-10 rounded-full transition-colors"
              style={{
                color: "var(--color-text-secondary)",
                background: "var(--color-surface-secondary)",
                border: "1px solid var(--color-border)",
              }}
              aria-label="Toggle menu"
            >
              <Menu size={18} />
            </button>
          </div>
        </div>

        {/* Tablet + Desktop header — shown at md+ */}
        <div className="hidden md:flex items-center justify-between h-[72px] gap-6">
          <Link to="/" className="flex flex-col leading-none no-underline flex-shrink-0"
            style={{ color: "inherit" }}>
            {logoUrl ? <img src={logoUrl} alt={content.restaurant_name} className="h-10 w-10 rounded-lg object-contain" /> : null}
            <span className="text-[11px] font-bold tracking-widest uppercase"
              style={{ color: "var(--color-accent)" }}>Special</span>
            <span className="text-xl lg:text-2xl font-bold"
              style={{ color: "var(--color-text)", letterSpacing: "-0.5px" }}>{content.restaurant_name}</span>
          </Link>

          {/* Desktop nav */}
          <nav className="flex items-center gap-1 lg:gap-2 flex-1 justify-center md:justify-end lg:justify-center">
            {navLinks.map(({ to, label, end }) => (
              <NavLink key={to} to={to} end={end} className={navLinkClass}>
                {label}
              </NavLink>
            ))}
          </nav>

          <div className="flex-shrink-0">
            <div className="flex items-center gap-2"><Link to="/account" className="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]" aria-label="Customer account"><UserRound size={17} />Account</Link><ThemeToggle /></div>
          </div>
        </div>
      </div>
    </header>
  );
}
