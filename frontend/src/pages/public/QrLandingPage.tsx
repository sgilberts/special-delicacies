import { useEffect, useState, useCallback, useMemo } from "react";
import { useParams, useSearchParams, Link } from "react-router-dom";
import {
  Utensils,
  AlertCircle,
  ArrowRight,
  Sparkles,
  Users,
  ShoppingBag,
  Plus,
  Minus,
  Trash2,
  CheckCircle2,
  MessageSquare,
  X,
  Search,
  ChevronRight,
  Clock,
  RefreshCw,
  UtensilsCrossed,
  CreditCard,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import { assetUrl } from "@/lib/assetUrl";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import type { ApiResponse, MenuCategory, MenuItem, PlacedOrder } from "@/types/api";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ThemeToggle from "@/components/public/ThemeToggle";

interface TableLookupData {
  id?: number;
  table_number: string;
  capacity?: number;
}

interface CartItemEntry {
  menu_item_id: number;
  item: MenuItem;
  quantity: number;
  special_instructions: string;
}

function formatGhc(amount: number | string): string {
  const val = typeof amount === "string" ? parseFloat(amount) : amount;
  return isNaN(val) ? `GH₵ 0.00` : `GH₵ ${val.toFixed(2)}`;
}

export default function QrLandingPage() {
  const { content } = useRestaurantContent();
  const { token } = useParams<{ token: string }>();
  const [searchParams] = useSearchParams();
  const qrToken = token || searchParams.get("token") || undefined;

  // 1. Table identification state
  const [tableState, setTableState] = useState<"loading" | "success" | "error">("loading");
  const [tableData, setTableData] = useState<TableLookupData | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // 2. Active view mode: 'landing' | 'menu' | 'cart' | 'confirmation'
  const [viewMode, setViewMode] = useState<"landing" | "menu" | "cart" | "confirmation">("landing");

  // 3. Menu data state
  const [categories, setCategories] = useState<MenuCategory[]>([]);
  const [menuLoading, setMenuLoading] = useState<boolean>(false);
  const [menuError, setMenuError] = useState<string | null>(null);
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | null>(null);
  const [searchQuery, setSearchQuery] = useState<string>("");

  // 4. Cart state
  const [cart, setCart] = useState<Record<number, CartItemEntry>>({});
  const [customizingItem, setCustomizingItem] = useState<{
    item: MenuItem;
    quantity: number;
    instructions: string;
  } | null>(null);

  // 5. Customer info & Submission state
  const [customerName, setCustomerName] = useState<string>("");
  const [customerNotes, setCustomerNotes] = useState<string>("");
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [placedOrder, setPlacedOrder] = useState<PlacedOrder | null>(null);
  const [gatewayEnabled, setGatewayEnabled] = useState(false);
  const [gatewayName, setGatewayName] = useState("Paystack");
  const [paymentMethod, setPaymentMethod] = useState<"cash" | "gateway">("cash");
  const [customerEmail, setCustomerEmail] = useState("");

  useEffect(() => {
    cachedGet<ApiResponse<{ online_payment_enabled: boolean; gateway_name: string | null }>>("/payment-options")
      .then((res) => {
        if (res.data.success) {
          setGatewayEnabled(res.data.data.online_payment_enabled);
          setGatewayName(res.data.data.gateway_name || "Online payment");
        }
      }).catch(() => undefined);
  }, []);

  // Table verification on mount
  const verifyTable = useCallback(() => {
    if (!qrToken) {
      setTableState("error");
      setErrorMessage("No QR code token provided.");
      return;
    }

    setTableState("loading");
    setErrorMessage(null);

    api
      .get(`/table-lookup/${qrToken}`)
      .then((res) => {
        if (res.data?.success && res.data?.data) {
          setTableData(res.data.data);
          setTableState("success");
        } else {
          setTableState("error");
          setErrorMessage(res.data?.message || "Invalid or expired QR code.");
        }
      })
      .catch((err) => {
        setTableState("error");
        setErrorMessage(
          err?.response?.data?.message || "Unable to verify this table QR code."
        );
      });
  }, [qrToken]);

  useEffect(() => {
    verifyTable();
  }, [verifyTable]);

  // Fetch active menu when entering menu view
  const fetchMenu = useCallback(() => {
    setMenuLoading(true);
    setMenuError(null);
    api
      .get<ApiResponse<MenuCategory[]>>("/menu")
      .then((res) => {
        if (res.data?.success && Array.isArray(res.data.data)) {
          setCategories(res.data.data);
        } else {
          setCategories([]);
        }
      })
      .catch(() => {
        setMenuError("Unable to load menu. Please check your connection and tap retry.");
      })
      .finally(() => {
        setMenuLoading(false);
      });
  }, []);

  // Enter menu and pre-fetch dishes
  const handleStartOrdering = () => {
    setViewMode("menu");
    if (categories.length === 0) {
      fetchMenu();
    }
  };

  // Cart helper functions
  const cartList = useMemo(() => Object.values(cart), [cart]);

  const cartItemCount = useMemo(() => {
    return cartList.reduce((sum, entry) => sum + entry.quantity, 0);
  }, [cartList]);

  const cartSubtotal = useMemo(() => {
    return cartList.reduce((sum, entry) => {
      const price = parseFloat(entry.item.price) || 0;
      return sum + price * entry.quantity;
    }, 0);
  }, [cartList]);

  const addToCart = (item: MenuItem, quantity = 1, instructions = "") => {
    setCart((prev) => {
      const existing = prev[item.id];
      const newQty = existing ? existing.quantity + quantity : quantity;
      return {
        ...prev,
        [item.id]: {
          menu_item_id: item.id,
          item,
          quantity: newQty,
          special_instructions: instructions || existing?.special_instructions || "",
        },
      };
    });
  };

  const updateQuantity = (itemId: number, delta: number) => {
    setCart((prev) => {
      const existing = prev[itemId];
      if (!existing) return prev;
      const nextQty = existing.quantity + delta;
      if (nextQty <= 0) {
        const copy = { ...prev };
        delete copy[itemId];
        return copy;
      }
      return {
        ...prev,
        [itemId]: {
          ...existing,
          quantity: nextQty,
        },
      };
    });
  };

  const removeFromCart = (itemId: number) => {
    setCart((prev) => {
      const copy = { ...prev };
      delete copy[itemId];
      return copy;
    });
  };

  const openCustomizeModal = (item: MenuItem) => {
    const existing = cart[item.id];
    setCustomizingItem({
      item,
      quantity: existing ? existing.quantity : 1,
      instructions: existing ? existing.special_instructions : "",
    });
  };

  const saveCustomizedItem = () => {
    if (!customizingItem) return;
    setCart((prev) => ({
      ...prev,
      [customizingItem.item.id]: {
        menu_item_id: customizingItem.item.id,
        item: customizingItem.item,
        quantity: Math.max(1, customizingItem.quantity),
        special_instructions: customizingItem.instructions.trim(),
      },
    }));
    setCustomizingItem(null);
  };

  // Filtered menu items
  const activeCategories = useMemo(() => {
    return categories.filter((c) => c.is_active);
  }, [categories]);

  const displayedCategories = useMemo(() => {
    let result = activeCategories;
    if (selectedCategoryId !== null) {
      result = result.filter((c) => c.id === selectedCategoryId);
    }
    if (searchQuery.trim() !== "") {
      const q = searchQuery.toLowerCase();
      result = result
        .map((cat) => ({
          ...cat,
          available_items: (cat.available_items || []).filter(
            (item) =>
              item.name.toLowerCase().includes(q) ||
              (item.description && item.description.toLowerCase().includes(q))
          ),
        }))
        .filter((cat) => (cat.available_items || []).length > 0);
    }
    return result;
  }, [activeCategories, selectedCategoryId, searchQuery]);

  // Order submission
  const handleSubmitOrder = async () => {
    if (cartList.length === 0 || isSubmitting) return;
    if (paymentMethod === "gateway" && !customerEmail.trim()) {
      setSubmitError("Enter your email address to continue to secure online payment.");
      return;
    }

    setIsSubmitting(true);
    setSubmitError(null);

    // Generate unique idempotency key for network resilience
    const idempotencyKey =
      typeof crypto !== "undefined" && crypto.randomUUID
        ? crypto.randomUUID()
        : `sd-dinein-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;

    const payload = {
      idempotency_key: idempotencyKey,
      order_type: "dine_in",
      payment_method: paymentMethod,
      customer_email: paymentMethod === "gateway" ? customerEmail.trim() : undefined,
      qr_token: qrToken,
      customer_name: customerName.trim() || undefined,
      customer_notes: customerNotes.trim() || undefined,
      items: cartList.map((entry) => ({
        menu_item_id: entry.menu_item_id,
        quantity: entry.quantity,
        special_instructions: entry.special_instructions || undefined,
      })),
    };

    try {
      const response = await api.post("/orders", payload);
      if (response.data?.success && response.data?.data) {
        if (response.data.data.payment_checkout_url) {
          window.location.assign(response.data.data.payment_checkout_url);
          return;
        }
        setPlacedOrder(response.data.data);
        setCart({}); // clear cart on success
        setViewMode("confirmation");
      } else {
        setSubmitError(response.data?.message || "Failed to submit order. Please try again.");
      }
    } catch (err: unknown) {
      const errorMsg =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ||
        "An unexpected error occurred while placing your order. Please notify your server.";
      setSubmitError(errorMsg);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen flex flex-col bg-[var(--color-background)] text-[var(--color-text)] transition-colors">
      {/* ──────────────── TOP APP HEADER ──────────────── */}
      <header className="sticky top-0 z-40 bg-[var(--color-surface)]/95 backdrop-blur-md border-b border-[var(--color-border)] shadow-xs">
        <div className="max-w-2xl mx-auto px-4 h-16 flex items-center justify-between">
          <Link to="/" className="flex items-center gap-2.5">
            {content.restaurant_logo ? <img src={assetUrl(content.restaurant_logo) ?? undefined} alt={content.restaurant_name} className="h-9 w-9 rounded-xl object-contain" /> : <div className="w-9 h-9 rounded-xl flex items-center justify-center font-bold text-[var(--color-background)] shadow-xs shrink-0 bg-[var(--color-primary)]"><Utensils className="w-4 h-4 text-[var(--color-highlight)]" /></div>}
            <div>
              <span className="font-extrabold text-sm tracking-tight block leading-tight text-[var(--color-text)]">
                {content.restaurant_name}
              </span>
              <span className="text-[10px] font-semibold uppercase tracking-wider text-[var(--color-accent)]">
                Dine-In Service
              </span>
            </div>
          </Link>

          <div className="flex items-center gap-2">
            {/* Table Badge if resolved */}
            {tableData && (
              <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-[var(--color-surface-secondary)] text-[var(--color-accent)] border border-[var(--color-border)]">
                <span>Table {tableData.table_number}</span>
              </div>
            )}
            <ThemeToggle />
          </div>
        </div>
      </header>

      {/* ──────────────── MAIN CONTENT AREA ──────────────── */}
      <main className="flex-1 w-full max-w-2xl mx-auto flex flex-col">
        {/* VIEW 0: Loading or Error on initial verification */}
        {tableState === "loading" && (
          <div className="flex-1 flex flex-col items-center justify-center p-8 text-center space-y-4">
            <LoadingSpinner message="Verifying table QR credentials..." />
          </div>
        )}

        {tableState === "error" && (
          <div className="flex-1 flex items-center justify-center p-4">
            <div className="w-full max-w-md bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl shadow-xl p-6 sm:p-8 text-center space-y-6">
              <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-500/10 text-[var(--color-danger)] mx-auto">
                <AlertCircle className="w-8 h-8" />
              </div>
              <div>
                <h1 className="text-2xl font-bold text-[var(--color-text)]">
                  Invalid QR Code
                </h1>
                <p className="text-sm text-[var(--color-text-secondary)] mt-2">
                  {errorMessage || "This table QR code is invalid, expired, or deactivated."}
                </p>
              </div>
              <div className="p-4 rounded-xl bg-[var(--color-surface-secondary)]/50 border border-[var(--color-border)] text-xs text-[var(--color-text-secondary)]">
                Please ask your waiter for assistance, or tap retry to re-verify.
              </div>
              <div className="space-y-2 pt-2">
                <button
                  type="button"
                  onClick={verifyTable}
                  className="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl text-sm font-bold text-white transition-opacity hover:opacity-90 min-h-[44px]"
                  style={{ background: "var(--color-accent)" }}
                >
                  <RefreshCw className="w-4 h-4" />
                  <span>Retry Verification</span>
                </button>
                <Link
                  to="/menu"
                  className="w-full inline-block py-2.5 px-4 rounded-xl text-xs font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)] transition-colors min-h-[44px] flex items-center justify-center"
                >
                  Browse General Menu
                </Link>
              </div>
            </div>
          </div>
        )}

        {/* VIEW 1: WELCOME / TABLE IDENTIFIED */}
        {tableState === "success" && tableData && viewMode === "landing" && (
          <div className="flex-1 flex items-center justify-center p-4">
            <div className="w-full max-w-md bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl shadow-xl p-6 sm:p-8 text-center space-y-6 animate-in fade-in zoom-in-95 duration-200">
              <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 mx-auto">
                <Sparkles className="w-8 h-8" />
              </div>

              <div>
                <span className="text-xs font-bold uppercase tracking-widest text-[var(--color-accent)]">
                  Welcome to Special Delicacies
                </span>
                <h1 className="text-3xl font-black mt-1 text-[var(--color-text)]">
                  Table {tableData.table_number}
                </h1>
                {tableData.capacity && (
                  <p className="inline-flex items-center gap-1.5 mt-2 px-3 py-1 rounded-full text-xs font-semibold bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)]">
                    <Users className="w-3.5 h-3.5" />
                    Seating capacity: {tableData.capacity} guests
                  </p>
                )}
              </div>

              <div className="p-4 rounded-xl bg-[var(--color-surface-secondary)]/50 border border-[var(--color-border)] text-sm text-[var(--color-text-secondary)] text-left space-y-2">
                <div className="flex items-center gap-2 text-xs font-bold text-[var(--color-text)]">
                  <Clock className="w-4 h-4 text-[var(--color-accent)] shrink-0" />
                  <span>Digital Dine-In Ordering</span>
                </div>
                <p className="text-xs leading-relaxed">
                  Browse our active kitchen specialties, tailor dish instructions, and place your order directly from your table.
                </p>
              </div>

              <div className="space-y-2.5 pt-2">
                <button
                  type="button"
                  onClick={handleStartOrdering}
                  className="w-full flex items-center justify-center gap-2 py-3.5 px-4 rounded-xl text-sm font-bold text-white transition-all hover:opacity-90 active:scale-98 shadow-md min-h-[48px]"
                  style={{ background: "var(--color-accent)" }}
                >
                  <ShoppingBag className="w-4 h-4" />
                  <span>Start Dine-In Order</span>
                  <ArrowRight className="w-4 h-4 ml-1" />
                </button>

                <Link
                  to="/"
                  className="w-full inline-block py-2.5 px-4 rounded-xl text-xs font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)] transition-colors min-h-[44px] flex items-center justify-center"
                >
                  Return to Home
                </Link>
              </div>
            </div>
          </div>
        )}

        {/* VIEW 2: INTERACTIVE MENU BROWSING */}
        {tableState === "success" && tableData && viewMode === "menu" && (
          <div className="flex-1 flex flex-col pb-24">
            {/* Table sticky subheader */}
            <div className="bg-[var(--color-surface-secondary)]/80 px-4 py-2.5 border-b border-[var(--color-border)] flex items-center justify-between text-xs">
              <span className="font-semibold text-[var(--color-text-secondary)]">
                Ordering for Table <strong className="text-[var(--color-text)]">{tableData.table_number}</strong>
              </span>
              {cartItemCount > 0 && (
                <button
                  type="button"
                  onClick={() => setViewMode("cart")}
                  className="font-bold text-[var(--color-accent)] hover:underline flex items-center gap-1"
                >
                  <span>{cartItemCount} in cart</span>
                  <ChevronRight className="w-3.5 h-3.5" />
                </button>
              )}
            </div>

            {/* Search Input */}
            <div className="p-4 bg-[var(--color-surface)] border-b border-[var(--color-border)]">
              <div className="relative">
                <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]" />
                <input
                  type="text"
                  placeholder="Search dishes or beverages..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] text-sm text-[var(--color-text)] placeholder:text-[var(--color-text-secondary)] focus:outline-hidden focus:ring-2 focus:ring-[var(--color-focus-ring)] min-h-[44px]"
                />
                {searchQuery && (
                  <button
                    type="button"
                    onClick={() => setSearchQuery("")}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-[var(--color-text-secondary)]"
                  >
                    Clear
                  </button>
                )}
              </div>
            </div>

            {/* Horizontal Category Nav */}
            {activeCategories.length > 0 && (
              <div className="sticky top-16 z-20 bg-[var(--color-background)]/95 backdrop-blur-md border-b border-[var(--color-border)] px-4 py-2 overflow-x-auto no-scrollbar flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setSelectedCategoryId(null)}
                  className={`min-h-[40px] px-4 py-1.5 rounded-full text-xs font-bold transition-all shrink-0 ${
                    selectedCategoryId === null
                      ? "bg-[var(--color-accent)] text-white shadow-xs"
                      : "bg-[var(--color-surface)] border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
                  }`}
                >
                  All Dishes
                </button>
                {activeCategories.map((cat) => (
                  <button
                    key={cat.id}
                    type="button"
                    onClick={() => setSelectedCategoryId(cat.id)}
                    className={`min-h-[40px] px-4 py-1.5 rounded-full text-xs font-bold transition-all shrink-0 ${
                      selectedCategoryId === cat.id
                        ? "bg-[var(--color-accent)] text-white shadow-xs"
                        : "bg-[var(--color-surface)] border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
                    }`}
                  >
                    {cat.name}
                  </button>
                ))}
              </div>
            )}

            {/* Menu items container */}
            <div className="flex-1 p-4 space-y-6">
              {menuLoading && <LoadingSpinner message="Loading delicious kitchen offerings..." />}

              {menuError && !menuLoading && (
                <div className="p-6 bg-[var(--color-surface)] rounded-2xl border border-[var(--color-border)] text-center space-y-3">
                  <AlertCircle className="w-8 h-8 text-[var(--color-danger)] mx-auto" />
                  <p className="text-sm font-semibold">{menuError}</p>
                  <button
                    type="button"
                    onClick={fetchMenu}
                    className="px-4 py-2 rounded-xl text-xs font-bold bg-[var(--color-accent)] text-white min-h-[44px]"
                  >
                    Try Again
                  </button>
                </div>
              )}

              {!menuLoading && !menuError && displayedCategories.length === 0 && (
                <div className="p-8 text-center text-[var(--color-text-secondary)]">
                  <p className="text-base font-semibold">No dishes found</p>
                  <p className="text-xs mt-1">Try clearing your search query or selecting a different category.</p>
                </div>
              )}

              {!menuLoading &&
                !menuError &&
                displayedCategories.map((cat) => {
                  const items = cat.available_items || [];
                  if (items.length === 0) return null;

                  return (
                    <div key={cat.id} className="space-y-3">
                      <h2 className="text-base font-extrabold text-[var(--color-text)] tracking-tight">
                        {cat.name}
                      </h2>
                      <div className="grid grid-cols-1 gap-3">
                        {items.map((item) => {
                          const inCart = cart[item.id];
                          return (
                            <div
                              key={item.id}
                              className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-3.5 flex gap-3.5 shadow-xs transition-shadow hover:shadow-sm"
                            >
                              {/* Item Photo / Icon */}
                              <div className="w-20 h-20 sm:w-24 sm:h-24 rounded-xl overflow-hidden bg-[var(--color-surface-secondary)] shrink-0 flex items-center justify-center relative">
                                {item.image_path ? (
                                  <img
                                    src={assetUrl(item.image_path) ?? undefined}
                                    alt={item.name}
                                    loading="lazy"
                                    className="w-full h-full object-cover"
                                    onError={(e) => {
                                      (e.currentTarget as HTMLImageElement).style.display = "none";
                                    }}
                                  />
                                ) : (
                                  <UtensilsCrossed className="w-6 h-6 text-[var(--color-text-secondary)] opacity-60" />
                                )}
                              </div>

                              {/* Details */}
                              <div className="flex-1 flex flex-col justify-between min-w-0">
                                <div>
                                  <div className="flex items-start justify-between gap-1">
                                    <h3 className="text-sm font-bold text-[var(--color-text)] leading-snug truncate">
                                      {item.name}
                                    </h3>
                                  </div>
                                  {item.description && (
                                    <p className="text-xs text-[var(--color-text-secondary)] line-clamp-2 mt-0.5">
                                      {item.description}
                                    </p>
                                  )}
                                </div>

                                <div className="flex items-center justify-between mt-2 pt-2 border-t border-[var(--color-border)]/50">
                                  <span className="font-extrabold text-sm text-[var(--color-accent)] font-mono">
                                    {formatGhc(item.price)}
                                  </span>

                                  {/* Add or Quantity Controls */}
                                  {inCart ? (
                                    <div className="flex items-center gap-1.5 bg-[var(--color-surface-secondary)] p-1 rounded-xl">
                                      <button
                                        type="button"
                                        onClick={() => updateQuantity(item.id, -1)}
                                        className="w-7 h-7 rounded-lg flex items-center justify-center text-[var(--color-text)] hover:bg-[var(--color-surface)] active:scale-95 transition-all"
                                        aria-label="Decrease quantity"
                                      >
                                        <Minus className="w-3.5 h-3.5" />
                                      </button>
                                      <span className="w-6 text-center text-xs font-black font-mono">
                                        {inCart.quantity}
                                      </span>
                                      <button
                                        type="button"
                                        onClick={() => updateQuantity(item.id, 1)}
                                        className="w-7 h-7 rounded-lg flex items-center justify-center text-[var(--color-text)] hover:bg-[var(--color-surface)] active:scale-95 transition-all"
                                        aria-label="Increase quantity"
                                      >
                                        <Plus className="w-3.5 h-3.5" />
                                      </button>
                                      <button
                                        type="button"
                                        onClick={() => openCustomizeModal(item)}
                                        className="p-1 text-[var(--color-accent)] hover:opacity-80"
                                        title="Special instructions"
                                      >
                                        <MessageSquare className="w-3.5 h-3.5" />
                                      </button>
                                    </div>
                                  ) : (
                                    <button
                                      type="button"
                                      onClick={() => addToCart(item)}
                                      className="flex items-center gap-1 py-1.5 px-3 rounded-xl text-xs font-bold text-white transition-all active:scale-95 min-h-[38px]"
                                      style={{ background: "var(--color-accent)" }}
                                    >
                                      <Plus className="w-3.5 h-3.5" />
                                      <span>Add</span>
                                    </button>
                                  )}
                                </div>
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  );
                })}
            </div>

            {/* Floating Bottom Cart Bar */}
            {cartItemCount > 0 && (
              <div className="fixed bottom-0 inset-x-0 z-30 p-4 bg-gradient-to-t from-[var(--color-background)] via-[var(--color-background)]/90 to-transparent">
                <div className="max-w-2xl mx-auto">
                  <button
                    type="button"
                    onClick={() => setViewMode("cart")}
                    className="w-full flex items-center justify-between p-3.5 px-5 rounded-2xl text-white shadow-xl transition-all active:scale-98 min-h-[52px]"
                    style={{ background: "var(--brand-espresso)" }}
                  >
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full bg-[var(--color-accent)] flex items-center justify-center font-bold text-xs font-mono">
                        {cartItemCount}
                      </div>
                      <div className="text-left">
                        <span className="text-xs text-[var(--color-text-secondary)] block text-neutral-300">
                          Table {tableData.table_number} Order
                        </span>
                        <span className="font-extrabold text-sm font-mono text-[var(--brand-gold)]">
                          {formatGhc(cartSubtotal)}
                        </span>
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5 font-bold text-sm text-[var(--color-accent)]">
                      <span>Review Cart</span>
                      <ArrowRight className="w-4 h-4" />
                    </div>
                  </button>
                </div>
              </div>
            )}
          </div>
        )}

        {/* VIEW 3: CART REVIEW & DINE-IN SUBMISSION */}
        {tableState === "success" && tableData && viewMode === "cart" && (
          <div className="flex-1 flex flex-col p-4 pb-12 space-y-4 animate-in fade-in duration-150">
            {/* Top header navigation */}
            <div className="flex items-center justify-between pb-2 border-b border-[var(--color-border)]">
              <button
                type="button"
                onClick={() => setViewMode("menu")}
                className="flex items-center gap-1 text-xs font-bold text-[var(--color-text-secondary)] hover:text-[var(--color-text)] min-h-[44px]"
              >
                <span>&larr; Back to Menu</span>
              </button>
              <h1 className="text-base font-extrabold text-[var(--color-text)]">
                Table {tableData.table_number} Cart
              </h1>
            </div>

            {/* Error banner if submission failed */}
            {submitError && (
              <div className="p-3.5 rounded-xl bg-red-500/10 border border-[var(--color-danger)] text-[var(--color-danger)] text-xs font-semibold flex items-start gap-2">
                <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
                <span>{submitError}</span>
              </div>
            )}

            {/* Cart item listing */}
            {cartList.length === 0 ? (
              <div className="py-16 text-center space-y-4">
                <ShoppingBag className="w-12 h-12 mx-auto text-[var(--color-text-secondary)] opacity-50" />
                <p className="text-sm font-bold text-[var(--color-text-secondary)]">Your dine-in cart is empty</p>
                <button
                  type="button"
                  onClick={() => setViewMode("menu")}
                  className="px-5 py-2.5 rounded-xl text-xs font-bold text-white min-h-[44px]"
                  style={{ background: "var(--color-accent)" }}
                >
                  Browse Dishes
                </button>
              </div>
            ) : (
              <div className="space-y-4">
                <div className="space-y-2.5">
                  {cartList.map((entry) => {
                    const price = parseFloat(entry.item.price) || 0;
                    const lineTotal = price * entry.quantity;

                    return (
                      <div
                        key={entry.menu_item_id}
                        className="p-3.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl space-y-2 shadow-xs"
                      >
                        <div className="flex items-start justify-between gap-2">
                          <div>
                            <h3 className="text-sm font-bold text-[var(--color-text)]">
                              {entry.item.name}
                            </h3>
                            <span className="text-xs text-[var(--color-text-secondary)] font-mono">
                              {formatGhc(entry.item.price)} each
                            </span>
                          </div>
                          <span className="font-extrabold text-sm text-[var(--color-text)] font-mono">
                            {formatGhc(lineTotal)}
                          </span>
                        </div>

                        {/* Special instructions indicator / preview */}
                        {entry.special_instructions ? (
                          <div className="flex items-center justify-between text-xs p-2 rounded-lg bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)]">
                            <span className="italic">Note: "{entry.special_instructions}"</span>
                            <button
                              type="button"
                              onClick={() => openCustomizeModal(entry.item)}
                              className="font-bold text-[var(--color-accent)] text-[11px] underline ml-2"
                            >
                              Edit
                            </button>
                          </div>
                        ) : (
                          <button
                            type="button"
                            onClick={() => openCustomizeModal(entry.item)}
                            className="text-xs text-[var(--color-accent)] hover:underline inline-flex items-center gap-1"
                          >
                            <Plus className="w-3 h-3" />
                            <span>Add special instructions</span>
                          </button>
                        )}

                        {/* Stepper & Remove */}
                        <div className="flex items-center justify-between pt-2 border-t border-[var(--color-border)]/50">
                          <button
                            type="button"
                            onClick={() => removeFromCart(entry.menu_item_id)}
                            className="flex items-center gap-1 text-xs text-[var(--color-danger)] hover:underline min-h-[36px]"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                            <span>Remove</span>
                          </button>

                          <div className="flex items-center gap-2 bg-[var(--color-surface-secondary)] p-1 rounded-xl">
                            <button
                              type="button"
                              onClick={() => updateQuantity(entry.menu_item_id, -1)}
                              className="w-7 h-7 rounded-lg flex items-center justify-center text-[var(--color-text)] hover:bg-[var(--color-surface)] active:scale-95 transition-all"
                            >
                              <Minus className="w-3.5 h-3.5" />
                            </button>
                            <span className="w-6 text-center text-xs font-black font-mono">
                              {entry.quantity}
                            </span>
                            <button
                              type="button"
                              onClick={() => updateQuantity(entry.menu_item_id, 1)}
                              className="w-7 h-7 rounded-lg flex items-center justify-center text-[var(--color-text)] hover:bg-[var(--color-surface)] active:scale-95 transition-all"
                            >
                              <Plus className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>

                {/* Optional Customer info */}
                <div className="p-4 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl space-y-3">
                  <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
                    Dine-In Details (Optional)
                  </span>
                  <div>
                    <label className="block text-xs font-semibold text-[var(--color-text-secondary)] mb-1">
                      Guest Name
                    </label>
                    <input
                      type="text"
                      placeholder="e.g. Kwame or Sarah"
                      value={customerName}
                      onChange={(e) => setCustomerName(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] text-xs text-[var(--color-text)] min-h-[44px]"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-[var(--color-text-secondary)] mb-1">
                      Kitchen / Table Note
                    </label>
                    <textarea
                      rows={2}
                      placeholder="e.g. Serve drinks first, no hurry"
                      value={customerNotes}
                      onChange={(e) => setCustomerNotes(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] text-xs text-[var(--color-text)]"
                    />
                  </div>
                </div>

                {/* Bill Breakdown */}
                <div className="p-4 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl space-y-2">
                  <div className="flex items-center justify-between text-xs text-[var(--color-text-secondary)]">
                    <span>Items Subtotal</span>
                    <span className="font-mono">{formatGhc(cartSubtotal)}</span>
                  </div>
                  <div className="flex items-center justify-between text-xs text-[var(--color-text-secondary)]">
                    <span>Dine-In Service Charge</span>
                    <span className="font-mono text-emerald-600 dark:text-emerald-400 font-semibold">GH₵ 0.00 (Included)</span>
                  </div>
                  <div className="border-t border-[var(--color-border)] pt-2 flex items-center justify-between text-base font-extrabold text-[var(--color-text)]">
                    <span>Total Due</span>
                    <span className="font-mono text-[var(--color-accent)]">{formatGhc(cartSubtotal)}</span>
                  </div>
                </div>

                {gatewayEnabled && (
                  <div className="p-4 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl space-y-2">
                    <p className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">Payment method</p>
                    <label className="flex items-center gap-2 py-2 text-sm text-[var(--color-text)] cursor-pointer"><input type="radio" name="dine-in-payment" checked={paymentMethod === "cash"} onChange={() => setPaymentMethod("cash")} /> Cash with your waiter</label>
                    <label className="flex items-center gap-2 py-2 text-sm text-[var(--color-text)] cursor-pointer"><input type="radio" name="dine-in-payment" checked={paymentMethod === "gateway"} onChange={() => setPaymentMethod("gateway")} /><CreditCard size={16} className="text-[var(--color-accent)]" /> Pay now with {gatewayName}</label>
                    {paymentMethod === "gateway" && <input type="email" required value={customerEmail} onChange={(event) => setCustomerEmail(event.target.value)} placeholder="Email for payment receipt" className="w-full px-3 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] text-sm text-[var(--color-text)]" />}
                  </div>
                )}

                {/* Submit Action */}
                <button
                  type="button"
                  onClick={handleSubmitOrder}
                  disabled={isSubmitting}
                  className="w-full py-4 px-4 rounded-2xl text-sm font-extrabold text-white transition-all shadow-md active:scale-98 flex items-center justify-center gap-2 min-h-[50px] disabled:opacity-50"
                  style={{ background: "var(--color-accent)" }}
                >
                  {isSubmitting ? (
                    <>
                      <LoadingSpinner message="" />
                      <span>Sending Order to Kitchen...</span>
                    </>
                  ) : (
                    <>
                      {paymentMethod === "gateway" ? <CreditCard className="w-5 h-5" /> : <ShoppingBag className="w-5 h-5" />}
                      <span>{paymentMethod === "gateway" ? `Continue to ${gatewayName} payment (${formatGhc(cartSubtotal)})` : `Confirm & Place Order (${formatGhc(cartSubtotal)})`}</span>
                    </>
                  )}
                </button>
              </div>
            )}
          </div>
        )}

        {/* VIEW 4: ORDER CONFIRMATION SCREEN */}
        {viewMode === "confirmation" && placedOrder && (
          <div className="flex-1 flex items-center justify-center p-4">
            <div className="w-full max-w-md bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl shadow-xl p-6 sm:p-8 text-center space-y-6 animate-in fade-in zoom-in-95 duration-200">
              <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 mx-auto">
                <CheckCircle2 className="w-8 h-8" />
              </div>

              <div>
                <span className="text-xs font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-400">
                  Order Successfully Placed
                </span>
                <h1 className="text-2xl font-black mt-1 text-[var(--color-text)]">
                  Table {tableData?.table_number}
                </h1>
                <div className="inline-block mt-2 px-3 py-1 rounded-full text-xs font-mono font-bold bg-[var(--color-surface-secondary)] text-[var(--color-text)] border border-[var(--color-border)]">
                  Order #{placedOrder.order_number}
                </div>
              </div>

              {/* Status explanation */}
              <div className="p-4 rounded-xl bg-emerald-500/5 border border-emerald-500/20 text-xs text-[var(--color-text)] text-left space-y-1.5">
                <div className="flex items-center gap-1.5 font-bold text-emerald-700 dark:text-emerald-300">
                  <Clock className="w-3.5 h-3.5" />
                  <span>Status: Preparing</span>
                </div>
                <p className="text-[var(--color-text-secondary)]">
                  Your order has been sent directly to our kitchen. Your food will be brought directly to Table {tableData?.table_number}.
                </p>
              </div>

              {/* Summary of items */}
              {placedOrder.items && placedOrder.items.length > 0 && (
                <div className="text-left space-y-2 border-t border-[var(--color-border)] pt-4">
                  <span className="text-xs font-bold uppercase text-[var(--color-text-secondary)]">
                    Ordered Dishes
                  </span>
                  <div className="space-y-1.5 max-h-48 overflow-y-auto pr-1">
                    {placedOrder.items.map((it) => (
                      <div key={it.id} className="flex items-center justify-between text-xs">
                        <span className="truncate pr-2">
                          {it.quantity}x {it.item_name}
                        </span>
                        <span className="font-mono font-semibold shrink-0">
                          {formatGhc(it.line_total)}
                        </span>
                      </div>
                    ))}
                  </div>
                  <div className="border-t border-[var(--color-border)] pt-2 flex items-center justify-between text-sm font-extrabold text-[var(--color-text)]">
                    <span>Total</span>
                    <span className="font-mono text-[var(--color-accent)]">{formatGhc(placedOrder.total)}</span>
                  </div>
                </div>
              )}

              <div className="space-y-2 pt-2">
                <button
                  type="button"
                  onClick={() => {
                    setViewMode("menu");
                  }}
                  className="w-full py-3 px-4 rounded-xl text-xs font-bold text-white transition-opacity hover:opacity-90 min-h-[44px]"
                  style={{ background: "var(--color-accent)" }}
                >
                  Order More Dishes for This Table
                </button>
                <Link
                  to="/"
                  className="w-full inline-block py-2.5 px-4 rounded-xl text-xs font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)] transition-colors min-h-[44px] flex items-center justify-center"
                >
                  Finish & Return Home
                </Link>
              </div>
            </div>
          </div>
        )}
      </main>

      {/* ──────────────── CUSTOMIZE ITEM MODAL ──────────────── */}
      {customizingItem && (
        <div
          className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-end sm:items-center justify-center p-0 sm:p-4 animate-in fade-in duration-150"
          onClick={() => setCustomizingItem(null)}
        >
          <div
            className="w-full max-w-md bg-[var(--color-surface)] border border-[var(--color-border)] rounded-t-3xl sm:rounded-2xl p-5 space-y-4 shadow-2xl animate-in slide-in-from-bottom-4 duration-200"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between border-b border-[var(--color-border)] pb-3">
              <div>
                <h3 className="text-sm font-extrabold text-[var(--color-text)]">
                  Special Instructions
                </h3>
                <p className="text-xs text-[var(--color-text-secondary)]">
                  {customizingItem.item.name}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setCustomizingItem(null)}
                className="p-1 rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)] min-h-[40px] min-w-[40px] flex items-center justify-center"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div>
              <label className="block text-xs font-semibold text-[var(--color-text-secondary)] mb-1">
                Kitchen Notes (e.g. No pepper, dressing on side)
              </label>
              <textarea
                rows={3}
                maxLength={255}
                placeholder="Type your preferences for the chef..."
                value={customizingItem.instructions}
                onChange={(e) =>
                  setCustomizingItem((prev) => (prev ? { ...prev, instructions: e.target.value } : null))
                }
                className="w-full p-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] text-xs text-[var(--color-text)] focus:outline-hidden focus:ring-2 focus:ring-[var(--color-focus-ring)]"
              />
              <span className="text-[10px] text-[var(--color-text-secondary)] float-right mt-1">
                {customizingItem.instructions.length}/255
              </span>
            </div>

            <div className="flex items-center justify-between pt-2">
              <span className="text-xs font-bold text-[var(--color-text-secondary)]">Quantity</span>
              <div className="flex items-center gap-2 bg-[var(--color-surface-secondary)] p-1 rounded-xl">
                <button
                  type="button"
                  onClick={() =>
                    setCustomizingItem((prev) =>
                      prev ? { ...prev, quantity: Math.max(1, prev.quantity - 1) } : null
                    )
                  }
                  className="w-8 h-8 rounded-lg flex items-center justify-center text-[var(--color-text)] hover:bg-[var(--color-surface)]"
                >
                  <Minus className="w-3.5 h-3.5" />
                </button>
                <span className="w-6 text-center text-xs font-bold font-mono">
                  {customizingItem.quantity}
                </span>
                <button
                  type="button"
                  onClick={() =>
                    setCustomizingItem((prev) =>
                      prev ? { ...prev, quantity: prev.quantity + 1 } : null
                    )
                  }
                  className="w-8 h-8 rounded-lg flex items-center justify-center text-[var(--color-text)] hover:bg-[var(--color-surface)]"
                >
                  <Plus className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>

            <div className="pt-2">
              <button
                type="button"
                onClick={saveCustomizedItem}
                className="w-full py-3 px-4 rounded-xl text-xs font-extrabold text-white transition-opacity hover:opacity-90 min-h-[44px]"
                style={{ background: "var(--color-accent)" }}
              >
                Save Preferences
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ──────────────── MINI FOOTER ──────────────── */}
      <footer className="w-full max-w-2xl mx-auto px-4 py-4 text-center text-[11px] text-[var(--color-text-secondary)]">
        &copy; 2026 Special Delicacies &mdash; Authentic Fine Dining. All rights reserved.
      </footer>
    </div>
  );
}
