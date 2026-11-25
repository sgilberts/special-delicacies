import { useEffect } from "react";
import { useParams, useLocation, Link } from "react-router-dom";
import {
  CheckCircle2,
  Banknote,
  MapPin,
  Phone,
  UtensilsCrossed,
  ArrowLeft,
  ShoppingBag,
  Clock,
  Receipt,
} from "lucide-react";
import type { PlacedOrder } from "@/types/api";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatCurrency(val: string | number): string {
  const num = typeof val === "string" ? parseFloat(val) : val;
  return isNaN(num) ? String(val) : `GH₵ ${num.toFixed(2)}`;
}

function formatDate(dateStr: string): string {
  try {
    return new Intl.DateTimeFormat("en-GH", {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date(dateStr));
  } catch {
    return dateStr;
  }
}

type StatusStyle = {
  label: string;
  bg: string;
  text: string;
  dot: string;
};

function getStatusStyle(status: string): StatusStyle {
  const s = status.toLowerCase();
  if (s === "confirmed" || s === "accepted")
    return {
      label: "Confirmed",
      bg: "bg-blue-50 dark:bg-blue-900/20",
      text: "text-blue-700 dark:text-blue-300",
      dot: "bg-blue-500",
    };
  if (s === "out_for_delivery" || s === "out for delivery")
    return {
      label: "Out for Delivery",
      bg: "bg-orange-50 dark:bg-orange-900/20",
      text: "text-orange-700 dark:text-orange-300",
      dot: "bg-orange-500",
    };
  if (s === "delivered" || s === "completed")
    return {
      label: "Delivered",
      bg: "bg-green-50 dark:bg-green-900/20",
      text: "text-green-700 dark:text-green-300",
      dot: "bg-green-500",
    };
  if (s === "cancelled" || s === "canceled")
    return {
      label: "Cancelled",
      bg: "bg-red-50 dark:bg-red-900/20",
      text: "text-red-700 dark:text-red-300",
      dot: "bg-red-500",
    };
  // Default: pending
  return {
    label: "Pending",
    bg: "bg-yellow-50 dark:bg-yellow-900/20",
    text: "text-yellow-700 dark:text-yellow-300",
    dot: "bg-yellow-500",
  };
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
  const style = getStatusStyle(status);
  return (
    <span
      className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-semibold ${style.bg} ${style.text}`}
    >
      <span className={`w-2 h-2 rounded-full ${style.dot}`} />
      {style.label}
    </span>
  );
}

// ─── Minimal Fallback ─────────────────────────────────────────────────────────

function MinimalConfirmation({ orderNumber }: { orderNumber: string }) {
  const { content } = useRestaurantContent();
  return (
    <div className="min-h-screen bg-stone-50 dark:bg-stone-950 flex flex-col items-center justify-center px-4 py-16">
      <div className="max-w-md w-full bg-white dark:bg-stone-800 rounded-2xl shadow-md border border-stone-200 dark:border-stone-700 p-8 text-center">
        <div className="flex justify-center mb-5">
          <div className="w-20 h-20 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
            <CheckCircle2 size={44} className="text-green-500" />
          </div>
        </div>

        <h1 className="text-2xl font-bold text-stone-900 dark:text-stone-100 mb-2">
          {content.restaurant_name} · Order Placed!
        </h1>

        <div
          className="inline-block px-4 py-1.5 rounded-full text-sm font-bold mb-5"
          style={{ backgroundColor: "var(--color-primary)", color: "var(--color-highlight)" }}
        >
          {orderNumber}
        </div>

        <div className="flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 mb-6">
          <Banknote size={18} className="text-green-600 dark:text-green-400" />
          <span className="font-semibold text-green-800 dark:text-green-300 text-sm">
            Pay on Delivery
          </span>
        </div>

        <p className="text-stone-500 dark:text-stone-400 text-sm mb-6 leading-relaxed">
          Your order has been received. Our team will contact you to confirm your order
          and arrange delivery. Please have your phone available.
        </p>

        <div className="flex flex-col sm:flex-row gap-3">
          <Link
            to="/"
            className="flex-1 py-2.5 px-4 rounded-xl font-semibold text-sm border border-stone-300 dark:border-stone-600 text-stone-700 dark:text-stone-300 hover:bg-stone-50 dark:hover:bg-stone-700 transition-colors text-center"
          >
            Back to Home
          </Link>
          <Link
            to="/delivery"
            className="flex-1 py-2.5 px-4 rounded-xl font-semibold text-sm text-white transition-opacity hover:opacity-90 text-center"
            style={{ backgroundColor: "var(--color-primary)" }}
          >
            Order More Food
          </Link>
        </div>
      </div>
    </div>
  );
}

// ─── Full Confirmation ────────────────────────────────────────────────────────

function FullConfirmation({ order }: { order: PlacedOrder }) {
  const { content } = useRestaurantContent();
  const subtotal = parseFloat(order.subtotal);
  const deliveryFee = parseFloat(order.delivery_fee);
  const total = parseFloat(order.total);

  return (
    <div className="min-h-screen bg-stone-50 dark:bg-stone-950 pb-16">
      {/* Header */}
      <header
        className="shadow-md"
        style={{ backgroundColor: "var(--color-primary)" }}
      >
        <div className="max-w-2xl mx-auto px-4 h-14 flex items-center justify-between">
          <Link
            to="/"
            className="flex items-center gap-2 text-sm text-stone-300 hover:text-white transition-colors"
          >
            <ArrowLeft size={16} />
            Home
          </Link>
          <div className="flex items-center gap-2">
            <UtensilsCrossed size={18} className="text-amber-400" />
            {content.restaurant_logo && <img src={assetUrl(content.restaurant_logo) ?? undefined} alt={content.restaurant_name} className="h-8 w-8 rounded-lg object-contain" />}
            <span className="text-white font-semibold text-sm hidden sm:block">{content.restaurant_name}</span>
          </div>
          <Link
            to="/delivery"
            className="flex items-center gap-1.5 text-sm text-stone-300 hover:text-white transition-colors"
          >
            <ShoppingBag size={16} />
            <span className="hidden sm:inline">Order Again</span>
          </Link>
        </div>
      </header>

      <div className="max-w-2xl mx-auto px-4 py-8 space-y-5">
        {/* Success hero */}
        <div className="bg-white dark:bg-stone-800 rounded-2xl shadow-sm border border-stone-200 dark:border-stone-700 p-8 text-center">
          <div className="flex justify-center mb-5">
            <div className="w-20 h-20 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
              <CheckCircle2 size={44} className="text-green-500" />
            </div>
          </div>

          <h1 className="text-2xl font-bold text-stone-900 dark:text-stone-100 mb-1">
            Order Placed Successfully!
          </h1>
          <p className="text-sm text-stone-500 dark:text-stone-400 mb-5">
            Thank you, {order.customer_name}! Your delivery is on its way.
          </p>

          {/* Order number badge */}
          <div
            className="inline-block px-5 py-2 rounded-full font-bold text-base mb-5 tracking-wider"
            style={{ backgroundColor: "var(--color-primary)", color: "var(--color-highlight)" }}
          >
            {order.order_number}
          </div>

          {/* Pay on delivery */}
          <div className="flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 mb-5">
            <Banknote size={20} className="text-green-600 dark:text-green-400" />
            <div className="text-left">
              <p className="font-semibold text-green-800 dark:text-green-300 text-sm">
                Pay on Delivery
              </p>
              <p className="text-xs text-green-600 dark:text-green-500">
                Cash payment when your order arrives
              </p>
            </div>
          </div>

          {/* Status + contact note */}
          <div className="flex flex-col items-center gap-3">
            <StatusBadge status={order.status} />
            <div className="flex items-center gap-2 text-xs text-stone-500 dark:text-stone-400 bg-stone-50 dark:bg-stone-700/50 px-4 py-2 rounded-lg">
              <Clock size={13} />
              Our team will contact you to confirm your order
            </div>
            {order.created_at && (
              <p className="text-xs text-stone-400 dark:text-stone-500">
                Ordered on {formatDate(order.created_at)}
              </p>
            )}
          </div>
        </div>

        {/* Order Items */}
        {order.items && order.items.length > 0 && (
          <div className="bg-white dark:bg-stone-800 rounded-2xl shadow-sm border border-stone-200 dark:border-stone-700 overflow-hidden">
            <div
              className="px-5 py-3 border-b border-stone-200 dark:border-stone-700 flex items-center gap-2"
              style={{ backgroundColor: "var(--color-primary)" }}
            >
              <Receipt size={15} className="text-amber-400" />
              <h2 className="font-semibold text-white text-sm">Order Items</h2>
            </div>

            {/* Table header */}
            <div className="hidden sm:grid grid-cols-12 gap-2 px-5 py-2 text-xs font-semibold text-stone-500 dark:text-stone-400 uppercase tracking-wide border-b border-stone-100 dark:border-stone-700 bg-stone-50 dark:bg-stone-900">
              <div className="col-span-5">Item</div>
              <div className="col-span-2 text-center">Qty</div>
              <div className="col-span-2 text-right">Unit Price</div>
              <div className="col-span-3 text-right">Line Total</div>
            </div>

            <div className="divide-y divide-stone-100 dark:divide-stone-700">
              {order.items.map((item) => (
                <div
                  key={item.id}
                  className="px-5 py-3 flex flex-col sm:grid sm:grid-cols-12 sm:gap-2 sm:items-center"
                >
                  <div className="sm:col-span-5">
                    <p className="text-sm font-medium text-stone-900 dark:text-stone-100">
                      {item.item_name}
                    </p>
                    {item.special_instructions && (
                      <p className="text-xs text-stone-400 dark:text-stone-500 italic mt-0.5">
                        {item.special_instructions}
                      </p>
                    )}
                    {/* Mobile: qty + prices inline */}
                    <div className="sm:hidden flex items-center justify-between mt-1 text-xs text-stone-500 dark:text-stone-400">
                      <span>
                        {item.quantity} × {formatCurrency(item.unit_price)}
                      </span>
                      <span className="font-semibold text-stone-900 dark:text-stone-100">
                        {formatCurrency(item.line_total)}
                      </span>
                    </div>
                  </div>
                  <div className="hidden sm:block sm:col-span-2 text-center text-sm text-stone-700 dark:text-stone-300">
                    {item.quantity}
                  </div>
                  <div className="hidden sm:block sm:col-span-2 text-right text-sm text-stone-700 dark:text-stone-300">
                    {formatCurrency(item.unit_price)}
                  </div>
                  <div className="hidden sm:block sm:col-span-3 text-right text-sm font-semibold text-stone-900 dark:text-stone-100">
                    {formatCurrency(item.line_total)}
                  </div>
                </div>
              ))}
            </div>

            {/* Totals */}
            <div className="border-t border-stone-200 dark:border-stone-700 px-5 py-4 bg-stone-50 dark:bg-stone-900 space-y-2">
              <div className="flex justify-between text-sm">
                <span className="text-stone-500 dark:text-stone-400">Subtotal</span>
                <span className="font-medium text-stone-900 dark:text-stone-100">
                  {formatCurrency(subtotal)}
                </span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-stone-500 dark:text-stone-400">Delivery Fee</span>
                <span className="font-medium text-stone-900 dark:text-stone-100">
                  {formatCurrency(deliveryFee)}
                </span>
              </div>
              <div className="flex justify-between text-base font-bold border-t border-stone-200 dark:border-stone-700 pt-2 mt-1">
                <span className="text-stone-900 dark:text-stone-100">Total</span>
                <span style={{ color: "var(--color-accent)" }}>{formatCurrency(total)}</span>
              </div>
            </div>
          </div>
        )}

        {/* Delivery Details */}
        <div className="bg-white dark:bg-stone-800 rounded-2xl shadow-sm border border-stone-200 dark:border-stone-700 overflow-hidden">
          <div
            className="px-5 py-3 border-b border-stone-200 dark:border-stone-700 flex items-center gap-2"
            style={{ backgroundColor: "var(--color-primary)" }}
          >
            <MapPin size={15} className="text-amber-400" />
            <h2 className="font-semibold text-white text-sm">Delivery Details</h2>
          </div>
          <div className="px-5 py-4 space-y-3">
            {order.delivery_zone && (
              <div>
                <p className="text-xs font-semibold text-stone-500 dark:text-stone-400 uppercase tracking-wide mb-0.5">
                  Delivery Zone
                </p>
                <p className="text-sm font-medium text-stone-900 dark:text-stone-100">
                  {order.delivery_zone.name}
                </p>
                {order.delivery_zone.description && (
                  <p className="text-xs text-stone-400 dark:text-stone-500 mt-0.5">
                    {order.delivery_zone.description}
                  </p>
                )}
              </div>
            )}
            {order.delivery_address && (
              <div>
                <p className="text-xs font-semibold text-stone-500 dark:text-stone-400 uppercase tracking-wide mb-0.5">
                  Delivery Address
                </p>
                <p className="text-sm text-stone-900 dark:text-stone-100 leading-relaxed">
                  {order.delivery_address}
                </p>
              </div>
            )}
            <div className="flex items-center gap-2">
              <Phone size={14} className="text-stone-400 flex-shrink-0" />
              <div>
                <p className="text-xs font-semibold text-stone-500 dark:text-stone-400 uppercase tracking-wide mb-0.5">
                  Contact Phone
                </p>
                <p className="text-sm text-stone-900 dark:text-stone-100">
                  {order.customer_phone}
                </p>
              </div>
            </div>
          </div>
        </div>

        {/* Action buttons */}
        <div className="flex flex-col sm:flex-row gap-3 pt-2">
          <Link
            to="/"
            className="flex-1 flex items-center justify-center gap-2 py-3 px-6 rounded-xl font-semibold text-sm border border-stone-300 dark:border-stone-600 text-stone-700 dark:text-stone-300 hover:bg-stone-100 dark:hover:bg-stone-700 transition-colors"
          >
            <ArrowLeft size={16} />
            Back to Home
          </Link>
          <Link
            to="/delivery"
            className="flex-1 flex items-center justify-center gap-2 py-3 px-6 rounded-xl font-semibold text-sm text-white transition-opacity hover:opacity-90"
            style={{ backgroundColor: "var(--color-primary)" }}
          >
            <ShoppingBag size={16} />
            Order More Food
          </Link>
        </div>
      </div>
    </div>
  );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function DeliveryConfirmationPage() {
  const { orderNumber } = useParams<{ orderNumber: string }>();
  const location = useLocation();

  // Try to get order from navigation state
  const order = (location.state as { order?: PlacedOrder } | null)?.order ?? null;

  useEffect(() => {
    document.title = order
      ? `Order ${order.order_number} Confirmed | Special Delicacies`
      : `Order Confirmed | Special Delicacies`;
  }, [order]);

  if (!order) {
    return <MinimalConfirmation orderNumber={orderNumber ?? "—"} />;
  }

  return <FullConfirmation order={order} />;
}
