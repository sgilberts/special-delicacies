import { NavLink } from "react-router-dom";
import { Home, UtensilsCrossed, Images, Info, Phone } from "lucide-react";

const navItems = [
  { to: "/", label: "Home", icon: <Home size={22} strokeWidth={1.8} />, end: true },
  { to: "/menu", label: "Menu", icon: <UtensilsCrossed size={22} strokeWidth={1.8} /> },
  { to: "/gallery", label: "Gallery", icon: <Images size={22} strokeWidth={1.8} /> },
  { to: "/about", label: "About", icon: <Info size={22} strokeWidth={1.8} /> },
  { to: "/contact", label: "Contact", icon: <Phone size={22} strokeWidth={1.8} /> },
];

export default function MobileBottomNav() {
  return (
    <nav
      aria-label="Mobile navigation"
      className="fixed bottom-0 left-0 right-0 md:hidden z-50 border-t"
      style={{
        background: "var(--color-surface)",
        borderColor: "var(--color-border)",
        paddingBottom: "env(safe-area-inset-bottom, 0px)",
      }}
    >
      <div className="flex items-stretch h-[60px]">
        {navItems.map(({ to, label, icon, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className="flex-1 flex flex-col items-center justify-center gap-0.5 no-underline transition-colors"
            style={({ isActive }) => ({
              color: isActive ? "var(--color-accent)" : "var(--color-text-secondary)",
            })}
            aria-label={label}
          >
            {icon}
            <span className="text-[10px] font-semibold leading-none">{label}</span>
          </NavLink>
        ))}
      </div>
    </nav>
  );
}
