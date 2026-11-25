import { useEffect, useState, useCallback, useMemo } from "react";
import { useNavigate } from "react-router-dom";
import {
  ShoppingCart,
  Search,
  Plus,
  Minus,
  X,
  Trash2,
  Bike,
  ChevronRight,
  UtensilsCrossed,
} from "lucide-react";
import { cachedGet } from "@/lib/api";
import { assetUrl } from "@/lib/assetUrl";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import type { ApiResponse, MenuCategory, MenuItem, DeliveryZone } from "@/types/api";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ThemeToggle from "@/components/public/ThemeToggle";

// ─── Types ───────────────────────────────────────────────────────────────────

export interface CartEntry {
  menu_item_id: number;
  item: MenuItem;
  quantity: number;
  special_instructions: string;
}

type CartState = Record<number, CartEntry>;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatPrice(price: string): string {
  const val = parseFloat(price);
  return isNaN(val) ? `GH₵ ${price}` : `GH₵ ${val.toFixed(2)}`;
}

function formatCurrency(val: number): string {
  return `GH₵ ${val.toFixed(2)}`;
}

function cartToArray(cart: CartState): CartEntry[] {
  return Object.values(cart);
}

function cartTotal(cart: CartState): number {
  return cartToArray(cart).reduce(
    (sum, e) => sum + parseFloat(e.item.price) * e.quantity,
    0
  );
}

function cartItemCount(cart: CartState): number {
  return cartToArray(cart).reduce((sum, e) => sum + e.quantity, 0);
}

function saveCartToSession(cart: CartState) {
  try {
    sessionStorage.setItem("delivery_cart", JSON.stringify(cartToArray(cart)));
  } catch {
    // ignore
  }
}

function saveZonesToSession(zones: DeliveryZone[]) {
  try {
    sessionStorage.setItem("delivery_zones", JSON.stringify(zones));
  } catch {
    // ignore
  }
}

// ─── Sub-components ──────────────────────────────────────────────────────────

function MenuItemCard({
  item,
  quantity,
  onAdd,
  onRemove,
}: {
  item: MenuItem;
  quantity: number;
  onAdd: (item: MenuItem) => void;
  onRemove: (itemId: number) => void;
}) {
  const imageUrl = assetUrl(item.image_url || item.image_path);

  return (
    <div className="bg-white dark:bg-stone-800 rounded-xl shadow-sm border border-stone-200 dark:border-stone-700 overflow-hidden flex flex-col">
      {imageUrl && (
        <div className="h-40 w-full overflow-hidden bg-stone-100 dark:bg-stone-700 flex-shrink-0">
          <img
            src={imageUrl}
            alt={item.name}
            className="w-full h-full object-cover"
            onError={(e) => {
              (e.currentTarget as HTMLImageElement).style.display = "none";
            }}
          />
        </div>
      )}
      <div className="p-4 flex flex-col flex-1">
        <div className="flex-1">
          <h3 className="font-semibold text-stone-900 dark:text-stone-100 text-sm leading-tight mb-1">
            {item.name}
          </h3>
          {item.description && (
            <p className="text-xs text-stone-500 dark:text-stone-400 line-clamp-2 mb-2">
              {item.description}
            </p>
          )}
        </div>
        <div className="flex items-center justify-between mt-3 gap-2">
          <span className="font-bold text-sm" style={{ color: "var(--color-accent)" }}>
            {formatPrice(item.price)}
          </span>
          {quantity === 0 ? (
            <button
              onClick={() => onAdd(item)}
              className="flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold transition-colors text-white"
              style={{ backgroundColor: "var(--color-primary)" }}
            >
              <Plus size={14} />
              Add
            </button>
          ) : (
            <div className="flex items-center gap-2">
              <button
                onClick={() => onRemove(item.id)}
                className="w-7 h-7 rounded-full flex items-center justify-center transition-colors text-white"
                style={{ backgroundColor: "var(--color-primary)" }}
                aria-label="Decrease quantity"
              >
                <Minus size={14} />
              </button>
              <span className="text-sm font-bold w-5 text-center text-stone-900 dark:text-stone-100">
                {quantity}
              </span>
              <button
                onClick={() => onAdd(item)}
                className="w-7 h-7 rounded-full flex items-center justify-center transition-colors text-white"
                style={{ backgroundColor: "var(--color-primary)" }}
                aria-label="Increase quantity"
              >
                <Plus size={14} />
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function CartPanel({
  cart,
  onClose,
  onAdd,
  onRemove,
  onClearItem,
  onCheckout,
}: {
  cart: CartState;
  onClose: () => void;
  onAdd: (item: MenuItem) => void;
  onRemove: (itemId: number) => void;
  onClearItem: (itemId: number) => void;
  onCheckout: () => void;
}) {
  const entries = cartToArray(cart);
  const total = cartTotal(cart);

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div
        className="flex items-center justify-between px-4 py-3 border-b border-stone-200 dark:border-stone-700 flex-shrink-0"
        style={{ backgroundColor: "var(--color-primary)" }}
      >
        <div className="flex items-center gap-2">
          <ShoppingCart size={18} className="text-amber-300" />
          <span className="font-semibold text-white text-sm">Your Cart</span>
          {entries.length > 0 && (
            <span
              className="text-xs font-bold px-2 py-0.5 rounded-full text-white"
              style={{ backgroundColor: "var(--color-primary)" }}
            >
              {cartItemCount(cart)} items
            </span>
          )}
        </div>
        <button
          onClick={onClose}
          className="text-stone-300 hover:text-white transition-colors"
          aria-label="Close cart"
        >
          <X size={18} />
        </button>
      </div>

      {/* Items */}
      <div className="flex-1 overflow-y-auto px-4 py-3 space-y-3 bg-stone-50 dark:bg-stone-900">
        {entries.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-full py-12 text-center gap-3">
            <ShoppingCart size={40} className="text-stone-300 dark:text-stone-600" />
            <p className="text-stone-500 dark:text-stone-400 text-sm font-medium">
              Your cart is empty
            </p>
            <p className="text-stone-400 dark:text-stone-500 text-xs">
              Add items from the menu to start ordering
            </p>
          </div>
        ) : (
          entries.map((entry) => (
            <div
              key={entry.menu_item_id}
              className="bg-white dark:bg-stone-800 rounded-lg p-3 border border-stone-200 dark:border-stone-700"
            >
              <div className="flex items-start justify-between gap-2">
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-semibold text-stone-900 dark:text-stone-100 truncate">
                    {entry.item.name}
                  </p>
                  <p className="text-xs text-stone-500 dark:text-stone-400 mt-0.5">
                    {formatPrice(entry.item.price)} each
                  </p>
                </div>
                <button
                  onClick={() => onClearItem(entry.menu_item_id)}
                  className="text-stone-400 hover:text-red-500 transition-colors flex-shrink-0"
                  aria-label={`Remove ${entry.item.name}`}
                >
                  <Trash2 size={14} />
                </button>
              </div>
              <div className="flex items-center justify-between mt-2">
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => onRemove(entry.menu_item_id)}
                    className="w-7 h-7 rounded-full flex items-center justify-center text-white"
                    style={{ backgroundColor: "var(--color-primary)" }}
                  >
                    <Minus size={12} />
                  </button>
                  <span className="text-sm font-bold text-stone-900 dark:text-stone-100 w-5 text-center">
                    {entry.quantity}
                  </span>
                  <button
                    onClick={() => onAdd(entry.item)}
                    className="w-7 h-7 rounded-full flex items-center justify-center text-white"
                    style={{ backgroundColor: "var(--color-primary)" }}
                  >
                    <Plus size={12} />
                  </button>
                </div>
                <span className="font-bold text-sm" style={{ color: "var(--color-accent)" }}>
                  {formatCurrency(parseFloat(entry.item.price) * entry.quantity)}
                </span>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Footer */}
      {entries.length > 0 && (
        <div
          className="border-t border-stone-200 dark:border-stone-700 px-4 py-4 flex-shrink-0 bg-white dark:bg-stone-800 space-y-3"
        >
          <div className="flex items-center justify-between">
            <span className="text-sm text-stone-500 dark:text-stone-400 font-medium">Subtotal</span>
            <span className="font-bold text-stone-900 dark:text-stone-100">
              {formatCurrency(total)}
            </span>
          </div>
          <p className="text-xs text-stone-400 dark:text-stone-500">
            Delivery fee selected at checkout
          </p>
          <button
            onClick={onCheckout}
            className="w-full flex items-center justify-center gap-2 py-3 rounded-xl font-semibold text-white transition-opacity hover:opacity-90"
            style={{ backgroundColor: "var(--color-primary)" }}
          >
            Go to Checkout
            <ChevronRight size={16} />
          </button>
        </div>
      )}
    </div>
  );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function DeliveryPage() {
  const { content } = useRestaurantContent();
  const navigate = useNavigate();

  const [categories, setCategories] = useState<MenuCategory[]>([]);
  const [, setZones] = useState<DeliveryZone[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | null>(null);
  const [search, setSearch] = useState("");
  const [cart, setCart] = useState<CartState>({});
  const [cartOpen, setCartOpen] = useState(false);

  // Set page title
  useEffect(() => {
    document.title = "Home Delivery | Special Delicacies";
  }, []);

  // Fetch menu + zones
  useEffect(() => {
    let ignore = false;

    async function load() {
      try {
        const [menuRes, zonesRes] = await Promise.all([
          cachedGet<ApiResponse<MenuCategory[]>>("/menu"),
          cachedGet<ApiResponse<DeliveryZone[]>>("/delivery-zones"),
        ]);
        if (ignore) return;

        if (menuRes.data?.success && Array.isArray(menuRes.data.data)) {
          const cats = menuRes.data.data;
          setCategories(cats);
          if (cats.length > 0) setSelectedCategoryId(cats[0].id);
        }

        if (zonesRes.data?.success && Array.isArray(zonesRes.data.data)) {
          const activeZones = zonesRes.data.data.filter((z) => z.is_active);
          setZones(activeZones);
          saveZonesToSession(activeZones);
        }
      } catch {
        if (!ignore) setError("Unable to load menu. Please check your connection and try again.");
      } finally {
        if (!ignore) setLoading(false);
      }
    }

    load();
    return () => { ignore = true; };
  }, []);

  // Cart handlers
  const handleAdd = useCallback((item: MenuItem) => {
    setCart((prev) => {
      const existing = prev[item.id];
      const updated: CartState = {
        ...prev,
        [item.id]: {
          menu_item_id: item.id,
          item,
          quantity: existing ? existing.quantity + 1 : 1,
          special_instructions: existing?.special_instructions ?? "",
        },
      };
      saveCartToSession(updated);
      return updated;
    });
  }, []);

  const handleRemove = useCallback((itemId: number) => {
    setCart((prev) => {
      const existing = prev[itemId];
      if (!existing) return prev;
      let updated: CartState;
      if (existing.quantity <= 1) {
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { [itemId]: _, ...rest } = prev;
        updated = rest;
      } else {
        updated = {
          ...prev,
          [itemId]: { ...existing, quantity: existing.quantity - 1 },
        };
      }
      saveCartToSession(updated);
      return updated;
    });
  }, []);

  const handleClearItem = useCallback((itemId: number) => {
    setCart((prev) => {
      // eslint-disable-next-line @typescript-eslint/no-unused-vars
      const { [itemId]: _, ...rest } = prev;
      saveCartToSession(rest);
      return rest;
    });
  }, []);

  const handleCheckout = useCallback(() => {
    saveCartToSession(cart);
    navigate("/delivery/checkout");
  }, [cart, navigate]);

  // Filtered items
  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase();
    const allItems: MenuItem[] = categories.flatMap((c) =>
      c.available_items ?? c.items ?? []
    );

    if (q) {
      return allItems.filter(
        (item) =>
          item.name.toLowerCase().includes(q) ||
          (item.description ?? "").toLowerCase().includes(q)
      );
    }

    if (selectedCategoryId !== null) {
      const cat = categories.find((c) => c.id === selectedCategoryId);
      return cat?.available_items ?? cat?.items ?? [];
    }

    return allItems;
  }, [categories, selectedCategoryId, search]);

  const itemCount = cartItemCount(cart);
  const subtotal = cartTotal(cart);

  return (
    <div className="min-h-screen bg-stone-50 dark:bg-stone-950 flex flex-col">
      {/* Header */}
      <header
        className="sticky top-0 z-40 shadow-md"
        style={{ backgroundColor: "var(--color-primary)" }}
      >
        <div className="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between gap-4">
          {/* Logo */}
          <div className="flex items-center gap-2 flex-shrink-0">
            <UtensilsCrossed size={22} className="text-amber-400" />
            <div className="leading-tight">
              {content.restaurant_logo && <img src={assetUrl(content.restaurant_logo) ?? undefined} alt={content.restaurant_name} className="h-8 w-8 rounded-lg object-contain" />}
              <span className="text-white font-bold text-sm block">{content.restaurant_name}</span>
              <span
                className="text-xs font-semibold px-1.5 py-0.5 rounded"
                style={{ backgroundColor: "var(--color-primary)", color: "var(--color-background)" }}
              >
                Home Delivery
              </span>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <ThemeToggle />
            {/* Cart button */}
            <button
              onClick={() => setCartOpen(true)}
              className="relative flex items-center gap-2 px-4 py-2 rounded-full font-semibold text-white text-sm transition-opacity hover:opacity-90"
              style={{ backgroundColor: "var(--color-primary)" }}
              aria-label={`Cart with ${itemCount} items`}
            >
              <ShoppingCart size={18} />
              <span className="hidden sm:inline">Cart</span>
              {itemCount > 0 && (
                <span className="absolute -top-2 -right-2 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold text-white bg-green-500">
                  {itemCount > 99 ? "99+" : itemCount}
                </span>
              )}
            </button>
          </div>
        </div>
      </header>

      {/* Body */}
      <div className="flex flex-1 max-w-7xl mx-auto w-full px-4 py-6 gap-6">
        {/* Main content */}
        <main className="flex-1 min-w-0">
          {/* Search */}
          <div className="relative mb-5">
            <Search
              size={16}
              className="absolute left-3 top-1/2 -translate-y-1/2 text-stone-400"
            />
            <input
              type="text"
              placeholder="Search menu items…"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                if (e.target.value) setSelectedCategoryId(null);
              }}
              className="w-full pl-9 pr-4 py-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 placeholder-stone-400 text-sm focus:outline-none focus:ring-2"
              style={{ "--tw-ring-color": "var(--color-accent)" } as React.CSSProperties}
            />
            {search && (
              <button
                onClick={() => setSearch("")}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-stone-400 hover:text-stone-600"
                aria-label="Clear search"
              >
                <X size={14} />
              </button>
            )}
          </div>

          {/* Category tabs */}
          {!search && categories.length > 0 && (
            <div className="flex gap-2 overflow-x-auto pb-3 mb-5 scrollbar-thin">
              {categories.map((cat) => (
                <button
                  key={cat.id}
                  onClick={() => setSelectedCategoryId(cat.id)}
                  className={`flex-shrink-0 px-4 py-2 rounded-full text-sm font-semibold border transition-colors whitespace-nowrap ${
                    selectedCategoryId === cat.id
                      ? "text-white border-transparent"
                      : "text-stone-600 dark:text-stone-300 border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 hover:border-stone-400"
                  }`}
                  style={
                    selectedCategoryId === cat.id
                      ? { backgroundColor: "var(--color-primary)", borderColor: "var(--color-accent)" }
                      : {}
                  }
                >
                  {cat.name}
                </button>
              ))}
            </div>
          )}

          {/* Content */}
          {loading ? (
            <LoadingSpinner message="Loading menu…" />
          ) : error ? (
            <div className="flex flex-col items-center py-20 gap-4 text-center">
              <UtensilsCrossed size={40} className="text-stone-300" />
              <p className="text-stone-500 dark:text-stone-400">{error}</p>
              <button
                onClick={() => window.location.reload()}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white"
                style={{ backgroundColor: "var(--color-primary)" }}
              >
                Retry
              </button>
            </div>
          ) : filteredItems.length === 0 ? (
            <div className="flex flex-col items-center py-20 gap-4 text-center">
              <UtensilsCrossed size={40} className="text-stone-300" />
              {search ? (
                <>
                  <p className="text-stone-500 dark:text-stone-400 font-medium">
                    No items match "{search}"
                  </p>
                  <button
                    onClick={() => setSearch("")}
                    className="text-sm underline text-stone-400"
                  >
                    Clear search
                  </button>
                </>
              ) : (
                <p className="text-stone-500 dark:text-stone-400 font-medium">
                  No items available in this category
                </p>
              )}
            </div>
          ) : (
            <>
              {search && (
                <p className="text-xs text-stone-400 dark:text-stone-500 mb-4">
                  {filteredItems.length} result{filteredItems.length !== 1 ? "s" : ""} for "{search}"
                </p>
              )}
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {filteredItems.map((item) => (
                  <MenuItemCard
                    key={item.id}
                    item={item}
                    quantity={cart[item.id]?.quantity ?? 0}
                    onAdd={handleAdd}
                    onRemove={handleRemove}
                  />
                ))}
              </div>
            </>
          )}
        </main>

        {/* Desktop cart panel */}
        <aside className="hidden lg:flex flex-col w-80 flex-shrink-0">
          <div className="sticky top-24 rounded-xl overflow-hidden shadow-md border border-stone-200 dark:border-stone-700 h-[calc(100vh-7rem)] flex flex-col bg-white dark:bg-stone-800">
            <CartPanel
              cart={cart}
              onClose={() => {}}
              onAdd={handleAdd}
              onRemove={handleRemove}
              onClearItem={handleClearItem}
              onCheckout={handleCheckout}
            />
          </div>
        </aside>
      </div>

      {/* Mobile bottom bar */}
      {itemCount > 0 && (
        <div className="lg:hidden fixed bottom-0 left-0 right-0 z-30 p-3 bg-white dark:bg-stone-800 border-t border-stone-200 dark:border-stone-700 shadow-lg">
          <button
            onClick={() => setCartOpen(true)}
            className="w-full flex items-center justify-between px-5 py-3 rounded-xl text-white font-semibold"
            style={{ backgroundColor: "var(--color-primary)" }}
          >
            <div className="flex items-center gap-2">
              <ShoppingCart size={18} />
              <span className="text-sm">{itemCount} item{itemCount !== 1 ? "s" : ""}</span>
            </div>
            <div className="flex items-center gap-2">
              <span className="text-sm font-bold" style={{ color: "var(--color-highlight)" }}>
                {formatCurrency(subtotal)}
              </span>
              <span className="text-xs bg-white/20 px-2 py-0.5 rounded-full">View Cart</span>
            </div>
          </button>
        </div>
      )}

      {/* Mobile cart drawer overlay */}
      {cartOpen && (
        <div
          className="lg:hidden fixed inset-0 z-50 flex flex-col justify-end"
          role="dialog"
          aria-modal="true"
          aria-label="Shopping cart"
        >
          {/* Backdrop */}
          <div
            className="absolute inset-0 bg-black/50"
            onClick={() => setCartOpen(false)}
          />
          {/* Drawer */}
          <div className="relative bg-white dark:bg-stone-800 rounded-t-2xl max-h-[85vh] flex flex-col shadow-2xl">
            <CartPanel
              cart={cart}
              onClose={() => setCartOpen(false)}
              onAdd={handleAdd}
              onRemove={handleRemove}
              onClearItem={handleClearItem}
              onCheckout={handleCheckout}
            />
          </div>
        </div>
      )}

      {/* Delivery info ribbon */}
      <div
        className="hidden md:block text-center py-2 text-xs font-medium text-white"
        style={{ backgroundColor: "var(--color-success)" }}
      >
        <Bike size={13} className="inline-block mr-1 -mt-0.5" />
        Fast home delivery across our service zones · Delivery fee applied at checkout
      </div>
    </div>
  );
}
