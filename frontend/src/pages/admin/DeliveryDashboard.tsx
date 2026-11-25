import { useState, useEffect, useCallback, useRef } from "react";
import {
  Truck,
  RefreshCw,
  Clock,
  Phone,
  MapPin,
  Package,
  CheckCircle,
  AlertCircle,
  User,
  X,
  ChevronDown,
  ChevronUp,
  Loader2,
  Banknote,
} from "lucide-react";
import api from "@/lib/api";
import echo from "@/lib/echo";
import { useAuth } from "@/app/providers/useAuth";
import type { DineInOrder } from "@/types/api";

// ── Helpers ─────────────────────────────────────────────────────────────────

function formatCurrency(val: string | number) {
  return `GH₵ ${parseFloat(String(val)).toFixed(2)}`;
}

function timeAgo(dateStr: string): string {
  const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
  if (diff < 60) return `${diff}s ago`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}

function compactItems(items: DineInOrder["items"]): string {
  return items
    .map((i) => `${i.quantity}× ${i.item_name}`)
    .join(", ");
}

// ── Types ────────────────────────────────────────────────────────────────────

type ToastEntry = { id: number; message: string; type: "info" | "success" | "error" };

// ── Toast system ─────────────────────────────────────────────────────────────

function ToastContainer({
  toasts,
  onDismiss,
}: {
  toasts: ToastEntry[];
  onDismiss: (id: number) => void;
}) {
  return (
    <div className="fixed top-4 right-4 z-50 flex flex-col gap-2 w-80 max-w-full">
      {toasts.map((t) => (
        <div
          key={t.id}
          className={`flex items-start gap-2 rounded-xl px-4 py-3 shadow-lg text-sm font-medium text-white transition-all ${
            t.type === "error"
              ? "bg-red-600"
              : t.type === "success"
              ? "bg-emerald-600"
              : "bg-[#302019]"
          }`}
        >
          <span className="flex-1">{t.message}</span>
          <button
            onClick={() => onDismiss(t.id)}
            className="ml-1 opacity-70 hover:opacity-100 transition-opacity"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      ))}
    </div>
  );
}

// ── Skeleton card ─────────────────────────────────────────────────────────────

function SkeletonCard() {
  return (
    <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 animate-pulse space-y-3">
      <div className="h-4 bg-[var(--color-surface-secondary)] rounded w-2/3" />
      <div className="h-3 bg-[var(--color-surface-secondary)] rounded w-1/2" />
      <div className="h-3 bg-[var(--color-surface-secondary)] rounded w-3/4" />
      <div className="h-8 bg-[var(--color-surface-secondary)] rounded w-1/3 mt-2" />
    </div>
  );
}

// ── Payment status badge ──────────────────────────────────────────────────────

function PaymentBadge({ status }: { status: string }) {
  const cls =
    status === "paid"
      ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300"
      : "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300";
  return (
    <span className={`text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full ${cls}`}>
      {status}
    </span>
  );
}

// ── Status badge ──────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
  const map: Record<string, string> = {
    pending: "bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300",
    confirmed: "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300",
    preparing: "bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300",
    ready: "bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300",
    out_for_delivery: "bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300",
    delivered: "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300",
    completed: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
    cancelled: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300",
  };
  const cls = map[status] ?? "bg-gray-100 text-gray-700";
  return (
    <span className={`text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full ${cls}`}>
      {status.replace(/_/g, " ")}
    </span>
  );
}

// ── Order Card ────────────────────────────────────────────────────────────────

interface OrderCardProps {
  order: DineInOrder;
  currentUserId: number | undefined;
  isAdmin: boolean;
  canAssign: boolean;
  canDispatch: boolean;
  onAction: (
    orderId: number,
    action: "assign" | "out_for_delivery" | "delivered" | "confirm_payment" | "cancelled"
  ) => Promise<void>;
  loadingId: number | null;
}

function OrderCard({
  order,
  currentUserId,
  isAdmin,
  canAssign,
  canDispatch,
  onAction,
  loadingId,
}: OrderCardProps) {
  const [expanded, setExpanded] = useState(false);
  const isLoading = loadingId === order.id;
  const isAssignedToMe = order.delivery_staff_id === currentUserId;
  const isUnassigned = !order.delivery_staff_id;

  return (
    <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-sm hover:shadow-md transition-shadow overflow-hidden">
      {/* Header strip */}
      <div className="flex items-center gap-2 px-4 pt-4 pb-2 flex-wrap">
        <span className="font-mono text-xs font-bold text-[var(--color-text)] bg-[var(--color-surface-secondary)] px-2 py-1 rounded-lg">
          {order.order_number}
        </span>
        <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-orange-500 text-white">
          DELIVERY
        </span>
        <StatusBadge status={order.status} />
        <span className="ml-auto text-[10px] text-[var(--color-text-secondary)] flex items-center gap-1 whitespace-nowrap">
          <Clock className="w-3 h-3" />
          {timeAgo(order.created_at)}
        </span>
      </div>

      <div className="px-4 pb-4 space-y-3">
        {/* Customer */}
        <div className="flex items-start gap-2">
          <User className="w-4 h-4 mt-0.5 text-[var(--color-text-secondary)] shrink-0" />
          <div className="min-w-0">
            <p className="text-sm font-semibold text-[var(--color-text)] truncate">{order.customer_name}</p>
            <a
              href={`tel:${order.customer_phone}`}
              className="text-xs text-[#BD7048] hover:underline flex items-center gap-1"
            >
              <Phone className="w-3 h-3" />
              {order.customer_phone}
            </a>
          </div>
        </div>

        {/* Zone & Address */}
        <div className="flex items-start gap-2">
          <MapPin className="w-4 h-4 mt-0.5 text-[var(--color-text-secondary)] shrink-0" />
          <div className="min-w-0 flex-1">
            {order.delivery_zone && (
              <span className="text-xs font-semibold text-[#BD7048]">
                {order.delivery_zone.name}
              </span>
            )}
            <p
              className={`text-xs text-[var(--color-text-secondary)] mt-0.5 ${
                expanded ? "" : "truncate"
              }`}
            >
              {order.delivery_address || "No address provided"}
            </p>
            {order.delivery_address && order.delivery_address.length > 60 && (
              <button
                onClick={() => setExpanded((p) => !p)}
                className="text-[10px] text-[#BD7048] hover:underline flex items-center gap-0.5 mt-0.5"
              >
                {expanded ? (
                  <>
                    <ChevronUp className="w-3 h-3" /> Less
                  </>
                ) : (
                  <>
                    <ChevronDown className="w-3 h-3" /> More
                  </>
                )}
              </button>
            )}
          </div>
        </div>

        {/* Items */}
        <div className="flex items-start gap-2">
          <Package className="w-4 h-4 mt-0.5 text-[var(--color-text-secondary)] shrink-0" />
          <p className="text-xs text-[var(--color-text-secondary)] line-clamp-2">
            {compactItems(order.items)}
          </p>
        </div>

        {/* Pricing */}
        <div className="rounded-xl bg-[var(--color-surface-secondary)] px-3 py-2 space-y-1">
          <div className="flex justify-between text-xs text-[var(--color-text-secondary)]">
            <span>Subtotal</span>
            <span>{formatCurrency(order.subtotal)}</span>
          </div>
          <div className="flex justify-between text-xs text-[var(--color-text-secondary)]">
            <span>Delivery Fee</span>
            <span>{formatCurrency(order.delivery_fee)}</span>
          </div>
          <div className="flex justify-between text-sm font-bold text-[var(--color-text)] border-t border-[var(--color-border)] pt-1 mt-1">
            <span>Total</span>
            <span>{formatCurrency(order.total)}</span>
          </div>
        </div>

        {/* Payment */}
        <div className="flex items-center gap-2 flex-wrap">
          <span className="text-xs text-[var(--color-text-secondary)]">
            {order.payment_method === "cash" ? "Cash on Delivery" : `Online · ${order.payment_details?.gateway_name || "Gateway"}`}
          </span>
          <PaymentBadge status={order.payment_status === "paid" ? "Paid" : order.payment_method === "gateway" ? "Gateway Pending" : "Cash Pending"} />
          {order.payment_method === "gateway" && order.payment_details?.reference && <span className="break-all text-[10px] text-[var(--color-text-secondary)]">Ref: {order.payment_details.reference}</span>}
        </div>

        {/* Assignment */}
        <div className="flex items-center gap-1.5 text-xs">
          {isUnassigned ? (
            <span className="text-orange-600 dark:text-orange-400 font-semibold flex items-center gap-1">
              <AlertCircle className="w-3.5 h-3.5" /> Unassigned
            </span>
          ) : (
            <span className="text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1">
              <CheckCircle className="w-3.5 h-3.5" />
              {isAssignedToMe ? "Assigned to Me" : `Staff #${order.delivery_staff_id}`}
            </span>
          )}
        </div>

        {/* Notes */}
        {order.customer_notes && (
          <p className="text-xs italic text-[var(--color-text-secondary)] border-l-2 border-[#BD7048] pl-2">
            {order.customer_notes}
          </p>
        )}

        {/* Action buttons */}
        <div className="flex flex-wrap gap-2 pt-1">
          {/* Assign to Me */}
          {order.status === "ready" && isUnassigned && (canAssign || isAdmin) && (
            <button
              onClick={() => onAction(order.id, "assign")}
              disabled={isLoading}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#BD7048] text-white text-xs font-semibold hover:bg-[#a55e3a] disabled:opacity-60 transition-colors"
            >
              {isLoading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <User className="w-3.5 h-3.5" />}
              Assign to Me
            </button>
          )}

          {/* Mark Out for Delivery */}
          {order.status === "ready" && (isAssignedToMe || isAdmin) && !isUnassigned && (canDispatch || isAdmin) && (
            <button
              onClick={() => onAction(order.id, "out_for_delivery")}
              disabled={isLoading}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-semibold hover:bg-indigo-700 disabled:opacity-60 transition-colors"
            >
              {isLoading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Truck className="w-3.5 h-3.5" />}
              Out for Delivery
            </button>
          )}

          {/* Mark Delivered */}
          {order.status === "out_for_delivery" && (isAssignedToMe || isAdmin) && (
            <button
              onClick={() => onAction(order.id, "delivered")}
              disabled={isLoading}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 disabled:opacity-60 transition-colors"
            >
              {isLoading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <CheckCircle className="w-3.5 h-3.5" />}
              Mark Delivered
            </button>
          )}

          {/* Confirm Cash Collected (Explicit action when cash is received) */}
          {order.status === "delivered" && order.payment_status === "pending" && (isAssignedToMe || isAdmin) && (
            <button
              onClick={() => onAction(order.id, "confirm_payment")}
              disabled={isLoading}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-700 text-white text-xs font-semibold hover:bg-emerald-800 disabled:opacity-60 transition-colors shadow-sm"
              title="Explicitly confirm cash payment collected in full"
            >
              {isLoading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Banknote className="w-3.5 h-3.5" />}
              Confirm Cash (GH₵ {parseFloat(order.total).toFixed(2)})
            </button>
          )}

          {/* Cancel (admin only, pending orders) */}
          {isAdmin && order.status === "pending" && (
            <button
              onClick={() => onAction(order.id, "cancelled")}
              disabled={isLoading}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs font-semibold hover:bg-red-700 disabled:opacity-60 transition-colors"
            >
              {isLoading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <X className="w-3.5 h-3.5" />}
              Cancel
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

// ── Column ────────────────────────────────────────────────────────────────────

function KanbanColumn({
  title,
  icon: Icon,
  orders,
  accentColor,
  emptyMessage,
  currentUserId,
  isAdmin,
  canAssign,
  canDispatch,
  onAction,
  loadingId,
}: {
  title: string;
  icon: React.ElementType;
  orders: DineInOrder[];
  accentColor: string;
  emptyMessage: string;
  currentUserId: number | undefined;
  isAdmin: boolean;
  canAssign: boolean;
  canDispatch: boolean;
  onAction: OrderCardProps["onAction"];
  loadingId: number | null;
}) {
  return (
    <div className="flex flex-col min-w-0">
      <div
        className="flex items-center gap-2 mb-3 px-1 pb-2 border-b-2"
        style={{ borderColor: accentColor }}
      >
        <Icon className="w-4 h-4" style={{ color: accentColor }} />
        <h2 className="font-bold text-sm text-[var(--color-text)]">{title}</h2>
        <span
          className="ml-auto text-xs font-bold px-2 py-0.5 rounded-full text-white"
          style={{ background: accentColor }}
        >
          {orders.length}
        </span>
      </div>
      <div className="space-y-3 flex-1">
        {orders.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-6 text-center text-sm text-[var(--color-text-secondary)]">
            {emptyMessage}
          </div>
        ) : (
          orders.map((o) => (
            <OrderCard
              key={o.id}
              order={o}
              currentUserId={currentUserId}
              isAdmin={isAdmin}
              canAssign={canAssign}
              canDispatch={canDispatch}
              onAction={onAction}
              loadingId={loadingId}
            />
          ))
        )}
      </div>
    </div>
  );
}

// ── Main Dashboard ────────────────────────────────────────────────────────────

export default function DeliveryDashboard() {
  const { user } = useAuth();
  const isAdmin = user?.roles?.includes("super-admin") ?? false;
  const canAssign = user?.permissions?.includes("delivery.orders.assign") ?? false;
  const canDispatch = user?.permissions?.includes("delivery.orders.dispatch") ?? false;

  const [orders, setOrders] = useState<DineInOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);
  const [loadingActionId, setLoadingActionId] = useState<number | null>(null);
  const [toasts, setToasts] = useState<ToastEntry[]>([]);
  const toastIdRef = useRef(0);

  const addToast = useCallback((message: string, type: ToastEntry["type"] = "info") => {
    const id = ++toastIdRef.current;
    setToasts((prev) => [...prev, { id, message, type }]);
    setTimeout(() => setToasts((prev) => prev.filter((t) => t.id !== id)), 5000);
  }, []);

  const dismissToast = useCallback((id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const fetchOrders = useCallback(async () => {
    try {
      setError(null);
      const res = await api.get("/delivery/orders", {
        params: { statuses: "pending,confirmed,preparing,ready,out_for_delivery,delivered" },
      });
      setOrders(res.data.data ?? []);
      setLastUpdated(new Date());
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        "Failed to load delivery orders.";
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, []);

  // Initial fetch
  useEffect(() => {
    fetchOrders();
  }, [fetchOrders]);

  // Real-time subscription
  useEffect(() => {
    const channel = echo.private("orders.delivery");
    const events = [
      ".order.placed",
      ".order.confirmed",
      ".order.preparing",
      ".order.ready",
      ".order.out_for_delivery",
      ".order.delivered",
      ".order.completed",
      ".order.cancelled",
    ];
    const handler = (data: { order?: DineInOrder; message?: string }) => {
      const eventLabel = data?.order?.order_number ?? "An order";
      addToast(`🚚 Update: ${eventLabel} status changed`, "info");
      fetchOrders();
    };
    events.forEach((event) => channel.listen(event, handler));
    return () => {
      events.forEach((event) => channel.stopListening(event, handler));
    };
  }, [fetchOrders, addToast]);

  // Actions
  const handleAction = useCallback(
    async (
      orderId: number,
      action: "assign" | "out_for_delivery" | "delivered" | "confirm_payment" | "cancelled"
    ) => {
      setLoadingActionId(orderId);
      try {
        if (action === "assign") {
          await api.post(`/delivery/orders/${orderId}/assign`);
          addToast("Order assigned to you!", "success");
        } else if (action === "confirm_payment") {
          await api.post(`/delivery/orders/${orderId}/confirm-payment`);
          addToast("Cash payment confirmed collected!", "success");
        } else {
          await api.patch(`/delivery/orders/${orderId}/status`, {
            status: action,
          });
          addToast(`Order marked as ${action.replace(/_/g, " ")}`, "success");
        }
        await fetchOrders();
      } catch (err: unknown) {
        const msg =
          (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Action failed. Please try again.";
        addToast(msg, "error");
      } finally {
        setLoadingActionId(null);
      }
    },
    [fetchOrders, addToast]
  );

  // Bucket orders into columns
  const newOrders = orders.filter((o) =>
    ["pending", "confirmed", "preparing"].includes(o.status)
  );
  const readyOrders = orders.filter((o) => o.status === "ready");
  const outForDelivery = orders.filter((o) => o.status === "out_for_delivery");
  const completedOrders = orders
    .filter((o) => ["delivered", "completed"].includes(o.status))
    .slice(0, 20);

  const sharedProps = {
    currentUserId: user?.id,
    isAdmin,
    canAssign,
    canDispatch,
    onAction: handleAction,
    loadingId: loadingActionId,
  };

  return (
    <div className="min-h-screen bg-[var(--color-background)]">
      <ToastContainer toasts={toasts} onDismiss={dismissToast} />

      {/* Header */}
      <div className="mb-6 flex flex-col sm:flex-row sm:items-center gap-3">
        <div className="flex items-center gap-3">
          <div
            className="w-10 h-10 rounded-xl flex items-center justify-center shadow-sm"
            style={{ background: "#302019" }}
          >
            <Truck className="w-5 h-5 text-[#D8AA65]" />
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-[var(--color-text)] leading-tight">
              🚚 Delivery Dashboard
            </h1>
            {lastUpdated && (
              <p className="text-xs text-[var(--color-text-secondary)]">
                Last updated: {lastUpdated.toLocaleTimeString()}
              </p>
            )}
          </div>
        </div>
        <button
          onClick={() => {
            setLoading(true);
            fetchOrders();
          }}
          disabled={loading}
          className="sm:ml-auto flex items-center gap-2 px-4 py-2 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border)] text-sm font-semibold text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] disabled:opacity-60 transition-colors"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? "animate-spin" : ""}`} />
          Refresh
        </button>
      </div>

      {/* Error state */}
      {error && (
        <div className="mb-6 rounded-2xl border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4 flex items-center gap-3">
          <AlertCircle className="w-5 h-5 text-red-600 dark:text-red-400 shrink-0" />
          <p className="text-sm text-red-700 dark:text-red-300 flex-1">{error}</p>
          <button
            onClick={fetchOrders}
            className="text-sm font-semibold text-red-700 dark:text-red-300 underline"
          >
            Retry
          </button>
        </div>
      )}

      {/* Loading skeletons */}
      {loading && orders.length === 0 ? (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {Array.from({ length: 6 }).map((_, i) => (
            <SkeletonCard key={i} />
          ))}
        </div>
      ) : (
        <>
          {/* Kanban columns */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <KanbanColumn
              title="New Orders"
              icon={Package}
              orders={newOrders}
              accentColor="#BD7048"
              emptyMessage="No new delivery orders"
              {...sharedProps}
            />
            <KanbanColumn
              title="Ready for Dispatch"
              icon={CheckCircle}
              orders={readyOrders}
              accentColor="#D8AA65"
              emptyMessage="No orders ready for dispatch"
              {...sharedProps}
            />
            <KanbanColumn
              title="Out for Delivery"
              icon={Truck}
              orders={outForDelivery}
              accentColor="#78866C"
              emptyMessage="No orders out for delivery"
              {...sharedProps}
            />
          </div>

          {/* Completed section */}
          <div>
            <div className="flex items-center gap-2 mb-4 pb-2 border-b border-[var(--color-border)]">
              <CheckCircle className="w-4 h-4 text-[var(--color-text-secondary)]" />
              <h2 className="font-bold text-sm text-[var(--color-text)]">
                Recently Completed
              </h2>
              <span className="ml-auto text-xs text-[var(--color-text-secondary)]">
                Last {completedOrders.length}
              </span>
            </div>
            {completedOrders.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center text-sm text-[var(--color-text-secondary)]">
                No completed deliveries yet
              </div>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                {completedOrders.map((o) => (
                  <OrderCard
                    key={o.id}
                    order={o}
                    currentUserId={user?.id}
                    isAdmin={isAdmin}
                    canAssign={canAssign}
                    canDispatch={canDispatch}
                    onAction={handleAction}
                    loadingId={loadingActionId}
                  />
                ))}
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}
