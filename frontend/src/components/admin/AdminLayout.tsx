import { useState, useEffect, useCallback } from "react";
import { NavLink, Outlet, useNavigate } from "react-router-dom";
import {
  LayoutDashboard,
  FolderTree,
  Utensils,
  Table2,
  QrCode,
  LogOut,
  Menu,
  X,
  User,
  ShieldCheck,
  ChefHat,
  ClipboardList,
  Radio,
  Truck,
  MapPin,
  Users,
  ShoppingBag,
  Sliders,
  Globe,
  History,
  WalletCards,
  MessageCircle,
  Contact,
  Building2,
  FileText,
  RotateCcw,
  FileMinus,
  Undo2,
  BarChart3,
  Receipt,
  Layers,
  FileClock,
  Coins,
  Scale,
} from "lucide-react";
import { useAuth } from "@/app/providers/useAuth";
import ThemeToggle from "@/components/public/ThemeToggle";
import type { AuthUser } from "@/types/api";
import type { PublicContentResponse } from "@/types/api";
import { Link } from "react-router-dom";
import { useConfirm } from "@/components/ui/ConfirmDialog";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";
import { cachedGet } from "@/lib/api";
import { getStaffSecurityVisibility, hasAnyPermission } from "@/lib/permissionAccess";

const COLLAPSE_KEY = "sd_admin_sidebar_collapsed";

interface NavSection {
  heading: string;
  items: NavItem[];
}

interface NavItem {
  label: string;
  to: string;
  icon: React.ElementType;
  end?: boolean;
}

function getNavSections(user: AuthUser | null | undefined): NavSection[] {
  const isSuperAdmin = user?.roles?.includes("super-admin") ?? false;
  const userPermissions = user?.permissions ?? [];

  const operationsItems: NavItem[] = [];

  if (
    isSuperAdmin ||
    userPermissions.includes("orders.confirm") ||
    userPermissions.includes("orders.deliver") ||
    userPermissions.includes("orders.complete")
  ) {
    operationsItems.push({
      label: "Waiter Dashboard",
      to: "/admin/waiter",
      icon: ClipboardList,
    });
  }

  if (
    isSuperAdmin ||
    userPermissions.includes("orders.prepare") ||
    userPermissions.includes("orders.ready")
  ) {
    operationsItems.push({
      label: "Kitchen Dashboard",
      to: "/admin/kitchen",
      icon: ChefHat,
    });
  }

  if (
    isSuperAdmin ||
    userPermissions.includes("delivery.orders.view") ||
    userPermissions.includes("delivery.orders.dispatch")
  ) {
    operationsItems.push({
      label: "Delivery Dashboard",
      to: "/admin/delivery",
      icon: Truck,
    });
  }

  if (
    isSuperAdmin ||
    userPermissions.includes("orders.view")
  ) {
    operationsItems.push({
      label: "Order Oversight",
      to: "/admin/orders",
      icon: ShoppingBag,
    });
  }

  if (isSuperAdmin || userPermissions.includes("reverb.monitor")) {
    operationsItems.push({
      label: "Reverb Monitor",
      to: "/admin/reverb",
      icon: Radio,
    });
  }

  const sections: NavSection[] = [
    {
      heading: "Main",
      items: [
        { label: "Dashboard", to: "/admin", icon: LayoutDashboard, end: true },
      ],
    },
  ];

  const financeItems: NavItem[] = [];
  if (isSuperAdmin || userPermissions.includes("financials.view")) financeItems.push({ label: "Financial Management", to: "/admin/financials", icon: WalletCards });
  if (hasAnyPermission(userPermissions, ["financials.allocations.view", "financials.allocations.manage"], user?.roles)) {
    financeItems.push({ label: "Payment Allocations", to: "/admin/payment-allocations", icon: WalletCards });
  }
  if (hasAnyPermission(userPermissions, ["financials.deposits.view", "financials.deposits.manage", "financials.customer_credit.view", "financials.customer_credit.manage"], user?.roles)) {
    financeItems.push({ label: "Deposits & Credit", to: "/admin/customer-credit", icon: WalletCards });
  }
  if (hasAnyPermission(userPermissions, ["financials.refunds.view", "financials.refunds.initiate", "financials.refunds.approve"], user?.roles)) {
    financeItems.push({ label: "Refunds", to: "/admin/refunds", icon: RotateCcw });
  }
  if (hasAnyPermission(userPermissions, ["financials.credit_notes.view", "financials.credit_notes.create", "financials.credit_notes.approve"], user?.roles)) {
    financeItems.push({ label: "Credit Notes", to: "/admin/credit-notes", icon: FileMinus });
  }
  if (hasAnyPermission(userPermissions, ["financials.credit_reversals.view", "financials.credit_reversals.initiate", "financials.credit_reversals.approve"], user?.roles)) {
    financeItems.push({ label: "Credit Reversals", to: "/admin/credit-reversals", icon: Undo2 });
  }
  if (financeItems.length > 0) sections.push({ heading: "Finance", items: financeItems });

  // Stage 4D financial reporting. Each entry appears only for its own report
  // permission; the backend enforces the same permission on the endpoint.
  const reportItems: NavItem[] = [];
  if (hasAnyPermission(userPermissions, ["financials.reports.dashboard.view"], user?.roles)) {
    reportItems.push({ label: "Financial Dashboard", to: "/admin/financials/dashboard", icon: BarChart3 });
  }
  if (hasAnyPermission(userPermissions, ["financials.reports.receipts.view"], user?.roles)) {
    reportItems.push({ label: "Receipt Register", to: "/admin/financials/reports/receipts", icon: Receipt });
  }
  if (hasAnyPermission(userPermissions, ["financials.reports.allocations.view"], user?.roles)) {
    reportItems.push({ label: "Allocation Report", to: "/admin/financials/reports/allocations", icon: Layers });
  }
  if (hasAnyPermission(userPermissions, ["financials.reports.unallocated.view"], user?.roles)) {
    reportItems.push({ label: "Unallocated Receipts", to: "/admin/financials/reports/unallocated", icon: WalletCards });
  }
  if (hasAnyPermission(userPermissions, ["financials.reports.receivables.view"], user?.roles)) {
    reportItems.push({ label: "Accounts Receivable", to: "/admin/financials/reports/receivables", icon: FileClock });
  }
  if (hasAnyPermission(userPermissions, ["financials.reports.customer_credit.view"], user?.roles)) {
    reportItems.push({ label: "Customer Credit", to: "/admin/financials/reports/customer-credit", icon: Coins });
  }
  if (hasAnyPermission(userPermissions, ["financials.reports.organisation_credit.view"], user?.roles)) {
    reportItems.push({ label: "Organisation Credit", to: "/admin/financials/reports/organisation-credit", icon: Building2 });
  }
  if (hasAnyPermission(userPermissions, ["financials.reports.refunds.view"], user?.roles)) {
    reportItems.push({ label: "Refund Reports", to: "/admin/financials/reports/refunds", icon: RotateCcw });
  }
  if (hasAnyPermission(userPermissions, ["financials.reports.credit_notes.view"], user?.roles)) {
    reportItems.push({ label: "Credit Note Reports", to: "/admin/financials/reports/credit-notes", icon: FileMinus });
  }
  if (hasAnyPermission(userPermissions, ["financials.reports.activity.view"], user?.roles)) {
    reportItems.push({ label: "Financial Activity", to: "/admin/financials/reports/activity", icon: History });
  }
  if (hasAnyPermission(userPermissions, ["financials.reconciliation.view"], user?.roles)) {
    reportItems.push({ label: "Reconciliation", to: "/admin/financials/reconciliation", icon: Scale });
  }
  if (reportItems.length > 0) sections.push({ heading: "Financial Reports", items: reportItems });

  if (hasAnyPermission(userPermissions, ["commercial_documents.view", "commercial_documents.manage"], user?.roles)) {
    sections.push({ heading: "Commercial", items: [{ label: "Quotations & Invoices", to: "/admin/commercial-documents", icon: FileText }] });
  }

  // Customer Support groups the customer record and the support inbox. Each
  // item appears only for the capability it needs, so a customer administrator
  // without chat access still gets the section.
  const customerSupportItems: NavItem[] = [];
  if (isSuperAdmin || userPermissions.some((permission) => ["chat.view_assigned", "chat.view_all"].includes(permission))) {
    customerSupportItems.push({ label: "Live Chat Inbox", to: "/admin/chat", icon: MessageCircle });
    if (isSuperAdmin || userPermissions.includes("chat.manage_settings")) {
      customerSupportItems.push({ label: "Chat Availability", to: "/admin/chat/settings", icon: MessageCircle });
    }
  }
  if (hasAnyPermission(userPermissions, ["customers.view"], user?.roles)) {
    customerSupportItems.push({ label: "Customers", to: "/admin/customers", icon: Contact });
  }
  if (hasAnyPermission(userPermissions, ["organisations.view"], user?.roles)) {
    customerSupportItems.push({ label: "Organisations", to: "/admin/organisations", icon: Building2 });
  }
  if (customerSupportItems.length > 0) {
    sections.push({ heading: "Customer Support", items: customerSupportItems });
  }

  if (operationsItems.length > 0) {
    sections.push({
      heading: "Operations",
      items: operationsItems,
    });
  }

  const menuItems: NavItem[] = [];
  if (isSuperAdmin || userPermissions.some((permission) => ["menu.categories.view", "menu.categories.create", "menu.manage"].includes(permission))) {
    menuItems.push({ label: "Categories", to: "/admin/categories", icon: FolderTree });
  }
  if (isSuperAdmin || userPermissions.some((permission) => ["menu.items.view", "menu.items.create", "menu.manage"].includes(permission))) {
    menuItems.push({ label: "Menu Items", to: "/admin/items", icon: Utensils });
  }
  if (menuItems.length) sections.push({ heading: "Menu Management", items: menuItems });

  const restaurantItems: NavItem[] = [];
  if (isSuperAdmin || userPermissions.some((permission) => ["tables.view", "tables.create", "tables.manage"].includes(permission))) {
    restaurantItems.push({ label: "Tables", to: "/admin/tables", icon: Table2 });
  }
  if (isSuperAdmin || userPermissions.some((permission) => ["tables.qr.view", "tables.qr.generate", "tables.manage"].includes(permission))) {
    restaurantItems.push({ label: "QR Codes", to: "/admin/qr-codes", icon: QrCode });
  }
  if (restaurantItems.length) sections.push({ heading: "Restaurant Management", items: restaurantItems });

  if (
    isSuperAdmin ||
    userPermissions.includes("delivery_zones.view") ||
    userPermissions.includes("delivery_zones.manage")
  ) {
    sections.push({
      heading: "Delivery Management",
      items: [
        { label: "Delivery Zones", to: "/admin/delivery-zones", icon: MapPin },
      ],
    });
  }

  // Staff and role administration are separate capabilities. Treat this
  // section as a grouping container and include only individually permitted items.
  const staffSecurityItems: NavItem[] = [];
  const staffSecurityVisibility = getStaffSecurityVisibility(userPermissions);
  if (staffSecurityVisibility.staffAccounts) {
    staffSecurityItems.push({ label: "Staff Accounts", to: "/admin/staff", icon: Users });
  }
  if (staffSecurityVisibility.rolesAndPermissions) {
    staffSecurityItems.push({ label: "Roles & Permissions", to: "/admin/roles", icon: ShieldCheck });
  }
  if (staffSecurityItems.length > 0) {
    sections.push({ heading: "Staff & Security", items: staffSecurityItems });
  }

  // Phase 9: System & Content
  if (
    isSuperAdmin ||
    userPermissions.includes("settings.view") ||
    userPermissions.includes("settings.manage") ||
    userPermissions.includes("content.manage") ||
    userPermissions.includes("audit.view")
  ) {
    const systemItems: NavItem[] = [];

    if (isSuperAdmin || userPermissions.includes("settings.view") || userPermissions.includes("settings.manage")) {
      systemItems.push({ label: "Restaurant Settings", to: "/admin/settings", icon: Sliders });
    }

    if (isSuperAdmin || userPermissions.includes("content.manage")) {
      systemItems.push({ label: "Website Content", to: "/admin/content", icon: Globe });
    }

    if (isSuperAdmin || userPermissions.includes("audit.view")) {
      systemItems.push({ label: "Audit Trail", to: "/admin/audit-logs", icon: History });
    }

    if (systemItems.length > 0) {
      sections.push({
        heading: "Administration",
        items: systemItems,
      });
    }
  }

  if (user) sections.push({ heading: "My Account", items: [{ label: "My Profile", to: "/admin/profile", icon: User }] });
  return sections;
}

export default function AdminLayout() {
  const { user, logout } = useAuth();
  const { content } = useRestaurantContent();
  const navigate = useNavigate();
  const confirm = useConfirm();

  const [collapsed, setCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem(COLLAPSE_KEY) === "true";
    } catch {
      return false;
    }
  });
  const [mobileOpen, setMobileOpen] = useState(false);

  const toggleCollapse = useCallback(() => {
    setCollapsed((prev) => {
      const next = !prev;
      try {
        localStorage.setItem(COLLAPSE_KEY, String(next));
      } catch {
        // ignore
      }
      return next;
    });
  }, []);

  const handleLogout = async () => {
    if (!await confirm({ title: "Log out?", message: "You will need to sign in again to access the staff workspace.", confirmLabel: "Log out", tone: "danger" })) return;
    await logout();
    navigate("/admin/login");
  };

  // Close mobile drawer on resize to desktop
  useEffect(() => {
    const onResize = () => {
      if (window.innerWidth >= 1024) setMobileOpen(false);
    };
    window.addEventListener("resize", onResize);
    return () => window.removeEventListener("resize", onResize);
  }, []);

  const sidebarWidth = collapsed ? 64 : 256;

  return (
    <div className="h-screen flex overflow-hidden bg-[var(--color-background)] text-[var(--color-text)]">
      {/* ── Mobile backdrop ── */}
      {mobileOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 lg:hidden"
          onClick={() => setMobileOpen(false)}
          aria-hidden="true"
        />
      )}

      {/* ── SIDEBAR (desktop: flex item; mobile: fixed drawer) ── */}
      {/* Desktop sidebar */}
      <aside
        className="hidden lg:flex flex-col flex-shrink-0 h-full border-r border-[var(--color-border)] bg-[var(--color-surface)] transition-all duration-300 overflow-hidden"
        style={{ width: sidebarWidth }}
        aria-label="Admin Sidebar"
      >
        <SidebarContent
          collapsed={collapsed}
          onNavClick={() => {}}
          onLogout={handleLogout}
          user={user}
          content={content}
        />
      </aside>

      {/* Mobile drawer */}
      <aside
        className={`fixed inset-y-0 left-0 z-50 flex flex-col w-64 h-full border-r border-[var(--color-border)] bg-[var(--color-surface)] transition-transform duration-300 lg:hidden ${
          mobileOpen ? "translate-x-0" : "-translate-x-full"
        }`}
        aria-label="Admin Sidebar Mobile"
      >
        {/* Mobile close button */}
        <div className="absolute top-3 right-3 z-10">
          <button
            type="button"
            onClick={() => setMobileOpen(false)}
            className="p-1.5 rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)] transition-colors"
            aria-label="Close menu"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        <SidebarContent
          collapsed={false}
          onNavClick={() => setMobileOpen(false)}
          onLogout={handleLogout}
          user={user}
          content={content}
        />
      </aside>

      {/* ── MAIN AREA ── */}
      <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
        {/* Top header */}
        <header className="sticky top-0 z-30 h-16 flex items-center gap-3 px-4 border-b border-[var(--color-border)] bg-[var(--color-surface)] shadow-sm flex-shrink-0">
          {/* Hamburger / collapse toggle */}
          <button
            type="button"
            onClick={() => {
              if (window.innerWidth >= 1024) {
                toggleCollapse();
              } else {
                setMobileOpen(true);
              }
            }}
            className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center"
            aria-label="Toggle sidebar"
          >
            <Menu className="w-5 h-5" />
          </button>

          {/* Title */}
          <div className="flex-1 min-w-0">
            <span className="flex items-center gap-2 text-sm font-bold truncate text-[var(--color-text)]">
              {content.restaurant_logo && <img src={assetUrl(content.restaurant_logo) ?? undefined} alt="" className="h-8 w-8 rounded-lg border border-[var(--color-border)] bg-[var(--color-background)] object-contain p-0.5" />}
              {content.restaurant_name || "Special Delicacies"} Admin
            </span>
          </div>

          {/* Right controls */}
          <div className="flex items-center gap-2">
            <ThemeToggle />
            <div className="hidden sm:flex items-center gap-2 pl-1">
              <Link to="/admin/profile"
                className="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs"
                style={{
                  background: "var(--color-surface-secondary)",
                  color: "var(--color-accent)",
                }}
                title={user?.email}
              >
                {user?.profile_photo_url ? <img src={assetUrl(user.profile_photo_url) ?? undefined} alt="" className="h-full w-full rounded-full object-cover" /> : <User className="w-4 h-4" />}
              </Link>
              <div className="flex flex-col text-left">
                <span className="text-xs font-bold leading-tight truncate max-w-[100px]">
                  {user?.name || "Admin"}
                </span>
                <span className="text-[10px] text-[var(--color-text-secondary)] uppercase tracking-wider flex items-center gap-1">
                  <ShieldCheck className="w-3 h-3 text-[var(--color-success)]" />
                  {user?.roles?.[0]?.replaceAll("-", " ") || "Staff"}
                </span>
              </div>
            </div>
            <button
              type="button"
              onClick={handleLogout}
              className="p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-danger)] hover:bg-[var(--color-surface-secondary)] transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center"
              title="Log Out"
              aria-label="Log Out"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
          <Outlet />
        </main>
      </div>
    </div>
  );
}

// ── Sidebar inner content ──────────────────────────────────────────────────────

interface SidebarContentProps {
  collapsed: boolean;
  onNavClick: () => void;
  onLogout: () => void;
  user: AuthUser | null | undefined;
  content: PublicContentResponse;
}

function SidebarContent({ collapsed, onNavClick, onLogout, user, content }: SidebarContentProps) {
  return (
    <div className="flex flex-col h-full overflow-hidden">
      {/* Brand header */}
      <div
        className={`flex items-center gap-3 px-4 py-5 border-b border-[var(--color-border)] flex-shrink-0 ${
          collapsed ? "justify-center" : ""
        }`}
      >
        {content.restaurant_logo ? <img src={assetUrl(content.restaurant_logo) ?? undefined} alt="" className="w-10 h-10 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] object-contain p-1 flex-shrink-0 shadow-sm" /> : <div className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm bg-[var(--color-primary)]"><Utensils className="w-5 h-5 text-[var(--color-background)]" /></div>}
        {!collapsed && (
          <div className="min-w-0">
            <span className="font-extrabold text-sm tracking-tight block leading-tight text-[var(--color-text)] truncate">
              {content.restaurant_name || "Special Delicacies"}
            </span>
            <span className="text-[10px] font-semibold uppercase tracking-wider text-[var(--color-accent)]">
              Administration
            </span>
          </div>
        )}
      </div>

      {/* Nav sections */}
      <nav className="flex-1 overflow-y-auto py-4 px-2 space-y-4" aria-label="Sidebar navigation">
        {getNavSections(user).map((section) => (
          <div key={section.heading}>
            {!collapsed && (
              <p className="px-3 mb-1 text-[10px] font-bold uppercase tracking-widest text-[var(--color-text-secondary)]">
                {section.heading}
              </p>
            )}
            <div className="space-y-0.5">
              {section.items.map((item) => {
                const Icon = item.icon;
                const prefetch = () => {
                  const routes: Record<string, string> = {
                    "/admin": "/admin/dashboard/stats?period=today",
                    "/admin/orders": "/admin/orders?page=1&per_page=20",
                    "/admin/financials": "/admin/financials/snapshot",
                    "/admin/categories": "/admin/menu/categories",
                    "/admin/menu-overview": "/admin/menu/overview",
                    "/admin/settings": "/admin/settings",
                    "/admin/delivery-zones": "/admin/delivery-zones",
                  };
                  const endpoint = routes[item.to];
                  if (endpoint) void cachedGet(endpoint, 10_000).catch(() => undefined);
                };
                return (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    onClick={onNavClick}
                    onMouseEnter={prefetch}
                    title={collapsed ? item.label : undefined}
                    className={({ isActive }) =>
                      `flex items-center gap-3 rounded-lg text-sm font-semibold transition-all min-h-[42px] ${
                        collapsed ? "justify-center px-0" : "px-3"
                      } ${
                        isActive
                          ? "bg-[var(--color-surface-secondary)] text-[var(--color-accent)] font-bold"
                          : "text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]/50"
                      }`
                    }
                  >
                    <Icon className="w-5 h-5 shrink-0" />
                    {!collapsed && <span className="truncate">{item.label}</span>}
                  </NavLink>
                );
              })}
            </div>
          </div>
        ))}
      </nav>

      {/* Bottom: user info + logout */}
      <div className="border-t border-[var(--color-border)] p-3 flex-shrink-0 space-y-1">
        {!collapsed && (
          <div className="flex items-center gap-2 px-2 py-1 mb-1">
            <Link to="/admin/profile"
              className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden"
              style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
            >
              {user?.profile_photo_url ? <img src={assetUrl(user.profile_photo_url) ?? undefined} alt="" className="h-full w-full object-cover" /> : <User className="w-3.5 h-3.5" />}
            </Link>
            <div className="min-w-0">
              <p className="text-xs font-bold truncate">{user?.name || "Admin"}</p>
              <p className="text-[10px] text-[var(--color-text-secondary)] truncate">{user?.email}</p>
            </div>
          </div>
        )}
        <button
          type="button"
          onClick={onLogout}
          title={collapsed ? "Log Out" : undefined}
          className={`w-full flex items-center gap-3 rounded-lg min-h-[42px] text-sm font-semibold transition-colors text-[var(--color-text-secondary)] hover:text-[var(--color-danger)] hover:bg-red-50/10 ${
            collapsed ? "justify-center px-0" : "px-3"
          }`}
        >
          <LogOut className="w-5 h-5 shrink-0" />
          {!collapsed && <span>Logout</span>}
        </button>
      </div>
    </div>
  );
}

