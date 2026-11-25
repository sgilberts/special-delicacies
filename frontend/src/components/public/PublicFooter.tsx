import { Link } from "react-router-dom";
import { MapPin, Phone, Mail, Clock } from "lucide-react";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";

const quickLinks = [
  { to: "/", label: "Home" },
  { to: "/menu", label: "Our Menu" },
  { to: "/gallery", label: "Gallery" },
  { to: "/about", label: "About Us" },
  { to: "/contact", label: "Contact" },
];

const CURRENT_YEAR = new Date().getFullYear();

export default function PublicFooter() {
  const { content } = useRestaurantContent();
  return (
    <footer className="border-t mt-auto"
      style={{
        background: "var(--color-surface-secondary)",
        borderColor: "var(--color-border)",
      }}>
      <div className="max-w-[1440px] mx-auto px-4 md:px-6 lg:px-10 py-12 md:py-16">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-10">
          {/* Brand */}
          <div>
            <div className="mb-4">
              {content.restaurant_logo && <img src={assetUrl(content.restaurant_logo) ?? undefined} alt={content.restaurant_name} className="mb-2 h-10 w-10 rounded-lg object-contain" />}
              <span className="block text-xs font-bold tracking-widest uppercase mb-1"
                style={{ color: "var(--color-accent)" }}>Special</span>
              <span className="block text-2xl font-bold"
                style={{ color: "var(--color-text)", letterSpacing: "-0.5px" }}>{content.restaurant_name}</span>
            </div>
            <p className="text-sm leading-relaxed"
              style={{ color: "var(--color-text-secondary)" }}>
              Fine dining and takeaway experience crafted with passion, fresh ingredients, and flavours that bring people together.
            </p>
          </div>

          {/* Quick Links */}
          <div>
            <h3 className="text-sm font-bold uppercase tracking-widest mb-4"
              style={{ color: "var(--color-text)" }}>Quick Links</h3>
            <ul className="space-y-2">
              {quickLinks.map(({ to, label }) => (
                <li key={to}>
                  <Link to={to}
                    className="text-sm transition-colors no-underline"
                    style={{ color: "var(--color-text-secondary)" }}>
                    {label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          {/* Contact Info */}
          <div>
            <h3 className="text-sm font-bold uppercase tracking-widest mb-4"
              style={{ color: "var(--color-text)" }}>Contact Us</h3>
            <ul className="space-y-3">
              <li className="flex items-start gap-3">
                <MapPin size={16} strokeWidth={1.8} className="mt-0.5 flex-shrink-0"
                  style={{ color: "var(--color-accent)" }} />
                <span className="text-sm" style={{ color: "var(--color-text-secondary)" }}>
                  {content.restaurant_address || "100 Gourmet Boulevard, Culinary Quarter"}
                </span>
              </li>
              <li className="flex items-center gap-3">
                <Phone size={16} strokeWidth={1.8} className="flex-shrink-0"
                  style={{ color: "var(--color-accent)" }} />
                <span className="text-sm" style={{ color: "var(--color-text-secondary)" }}>
                  {content.restaurant_phone || "+233 30 123 4567"}
                </span>
              </li>
              <li className="flex items-center gap-3">
                <Mail size={16} strokeWidth={1.8} className="flex-shrink-0"
                  style={{ color: "var(--color-accent)" }} />
                <span className="text-sm" style={{ color: "var(--color-text-secondary)" }}>
                  {content.restaurant_email || "contact@special-delicacies.local"}
                </span>
              </li>
              <li className="flex items-start gap-3">
                <Clock size={16} strokeWidth={1.8} className="mt-0.5 flex-shrink-0"
                  style={{ color: "var(--color-accent)" }} />
                <span className="text-sm" style={{ color: "var(--color-text-secondary)" }}>
                  {content.opening_hours_days || "Monday – Sunday: 11:00 AM – 10:00 PM"}
                </span>
              </li>
            </ul>
          </div>
        </div>

        <div className="mt-10 pt-8 border-t flex flex-col md:flex-row items-center justify-between gap-4"
          style={{ borderColor: "var(--color-border)" }}>
          <p className="text-xs" style={{ color: "var(--color-text-secondary)" }}>
            © {CURRENT_YEAR} Special Delicacies. All rights reserved.
          </p>
          <div className="flex items-center gap-4 text-xs" style={{ color: "var(--color-text-secondary)" }}>
            <span>Fine Dining &amp; Takeaway</span>
            <span>&bull;</span>
            <Link to="/admin" className="hover:text-[var(--color-accent)] transition-colors min-h-[30px] flex items-center">
              Staff Portal
            </Link>
          </div>
        </div>
      </div>
    </footer>
  );
}
