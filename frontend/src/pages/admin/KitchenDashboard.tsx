import { useEffect, useState, useCallback, useMemo } from "react";
import {
  ChefHat,
  Clock,
  CheckCircle2,
  AlertCircle,
  RefreshCw,
  Flame,
  Radio,
  Sparkles,
  AlertTriangle,
  UtensilsCrossed,
} from "lucide-react";
import apiClient from "@/lib/api";
import { useOrdersRealTime } from "@/hooks/useOrdersRealTime";
import { useToast } from "@/components/ui/useToast";
import type { DineInOrder } from "@/types/api";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ErrorState from "@/components/public/ErrorState";

interface UrgencyInfo {
  text: string;
  isUrgent: boolean; // > 15 mins
  isWarning: boolean; // > 10 mins
  minutes: number;
}

function getUrgencyInfo(dateStr?: string): UrgencyInfo {
  if (!dateStr) return { text: "Just now", isUrgent: false, isWarning: false, minutes: 0 };
  const created = new Date(dateStr).getTime();
  if (isNaN(created)) return { text: "Just now", isUrgent: false, isWarning: false, minutes: 0 };
  const diffSec = Math.floor((Date.now() - created) / 1000);
  const minutes = Math.floor(diffSec / 60);

  let text = "Just now";
  if (diffSec < 45) {
    text = "Just now";
  } else if (minutes < 60) {
    text = `${minutes}m ago`;
  } else {
    const hours = Math.floor(minutes / 60);
    text = `${hours}h ${minutes % 60}m ago`;
  }

  return {
    text,
    minutes,
    isUrgent: minutes >= 15,
    isWarning: minutes >= 10 && minutes < 15,
  };
}

export default function KitchenDashboard() {
  const { success: toastSuccess, error: toastError } = useToast();
  const [orders, setOrders] = useState<DineInOrder[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [isRefreshing, setIsRefreshing] = useState<boolean>(false);
  const [updatingId, setUpdatingId] = useState<number | null>(null);
  const [, setTick] = useState<number>(0);

  // Timer to update elapsed times every 15 seconds
  useEffect(() => {
    const timer = setInterval(() => {
      setTick((t) => t + 1);
    }, 15000);
    return () => clearInterval(timer);
  }, []);

  // Fetch kitchen orders
  const fetchOrders = useCallback(async (isInitial = false) => {
    if (isInitial) setLoading(true);
    else setIsRefreshing(true);
    setError(null);

    try {
      const response = await apiClient.get<{ success: boolean; data: DineInOrder[] }>(
        "/orders?statuses=confirmed,preparing,ready"
      );
      if (response.data?.success && Array.isArray(response.data.data)) {
        setOrders(response.data.data);
      } else {
        setOrders([]);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to load kitchen orders";
      setError(msg);
      if (!isInitial) {
        toastError(msg);
      }
    } finally {
      if (isInitial) setLoading(false);
      setIsRefreshing(false);
    }
  }, [toastError]);

  // Initial load
  useEffect(() => {
    fetchOrders(true);
  }, [fetchOrders]);

  // Polling fallback every 20 seconds
  useEffect(() => {
    const interval = setInterval(() => {
      fetchOrders(false);
    }, 20000);
    return () => clearInterval(interval);
  }, [fetchOrders]);

  // Upsert or remove
  const handleIncomingOrder = useCallback((incoming: DineInOrder) => {
    // If incoming order status is no longer confirmed/preparing/ready, remove from kitchen
    if (!["confirmed", "preparing", "ready"].includes(incoming.status)) {
      setOrders((prev) => prev.filter((o) => o.id !== incoming.id));
      return;
    }

    setOrders((prev) => {
      const idx = prev.findIndex((o) => o.id === incoming.id);
      if (idx >= 0) {
        const updated = [...prev];
        updated[idx] = incoming;
        return updated;
      }
      return [incoming, ...prev];
    });
  }, []);

  const handleRemoveOrder = useCallback((orderId: number) => {
    setOrders((prev) => prev.filter((o) => o.id !== orderId));
  }, []);

  // Real-time integration on channel 'orders.kitchen'
  useOrdersRealTime({
    channel: "orders.kitchen",
    onOrderConfirmed: (order) => {
      handleIncomingOrder(order);
      toastSuccess(`New order ticket #${order.order_number} received!`);
    },
    onOrderPreparing: (order) => {
      handleIncomingOrder(order);
    },
    onOrderReady: (order) => {
      handleIncomingOrder(order);
    },
    onOrderDelivered: (order) => {
      handleRemoveOrder(order.id);
    },
    onOrderCompleted: (order) => {
      handleRemoveOrder(order.id);
    },
    onOrderCancelled: (order) => {
      handleRemoveOrder(order.id);
      toastError(`Ticket #${order.order_number} has been cancelled.`);
    },
  });

  // Action button: PATCH status
  const handleStatusTransition = async (orderId: number, nextStatus: "preparing" | "ready") => {
    setUpdatingId(orderId);
    try {
      const response = await apiClient.patch<{ success: boolean; data: DineInOrder; message?: string }>(
        `/orders/${orderId}/status`,
        { status: nextStatus }
      );

      if (response.data?.success && response.data.data) {
        handleIncomingOrder(response.data.data);
        const label = nextStatus === "preparing" ? "Preparation started" : "Marked as READY";
        toastSuccess(`Ticket #${response.data.data.order_number}: ${label}.`);
      } else {
        await fetchOrders(false);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to update ticket status";
      toastError(msg);
    } finally {
      setUpdatingId(null);
    }
  };

  // Group into columns
  const confirmedOrders = useMemo(() => orders.filter((o) => o.status === "confirmed"), [orders]);
  const preparingOrders = useMemo(() => orders.filter((o) => o.status === "preparing"), [orders]);
  const readyOrders = useMemo(() => orders.filter((o) => o.status === "ready"), [orders]);

  if (loading) {
    return <LoadingSpinner message="Loading kitchen display system (KDS)..." />;
  }

  if (error && orders.length === 0) {
    return <ErrorState message={error} onRetry={() => fetchOrders(true)} />;
  }

  return (
    <div className="space-y-6 max-w-7xl mx-auto pb-12">
      {/* ── Top Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-[var(--color-border)]">
        <div className="flex items-center gap-3">
          <div
            className="w-11 h-11 rounded-xl flex items-center justify-center shadow-md"
            style={{ background: "var(--brand-espresso)" }}
          >
            <ChefHat className="w-6 h-6 text-[var(--brand-gold)]" />
          </div>
          <div>
            <h1 className="text-xl sm:text-2xl font-black tracking-tight text-[var(--color-text)]">
              Kitchen Display System (KDS)
            </h1>
            <div className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)]">
              <span className="inline-flex items-center gap-1.5 font-bold text-emerald-600 dark:text-emerald-400">
                <Radio className="w-3.5 h-3.5 animate-pulse" />
                Live Channel: orders.kitchen
              </span>
              <span>•</span>
              <span>Auto-poll 20s</span>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <div className="hidden sm:flex items-center gap-3 px-3 py-1.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-xs font-bold">
            <span className="text-blue-600 dark:text-blue-400">New: {confirmedOrders.length}</span>
            <span>•</span>
            <span className="text-orange-600 dark:text-orange-400">Cooking: {preparingOrders.length}</span>
            <span>•</span>
            <span className="text-emerald-600 dark:text-emerald-400">Ready: {readyOrders.length}</span>
          </div>

          <button
            type="button"
            onClick={() => fetchOrders(false)}
            disabled={isRefreshing}
            className="flex items-center justify-center gap-2 px-4 py-2 min-h-[44px] rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] font-semibold text-sm transition-all disabled:opacity-50 shadow-sm"
            aria-label="Refresh tickets"
          >
            <RefreshCw className={`w-4 h-4 ${isRefreshing ? "animate-spin text-[var(--color-accent)]" : ""}`} />
            <span>{isRefreshing ? "Syncing..." : "Sync"}</span>
          </button>
        </div>
      </div>

      {/* ── 3-Column Kitchen Board ── */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 items-start">
        {/* ── COLUMN 1: NEW ORDERS (confirmed) ── */}
        <section
          className="rounded-2xl border-2 border-blue-200 dark:border-blue-900 bg-blue-50/40 dark:bg-blue-950/20 p-4 space-y-4 flex flex-col min-h-[500px]"
          aria-label="New Orders Column"
        >
          <div className="flex items-center justify-between pb-3 border-b border-blue-200 dark:border-blue-900">
            <div className="flex items-center gap-2">
              <span className="w-3 h-3 rounded-full bg-blue-500 animate-pulse" />
              <h2 className="font-black text-base sm:text-lg text-blue-950 dark:text-blue-100">
                1. New Orders
              </h2>
            </div>
            <span className="px-3 py-1 rounded-full text-xs font-black bg-blue-600 text-white shadow-sm">
              {confirmedOrders.length}
            </span>
          </div>

          <div className="space-y-4 flex-1">
            {confirmedOrders.length === 0 ? (
              <div className="text-center py-16 text-blue-900/60 dark:text-blue-300/60 text-xs font-medium">
                No new confirmed orders waiting
              </div>
            ) : (
              confirmedOrders.map((order) => (
                <KitchenCard
                  key={order.id}
                  order={order}
                  isUpdating={updatingId === order.id}
                  actionLabel="Start Preparing"
                  actionIcon={Flame}
                  actionColor="bg-amber-600 hover:bg-amber-700 text-white"
                  onAction={() => handleStatusTransition(order.id, "preparing")}
                />
              ))
            )}
          </div>
        </section>

        {/* ── COLUMN 2: PREPARING (preparing) ── */}
        <section
          className="rounded-2xl border-2 border-orange-200 dark:border-orange-900 bg-orange-50/40 dark:bg-orange-950/20 p-4 space-y-4 flex flex-col min-h-[500px]"
          aria-label="Preparing Column"
        >
          <div className="flex items-center justify-between pb-3 border-b border-orange-200 dark:border-orange-900">
            <div className="flex items-center gap-2">
              <Flame className="w-4 h-4 text-orange-500 animate-bounce" />
              <h2 className="font-black text-base sm:text-lg text-orange-950 dark:text-orange-100">
                2. Cooking Now
              </h2>
            </div>
            <span className="px-3 py-1 rounded-full text-xs font-black bg-orange-600 text-white shadow-sm">
              {preparingOrders.length}
            </span>
          </div>

          <div className="space-y-4 flex-1">
            {preparingOrders.length === 0 ? (
              <div className="text-center py-16 text-orange-900/60 dark:text-orange-300/60 text-xs font-medium">
                No orders currently in preparation
              </div>
            ) : (
              preparingOrders.map((order) => (
                <KitchenCard
                  key={order.id}
                  order={order}
                  isUpdating={updatingId === order.id}
                  actionLabel="Mark Ready"
                  actionIcon={CheckCircle2}
                  actionColor="bg-emerald-600 hover:bg-emerald-700 text-white"
                  onAction={() => handleStatusTransition(order.id, "ready")}
                />
              ))
            )}
          </div>
        </section>

        {/* ── COLUMN 3: READY (ready) ── */}
        <section
          className="rounded-2xl border-2 border-emerald-200 dark:border-emerald-900 bg-emerald-50/40 dark:bg-emerald-950/20 p-4 space-y-4 flex flex-col min-h-[500px]"
          aria-label="Ready Column"
        >
          <div className="flex items-center justify-between pb-3 border-b border-emerald-200 dark:border-emerald-900">
            <div className="flex items-center gap-2">
              <Sparkles className="w-4 h-4 text-emerald-500 animate-spin" />
              <h2 className="font-black text-base sm:text-lg text-emerald-950 dark:text-emerald-100">
                3. Ready for Service
              </h2>
            </div>
            <span className="px-3 py-1 rounded-full text-xs font-black bg-emerald-600 text-white shadow-sm">
              {readyOrders.length}
            </span>
          </div>

          <div className="space-y-4 flex-1">
            {readyOrders.length === 0 ? (
              <div className="text-center py-16 text-emerald-900/60 dark:text-emerald-300/60 text-xs font-medium">
                No plates waiting on the counter
              </div>
            ) : (
              readyOrders.map((order) => (
                <KitchenCard
                  key={order.id}
                  order={order}
                  isUpdating={false}
                  actionLabel="Awaiting Waiter Pickup"
                  actionIcon={UtensilsCrossed}
                  actionColor="bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200 cursor-default"
                  onAction={() => {}}
                  isPassive
                />
              ))
            )}
          </div>
        </section>
      </div>
    </div>
  );
}

// ── Kitchen Card Component ──────────────────────────────────────────────────

interface KitchenCardProps {
  order: DineInOrder;
  isUpdating: boolean;
  actionLabel: string;
  actionIcon: React.ElementType;
  actionColor: string;
  onAction: () => void;
  isPassive?: boolean;
}

function KitchenCard({
  order,
  isUpdating,
  actionLabel,
  actionIcon: ActionIcon,
  actionColor,
  onAction,
  isPassive = false,
}: KitchenCardProps) {
  const urgency = getUrgencyInfo(order.created_at);

  return (
    <div
      className={`rounded-xl border shadow-sm flex flex-col justify-between overflow-hidden transition-all bg-[var(--color-surface)] ${
        urgency.isUrgent
          ? "border-red-500 dark:border-red-600 ring-2 ring-red-500/30"
          : urgency.isWarning
          ? "border-amber-400 dark:border-amber-600"
          : "border-[var(--color-border)]"
      }`}
    >
      <div className="p-4 space-y-3">
        {/* Prominent Header: Large Order # & Large Table # */}
        <div className="flex items-start justify-between gap-2 pb-2 border-b border-[var(--color-border)]">
          <div>
            <span className="text-base sm:text-lg font-black font-mono tracking-tight text-[var(--color-accent)] block leading-tight">
              #{order.order_number}
            </span>
            <span className="text-xs text-[var(--color-text-secondary)] font-medium">
              Guest: {order.customer_name || "Dine-in Guest"}
            </span>
          </div>

          <div className="text-right">
            {order.order_type === "delivery" ? (
              <span className="inline-block px-2.5 py-1 rounded-lg font-black text-xs sm:text-sm bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30 shadow-sm">
                🚚 Delivery
              </span>
            ) : order.order_type === "pickup" ? (
              <span className="inline-block px-2.5 py-1 rounded-lg font-black text-xs sm:text-sm bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30 shadow-sm">Customer Pickup</span>
            ) : (
              <span className="inline-block px-3 py-1 rounded-lg font-black text-sm sm:text-base bg-[var(--brand-espresso)] text-[var(--brand-gold)] shadow-sm">
                Table {order.table?.table_number || "—"}
              </span>
            )}
          </div>
        </div>

        {/* Urgency Indicator / Elapsed Time */}
        <div
          className={`flex items-center justify-between px-3 py-1.5 rounded-lg text-xs font-bold ${
            urgency.isUrgent
              ? "bg-red-100 text-red-800 dark:bg-red-950/80 dark:text-red-200 border border-red-300 dark:border-red-800 animate-pulse"
              : urgency.isWarning
              ? "bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-200 border border-amber-300 dark:border-amber-800"
              : "bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)]"
          }`}
        >
          <div className="flex items-center gap-1.5">
            <Clock className="w-3.5 h-3.5" />
            <span>Elapsed: {urgency.text}</span>
          </div>
          {urgency.isUrgent && (
            <span className="flex items-center gap-1 font-black uppercase text-[10px] tracking-wider text-red-600 dark:text-red-400">
              <AlertTriangle className="w-3 h-3" /> Overdue (&gt;15m)
            </span>
          )}
        </div>

        {/* Items List (Clear & Large for Kitchen Display) */}
        <div className="space-y-2 pt-1">
          <p className="text-[11px] font-black uppercase tracking-wider text-[var(--color-text-secondary)]">
            Kitchen Ticket Items ({order.items?.length || 0})
          </p>
          <ul className="space-y-2 max-h-56 overflow-y-auto pr-1">
            {order.items?.map((item) => (
              <li
                key={item.id}
                className="p-2 rounded-lg bg-[var(--color-surface-secondary)]/50 border border-[var(--color-border)]/70 text-xs sm:text-sm"
              >
                <div className="flex items-baseline gap-2">
                  <span className="font-black text-base text-[var(--color-accent)] min-w-[28px]">
                    {item.quantity}×
                  </span>
                  <span className="font-extrabold text-[var(--color-text)] flex-1 leading-tight">
                    {item.item_name}
                  </span>
                </div>

                {/* Special Instructions (Yellow / Amber highlight) */}
                {item.special_instructions && (
                  <div className="mt-1.5 px-2.5 py-1 rounded bg-amber-100 dark:bg-amber-950 text-amber-900 dark:text-amber-200 text-xs font-bold border border-amber-300 dark:border-amber-800 flex items-start gap-1.5">
                    <AlertCircle className="w-3.5 h-3.5 shrink-0 mt-0.5 text-amber-600" />
                    <span>INSTRUCTION: {item.special_instructions}</span>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </div>

        {/* Order-level customer notes */}
        {order.customer_notes && (
          <div className="p-2.5 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-900 dark:text-amber-200 text-xs font-bold border border-amber-200 dark:border-amber-800">
            <span className="block uppercase text-[10px] tracking-wider text-amber-700 dark:text-amber-300 mb-0.5">
              Guest Ticket Note:
            </span>
            {order.customer_notes}
          </div>
        )}
      </div>

      {/* Action Button: Minimum 44px touch target */}
      <div className="p-3 bg-[var(--color-surface-secondary)]/60 border-t border-[var(--color-border)]">
        {isPassive ? (
          <div className="flex items-center justify-center gap-2 min-h-[44px] px-4 py-2 rounded-lg font-bold text-xs uppercase tracking-wider text-emerald-800 dark:text-emerald-300 bg-emerald-100/80 dark:bg-emerald-950/50 border border-emerald-300 dark:border-emerald-800">
            <ActionIcon className="w-4 h-4 animate-bounce" />
            <span>{actionLabel}</span>
          </div>
        ) : (
          <button
            type="button"
            onClick={onAction}
            disabled={isUpdating}
            className={`w-full min-h-[44px] px-4 py-2.5 rounded-lg font-extrabold text-sm flex items-center justify-center gap-2 transition-all disabled:opacity-50 shadow-md ${actionColor}`}
          >
            <ActionIcon className="w-4 h-4" />
            <span>{isUpdating ? "Updating Ticket..." : actionLabel}</span>
          </button>
        )}
      </div>
    </div>
  );
}
