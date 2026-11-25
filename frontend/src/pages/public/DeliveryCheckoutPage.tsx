import { useEffect, useState, useMemo } from "react";
import { useNavigate, Link } from "react-router-dom";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  ArrowLeft,
  Banknote,
  ShoppingCart,
  MapPin,
  Phone,
  User,
  FileText,
  ChevronDown,
  AlertCircle,
  Loader2,
  CreditCard,
} from "lucide-react";
import api, { cachedGet } from "@/lib/api";
import type { ApiResponse, DeliveryZone, PlacedOrder } from "@/types/api";
import type { CartEntry } from "./DeliveryPage";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ThemeToggle from "@/components/public/ThemeToggle";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatCurrency(val: number): string {
  return `GH₵ ${val.toFixed(2)}`;
}

function parseCurrency(str: string): number {
  const val = parseFloat(str);
  return isNaN(val) ? 0 : val;
}

function getCartFromSession(): CartEntry[] {
  try {
    const raw = sessionStorage.getItem("delivery_cart");
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

// ─── Validation schema ───────────────────────────────────────────────────────

const checkoutSchema = z.object({
  customer_name: z.string().min(2, "Name must be at least 2 characters"),
  customer_phone: z
    .string()
    .regex(
      /^(\+233|0)[0-9]{9}$/,
      "Enter a valid Ghana phone number (e.g. 024 XXX XXXX or +233 24 XXX XXXX)"
    ),
  delivery_zone_id: z
    .string()
    .min(1, "Please select a delivery zone")
    .refine((val) => !isNaN(parseInt(val, 10)) && parseInt(val, 10) > 0, {
      message: "Please select a valid delivery zone",
    }),
  delivery_address: z
    .string()
    .min(10, "Please enter a more detailed delivery address (at least 10 characters)")
    .max(300, "Address must not exceed 300 characters"),
  customer_notes: z.string().max(500, "Notes must not exceed 500 characters").optional(),
});

type CheckoutFormValues = z.infer<typeof checkoutSchema>;

// ─── Field wrapper ────────────────────────────────────────────────────────────

function FieldError({ message }: { message?: string }) {
  if (!message) return null;
  return (
    <p className="mt-1.5 flex items-center gap-1 text-xs text-red-500">
      <AlertCircle size={12} />
      {message}
    </p>
  );
}

function FieldLabel({
  htmlFor,
  children,
  required,
}: {
  htmlFor: string;
  children: React.ReactNode;
  required?: boolean;
}) {
  return (
    <label
      htmlFor={htmlFor}
      className="block text-sm font-semibold text-stone-700 dark:text-stone-300 mb-1.5"
    >
      {children}
      {required && <span className="ml-1 text-red-500">*</span>}
    </label>
  );
}

const inputClass =
  "w-full px-3.5 py-2.5 rounded-lg border bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 placeholder-stone-400 text-sm focus:outline-none focus:ring-2 transition-colors";
const inputNormal =
  "border-stone-300 dark:border-stone-600 focus:border-amber-600 focus:ring-amber-200 dark:focus:ring-amber-900/40";
const inputError =
  "border-red-400 dark:border-red-500 focus:border-red-500 focus:ring-red-200 dark:focus:ring-red-900/40";

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function DeliveryCheckoutPage() {
  const navigate = useNavigate();
  const { content } = useRestaurantContent();

  const [cartItems, setCartItems] = useState<CartEntry[]>([]);
  const [zones, setZones] = useState<DeliveryZone[]>([]);
  const [zonesLoading, setZonesLoading] = useState(false);
  const [apiError, setApiError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [includeDeliveryFee, setIncludeDeliveryFee] = useState(true);
  const [gatewayEnabled, setGatewayEnabled] = useState(false);
  const [gatewayName, setGatewayName] = useState("Paystack");
  const [paymentMethod, setPaymentMethod] = useState<"cash" | "gateway">("cash");
  const [customerEmail, setCustomerEmail] = useState("");

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
  } = useForm<CheckoutFormValues>({
    resolver: zodResolver(checkoutSchema),
    defaultValues: {
      customer_name: "",
      customer_phone: "",
      delivery_zone_id: "",
      delivery_address: "",
      customer_notes: "",
    },
  });

  const selectedZoneId = watch("delivery_zone_id");

  // Load cart + zones from session on mount
  useEffect(() => {
    document.title = "Checkout | Special Delicacies";

    const cart = getCartFromSession();
    if (cart.length === 0) {
      navigate("/delivery", { replace: true });
      return;
    }
    setCartItems(cart);

    let cancelled = false;
    setZonesLoading(true);
    cachedGet<ApiResponse<{ online_payment_enabled: boolean; gateway_name: string | null }>>("/payment-options")
      .then((res) => {
        if (!cancelled && res.data.success) {
          setGatewayEnabled(res.data.data.online_payment_enabled);
          setGatewayName(res.data.data.gateway_name || "Online payment");
        }
      }).catch(() => undefined);
    api
      .get<ApiResponse<DeliveryZone[]>>("/delivery-zones")
      .then((res) => {
        if (!res.data?.success || !Array.isArray(res.data.data)) {
          throw new Error("Delivery zones could not be loaded.");
        }
        const active = res.data.data.filter((zone) => zone.is_active !== false);
        if (!cancelled) {
          setZones(active);
          sessionStorage.setItem("delivery_zones", JSON.stringify(active));
          setApiError(active.length === 0 ? "No delivery zones are currently available." : null);
        }
      })
      .catch(() => {
        if (!cancelled) setApiError("Could not load delivery zones. Please refresh and try again.");
      })
      .finally(() => {
        if (!cancelled) setZonesLoading(false);
      });
    return () => { cancelled = true; };
  }, [navigate]);

  // Computed order totals
  const subtotal = useMemo(
    () => cartItems.reduce((sum, e) => sum + parseCurrency(e.item.price) * e.quantity, 0),
    [cartItems]
  );

  const selectedZone = useMemo(
    () => zones.find((z) => z.id === parseInt(selectedZoneId || "0", 10)) ?? null,
    [zones, selectedZoneId]
  );

  const deliveryFee = selectedZone ? parseCurrency(selectedZone.delivery_fee) : 0;
  const total = subtotal + (includeDeliveryFee ? deliveryFee : 0);

  // Submit
  const onSubmit = async (values: CheckoutFormValues) => {
    setApiError(null);
    if (paymentMethod === "gateway" && !customerEmail.trim()) {
      setApiError("Enter your email address to continue to secure online payment.");
      return;
    }
    setSubmitting(true);

    const payload = {
      order_type: "delivery",
      payment_method: paymentMethod,
      customer_email: paymentMethod === "gateway" ? customerEmail.trim() : undefined,
      customer_name: values.customer_name.trim(),
      customer_phone: values.customer_phone.trim(),
      delivery_zone_id: parseInt(values.delivery_zone_id, 10),
      include_delivery_fee: includeDeliveryFee,
      delivery_address: values.delivery_address.trim(),
      customer_notes: values.customer_notes?.trim() || undefined,
      items: cartItems.map((e) => ({
        menu_item_id: e.menu_item_id,
        quantity: e.quantity,
        special_instructions: e.special_instructions || undefined,
      })),
    };

    try {
      const res = await api.post<ApiResponse<PlacedOrder>>("/orders", payload);
      if (res.data?.success && res.data.data) {
        const order = res.data.data;
        if (order.payment_checkout_url) {
          window.location.assign(order.payment_checkout_url);
          return;
        }
        sessionStorage.removeItem("delivery_cart");
        navigate(`/delivery/confirmation/${order.order_number}`, {
          state: { order },
          replace: true,
        });
      } else {
        setApiError(res.data?.message ?? "Failed to place order. Please try again.");
      }
    } catch (err: unknown) {
      const axiosErr = err as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } };
      };
      const serverMessage = axiosErr.response?.data?.message;
      const serverErrors = axiosErr.response?.data?.errors;
      if (serverErrors) {
        const first = Object.values(serverErrors)[0]?.[0];
        setApiError(first ?? serverMessage ?? "Failed to place order. Please try again.");
      } else {
        setApiError(serverMessage ?? "Failed to place order. Please check your connection and try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (cartItems.length === 0) {
    return <LoadingSpinner message="Redirecting…" />;
  }

  return (
    <div className="min-h-screen bg-stone-50 dark:bg-stone-950">
      {/* Header */}
      <header
        className="sticky top-0 z-30 shadow-md"
        style={{ backgroundColor: "var(--color-primary)" }}
      >
        <div className="max-w-5xl mx-auto px-4 h-16 flex items-center justify-between gap-4">
          <Link
            to="/delivery"
            className="flex items-center gap-2 text-sm font-medium text-stone-200 hover:text-white transition-colors"
          >
            <ArrowLeft size={16} />
            Back to Menu
          </Link>
          <div className="flex items-center gap-2">
            {content.restaurant_logo && <img src={assetUrl(content.restaurant_logo) ?? undefined} alt={content.restaurant_name} className="h-8 w-8 rounded-lg object-contain" />}
            <ShoppingCart size={16} className="text-amber-400" />
            <span className="text-white font-semibold text-sm hidden sm:block">
              {content.restaurant_name} · Checkout
            </span>
          </div>
          <ThemeToggle />
        </div>
      </header>

      <div className="max-w-5xl mx-auto px-4 py-8">
        {/* Page heading */}
        <div className="mb-8">
          <h1 className="text-2xl font-bold text-stone-900 dark:text-stone-100">
            Complete Your Order
          </h1>
          <p className="text-sm text-stone-500 dark:text-stone-400 mt-1">
            Fill in your delivery details to place your order
          </p>
        </div>

        <form onSubmit={handleSubmit(onSubmit)}>
          <div className="flex flex-col lg:flex-row gap-8">
            {/* ── LEFT: Form ───────────────────────────────────────────────── */}
            <div className="flex-1 space-y-5">
              {/* API error */}
              {apiError && (
                <div className="flex items-start gap-3 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                  <AlertCircle size={18} className="text-red-500 flex-shrink-0 mt-0.5" />
                  <p className="text-sm text-red-700 dark:text-red-400">{apiError}</p>
                </div>
              )}

              {/* Section: Contact */}
              <div className="bg-white dark:bg-stone-800 rounded-xl p-5 border border-stone-200 dark:border-stone-700 space-y-4">
                <h2 className="font-semibold text-stone-900 dark:text-stone-100 flex items-center gap-2 text-sm uppercase tracking-wide">
                  <User size={15} style={{ color: "var(--color-accent)" }} />
                  Contact Details
                </h2>

                {/* Customer Name */}
                <div>
                  <FieldLabel htmlFor="customer_name" required>
                    Full Name
                  </FieldLabel>
                  <input
                    id="customer_name"
                    type="text"
                    placeholder="e.g. Kwame Mensah"
                    autoComplete="name"
                    className={`${inputClass} ${errors.customer_name ? inputError : inputNormal}`}
                    {...register("customer_name")}
                  />
                  <FieldError message={errors.customer_name?.message} />
                </div>

                {/* Phone */}
                <div>
                  <FieldLabel htmlFor="customer_phone" required>
                    Phone Number
                  </FieldLabel>
                  <div className="relative">
                    <Phone
                      size={15}
                      className="absolute left-3 top-1/2 -translate-y-1/2 text-stone-400"
                    />
                    <input
                      id="customer_phone"
                      type="tel"
                      placeholder="024 XXX XXXX or +233 24 XXX XXXX"
                      autoComplete="tel"
                      className={`${inputClass} pl-9 ${errors.customer_phone ? inputError : inputNormal}`}
                      {...register("customer_phone")}
                    />
                  </div>
                  <FieldError message={errors.customer_phone?.message} />
                </div>
                {gatewayEnabled && paymentMethod === "gateway" && (
                  <div className="sm:col-span-2">
                    <FieldLabel htmlFor="customer_email" required>Email for payment receipt</FieldLabel>
                    <input id="customer_email" type="email" required value={customerEmail} onChange={(event) => setCustomerEmail(event.target.value)} autoComplete="email" placeholder="you@example.com" className={`${inputClass} ${inputNormal}`} />
                  </div>
                )}
              </div>

              {/* Section: Delivery */}
              <div className="bg-white dark:bg-stone-800 rounded-xl p-5 border border-stone-200 dark:border-stone-700 space-y-4">
                <h2 className="font-semibold text-stone-900 dark:text-stone-100 flex items-center gap-2 text-sm uppercase tracking-wide">
                  <MapPin size={15} style={{ color: "var(--color-accent)" }} />
                  Delivery Details
                </h2>

                {/* Zone */}
                <div>
                  <FieldLabel htmlFor="delivery_zone_id" required>
                    Delivery Zone
                  </FieldLabel>
                  {zonesLoading ? (
                    <div className="text-sm text-stone-400 py-2 flex items-center gap-2">
                      <Loader2 size={14} className="animate-spin" />
                      Loading zones…
                    </div>
                  ) : (
                    <div className="relative">
                      <select
                        id="delivery_zone_id"
                        disabled={zones.length === 0}
                        className={`${inputClass} pr-9 appearance-none ${
                          errors.delivery_zone_id ? inputError : inputNormal
                        }`}
                        {...register("delivery_zone_id")}
                      >
                        <option value="">Select delivery zone…</option>
                        {zones.map((zone) => (
                          <option key={zone.id} value={zone.id}>
                            {zone.name} — GH₵ {parseCurrency(zone.delivery_fee).toFixed(2)} delivery
                          </option>
                        ))}
                      </select>
                      <ChevronDown
                        size={15}
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-stone-400 pointer-events-none"
                      />
                    </div>
                  )}
                  {!zonesLoading && zones.length === 0 && (
                    <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">
                      {apiError ?? "No delivery zones are currently available."}
                    </p>
                  )}
                  <FieldError message={errors.delivery_zone_id?.message} />
                  {selectedZone?.description && (
                    <p className="mt-1.5 text-xs text-stone-500 dark:text-stone-400">
                      {selectedZone.description}
                    </p>
                  )}
                </div>

                {/* Address */}
                <div>
                  <FieldLabel htmlFor="delivery_address" required>
                    Delivery Address
                  </FieldLabel>
                  <textarea
                    id="delivery_address"
                    rows={3}
                    placeholder="House number, street name, landmark or area description…"
                    autoComplete="street-address"
                    className={`${inputClass} resize-none ${
                      errors.delivery_address ? inputError : inputNormal
                    }`}
                    {...register("delivery_address")}
                  />
                  <FieldError message={errors.delivery_address?.message} />
                </div>
              </div>

              {/* Section: Notes */}
              <div className="bg-white dark:bg-stone-800 rounded-xl p-5 border border-stone-200 dark:border-stone-700 space-y-4">
                <h2 className="font-semibold text-stone-900 dark:text-stone-100 flex items-center gap-2 text-sm uppercase tracking-wide">
                  <FileText size={15} style={{ color: "var(--color-accent)" }} />
                  Special Instructions{" "}
                  <span className="normal-case font-normal text-stone-400 text-xs">(optional)</span>
                </h2>
                <div>
                  <FieldLabel htmlFor="customer_notes">Order Notes</FieldLabel>
                  <textarea
                    id="customer_notes"
                    rows={3}
                    placeholder="Any special instructions for your order or delivery…"
                    className={`${inputClass} resize-none ${
                      errors.customer_notes ? inputError : inputNormal
                    }`}
                    {...register("customer_notes")}
                  />
                  <FieldError message={errors.customer_notes?.message} />
                </div>
              </div>
            </div>

            {/* ── RIGHT: Order Summary ──────────────────────────────────────── */}
            <aside className="lg:w-80 flex-shrink-0">
              <div className="sticky top-24 space-y-4">
                {/* Pay on Delivery badge */}
                <div className="flex items-center gap-2 px-4 py-3 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800">
                  <Banknote size={20} className="text-green-600 dark:text-green-400 flex-shrink-0" />
                  <div>
                    <p className="font-semibold text-green-800 dark:text-green-300 text-sm">
                      Pay on Delivery
                    </p>
                    <p className="text-xs text-green-600 dark:text-green-500">
                      Cash payment when your order arrives
                    </p>
                  </div>
                </div>

                {/* Order summary card */}
                <div className="bg-white dark:bg-stone-800 rounded-xl border border-stone-200 dark:border-stone-700 overflow-hidden">
                  <div
                    className="px-4 py-3 border-b border-stone-200 dark:border-stone-700"
                    style={{ backgroundColor: "var(--color-primary)" }}
                  >
                    <h2 className="font-semibold text-white text-sm flex items-center gap-2">
                      <ShoppingCart size={15} />
                      Order Summary
                    </h2>
                  </div>

                  {/* Items */}
                  <div className="divide-y divide-stone-100 dark:divide-stone-700">
                    {cartItems.map((entry) => {
                      const lineTotal = parseCurrency(entry.item.price) * entry.quantity;
                      return (
                        <div key={entry.menu_item_id} className="px-4 py-3 flex items-start gap-3">
                          <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium text-stone-900 dark:text-stone-100 truncate">
                              {entry.item.name}
                            </p>
                            <p className="text-xs text-stone-500 dark:text-stone-400 mt-0.5">
                              {entry.quantity} × {formatCurrency(parseCurrency(entry.item.price))}
                            </p>
                            {entry.special_instructions && (
                              <p className="text-xs text-stone-400 dark:text-stone-500 italic mt-0.5 truncate">
                                "{entry.special_instructions}"
                              </p>
                            )}
                          </div>
                          <span className="font-semibold text-sm text-stone-900 dark:text-stone-100 flex-shrink-0">
                            {formatCurrency(lineTotal)}
                          </span>
                        </div>
                      );
                    })}
                  </div>

                  {/* Totals */}
                  <div className="border-t border-stone-200 dark:border-stone-700 px-4 py-4 space-y-2 bg-stone-50 dark:bg-stone-900">
                    <div className="flex justify-between text-sm">
                      <span className="text-stone-500 dark:text-stone-400">Subtotal</span>
                      <span className="font-medium text-stone-900 dark:text-stone-100">
                        {formatCurrency(subtotal)}
                      </span>
                    </div>
                    <div className="flex justify-between text-sm">
                      <span className="text-stone-500 dark:text-stone-400">Delivery Fee</span>
                      <span
                        className={`font-medium ${
                          selectedZone
                            ? "text-stone-900 dark:text-stone-100"
                            : "text-stone-400 dark:text-stone-500 italic"
                        }`}
                      >
                        {selectedZone ? formatCurrency(deliveryFee) : "Select zone"}
                      </span>
                    </div>
                    <label className="flex items-center gap-2 text-xs text-stone-600 dark:text-stone-300 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={includeDeliveryFee}
                        onChange={(event) => setIncludeDeliveryFee(event.target.checked)}
                        className="rounded border-stone-300 text-amber-700 focus:ring-amber-600"
                      />
                      Include delivery fee in total
                    </label>
                    <div className="flex justify-between text-base font-bold border-t border-stone-200 dark:border-stone-700 pt-2 mt-2">
                      <span className="text-stone-900 dark:text-stone-100">Total</span>
                      <span style={{ color: "var(--color-accent)" }}>{formatCurrency(total)}</span>
                    </div>
                  </div>
                </div>

                {gatewayEnabled && (
                  <div className="rounded-xl border border-stone-200 dark:border-stone-700 p-4 space-y-2">
                    <p className="text-xs font-bold uppercase tracking-wide text-stone-500 dark:text-stone-400">Payment method</p>
                    <label className="flex items-center gap-3 rounded-lg p-3 hover:bg-stone-50 dark:hover:bg-stone-700/50 cursor-pointer">
                      <input type="radio" name="payment_method" checked={paymentMethod === "cash"} onChange={() => setPaymentMethod("cash")} />
                      <Banknote size={18} className="text-[var(--color-success)]" />
                      <span className="text-sm text-stone-800 dark:text-stone-100">Cash on delivery</span>
                    </label>
                    <label className="flex items-center gap-3 rounded-lg p-3 hover:bg-stone-50 dark:hover:bg-stone-700/50 cursor-pointer">
                      <input type="radio" name="payment_method" checked={paymentMethod === "gateway"} onChange={() => setPaymentMethod("gateway")} />
                      <CreditCard size={18} className="text-[var(--color-accent)]" />
                      <span className="text-sm text-stone-800 dark:text-stone-100">Pay now with {gatewayName} <span className="text-xs text-stone-500">(card or Mobile Money)</span></span>
                    </label>
                  </div>
                )}

                {/* Submit */}
                <button
                  type="submit"
                  disabled={submitting}
                  className="w-full flex items-center justify-center gap-2 py-3.5 rounded-xl font-bold text-white text-sm transition-opacity hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed"
                  style={{ backgroundColor: "var(--color-primary)" }}
                >
                  {submitting ? (
                    <>
                      <Loader2 size={18} className="animate-spin" />
                      Placing Order…
                    </>
                  ) : (
                    <>
                      {paymentMethod === "gateway" ? <CreditCard size={18} /> : <Banknote size={18} />}
                      {paymentMethod === "gateway" ? `Continue to ${gatewayName} payment` : "Place Order — Pay on Delivery"}
                    </>
                  )}
                </button>

                <p className="text-xs text-center text-stone-400 dark:text-stone-500">
                  By placing your order you agree to our terms of service
                </p>
              </div>
            </aside>
          </div>
        </form>
      </div>
    </div>
  );
}
