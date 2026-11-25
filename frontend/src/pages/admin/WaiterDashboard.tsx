import { useEffect, useState, useCallback, useMemo } from "react";
import {
  ClipboardList,
  Clock,
  CheckCircle2,
  AlertCircle,
  RefreshCw,
  ChefHat,
  UtensilsCrossed,
  User,
  ArrowRight,
  Sparkles,
  AlertTriangle,
  Radio,
} from "lucide-react";
import apiClient from "@/lib/api";
import { useOrdersRealTime } from "@/hooks/useOrdersRealTime";
import { useToast } from "@/components/ui/useToast";
import type { DineInOrder } from "@/types/api";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ErrorState from "@/components/public/ErrorState";
import { useConfirm } from "@/components/ui/ConfirmDialog";

type StatusGroup = "pending" | "confirmed" | "preparing" | "ready" | "delivered" | "completed";

const STATUS_PRIORITY: StatusGroup[] = [
  "pending",
  "confirmed",
  "preparing",
  "ready",
  "delivered",
  "completed",
];

const STATUS_CONFIG: Record<
  StatusGroup,
  { label: string; badgeClass: string; bgClass: string; textClass: string; icon: React.ElementType }
> = {
  pending: {
    label: "Pending Confirmation",
    badgeClass: "bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border-amber-300 dark:border-amber-800",
    bgClass: "border-l-4 border-l-amber-500",
    textClass: "text-amber-600 dark:text-amber-400",
    icon: AlertCircle,
  },
  confirmed: {
    label: "Confirmed",
    badgeClass: "bg-blue-100 text-blue-800 dark:bg-blue-950/80 dark:text-blue-300 border-blue-300 dark:border-blue-800",
    bgClass: "border-l-4 border-l-blue-500",
    textClass: "text-blue-600 dark:text-blue-400",
    icon: CheckCircle2,
  },
  preparing: {
    label: "Preparing in Kitchen",
    badgeClass: "bg-orange-100 text-orange-800 dark:bg-orange-950/80 dark:text-orange-300 border-orange-300 dark:border-orange-800",
    bgClass: "border-l-4 border-l-orange-500",
    textClass: "text-orange-600 dark:text-orange-400",
    icon: ChefHat,
  },
  ready: {
    label: "Ready to Deliver",
    badgeClass: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border-emerald-400 dark:border-emerald-700 animate-pulse font-bold",
    bgClass: "border-l-4 border-l-emerald-500 bg-emerald-500/5 ring-1 ring-emerald-500/20",
    textClass: "text-emerald-600 dark:text-emerald-400",
    icon: Sparkles,
  },
  delivered: {
    label: "Delivered",
    badgeClass: "bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300 border-gray-300 dark:border-gray-700",
    bgClass: "border-l-4 border-l-gray-400",
    textClass: "text-gray-500 dark:text-gray-400",
    icon: UtensilsCrossed,
  },
  completed: {
    label: "Completed",
    badgeClass: "bg-muted text-muted-foreground border-border",
    bgClass: "border-l-4 border-l-muted opacity-80",
    textClass: "text-muted-foreground",
    icon: CheckCircle2,
  },
};

function getElapsedTime(dateStr?: string): string {
  if (!dateStr) return "Just now";
  const created = new Date(dateStr).getTime();
  if (isNaN(created)) return "Just now";
  const diffSec = Math.floor((Date.now() - created) / 1000);
  if (diffSec < 45) return "Just now";
  const diffMin = Math.floor(diffSec / 60);
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHours = Math.floor(diffMin / 60);
  if (diffHours < 24) return `${diffHours}h ${diffMin % 60}m ago`;
  const diffDays = Math.floor(diffHours / 24);
  return `${diffDays}d ago`;
}

export default function WaiterDashboard() {
  const confirmDialog = useConfirm();
  const { success: toastSuccess, error: toastError } = useToast();
  const [orders, setOrders] = useState<DineInOrder[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [isRefreshing, setIsRefreshing] = useState<boolean>(false);
  const [updatingId, setUpdatingId] = useState<number | null>(null);
  const [statusFilter, setStatusFilter] = useState<"all" | StatusGroup>("all");
  const [, setTick] = useState<number>(0);

  // Timer to update elapsed times every 15 seconds
  useEffect(() => {
    const timer = setInterval(() => {
      setTick((t) => t + 1);
    }, 15000);
    return () => clearInterval(timer);
  }, []);

  // Fetch orders from API
  const fetchOrders = useCallback(async (isInitial = false) => {
    if (isInitial) setLoading(true);
    else setIsRefreshing(true);
    setError(null);

    try {
      const response = await apiClient.get<{ success: boolean; data: DineInOrder[] }>(
        "/orders?order_type=dine_in&statuses=pending,confirmed,preparing,ready,delivered"
      );
      if (response.data?.success && Array.isArray(response.data.data)) {
        setOrders(response.data.data);
      } else {
        setOrders([]);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to load dine-in orders";
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

  // Polling fallback every 30 seconds
  useEffect(() => {
    const interval = setInterval(() => {
      fetchOrders(false);
    }, 30000);
    return () => clearInterval(interval);
  }, [fetchOrders]);

  // Helper to upsert incoming orders from real-time events
  const handleIncomingOrder = useCallback((incoming: DineInOrder) => {
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

  // Real-time integration
  useOrdersRealTime({
    channel: "orders.waiter",
    onOrderPlaced: (order) => {
      handleIncomingOrder(order);
      toastSuccess(`New dine-in order #${order.order_number} received!`);
    },
    onOrderConfirmed: (order) => {
      handleIncomingOrder(order);
    },
    onOrderPreparing: (order) => {
      handleIncomingOrder(order);
    },
    onOrderReady: (order) => {
      handleIncomingOrder(order);
      toastSuccess(`Order #${order.order_number} (Table ${order.table?.table_number || "—"}) is READY for delivery!`);
    },
    onOrderDelivered: (order) => {
      handleIncomingOrder(order);
    },
    onOrderCompleted: (order) => {
      handleIncomingOrder(order);
    },
    onOrderCancelled: (order) => {
      setOrders((prev) => prev.filter((o) => o.id !== order.id));
      toastError(`Order #${order.order_number} was cancelled.`);
    },
  });

  // Action button handler
  const handleUpdateStatus = async (orderId: number, newStatus: "confirmed" | "delivered" | "completed" | "cancelled") => {
    if (newStatus === "cancelled" && !await confirmDialog({ title: "Cancel this order?", message: "This will cancel the order and update the kitchen and waiter dashboards.", confirmLabel: "Cancel order", tone: "danger" })) {
      return;
    }
    setUpdatingId(orderId);
    try {
      const response = await apiClient.patch<{ success: boolean; data: DineInOrder; message?: string }>(
        `/orders/${orderId}/status`,
        { status: newStatus }
      );

      if (response.data?.success && response.data.data) {
        if (newStatus === "cancelled") {
          setOrders((prev) => prev.filter((o) => o.id !== orderId));
          toastSuccess(`Order #${response.data.data.order_number} was cancelled.`);
        } else {
          handleIncomingOrder(response.data.data);
          const actionLabel =
            newStatus === "confirmed"
              ? "confirmed and sent to kitchen"
              : newStatus === "delivered"
              ? "marked as delivered"
              : "completed";
          toastSuccess(`Order #${response.data.data.order_number} ${actionLabel}.`);
        }
      } else {
        await fetchOrders(false);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to update order status";
      toastError(msg);
    } finally {
      setUpdatingId(null);
    }
  };

  // Group orders by priority
  const groupedOrders = useMemo(() => {
    const groups: Record<StatusGroup, DineInOrder[]> = {
      pending: [],
      confirmed: [],
      preparing: [],
      ready: [],
      delivered: [],
      completed: [],
    };

    for (const order of orders) {
      const st = order.status as StatusGroup;
      if (groups[st]) {
        groups[st].push(order);
      }
    }
    return groups;
  }, [orders]);

  const readyCount = groupedOrders.ready.length;
  const pendingCount = groupedOrders.pending.length;
  const preparingCount = groupedOrders.confirmed.length + groupedOrders.preparing.length;
  const deliveredCount = groupedOrders.delivered.length;

  if (loading) {
    return <LoadingSpinner message="Loading waiter operations dashboard..." />;
  }

  if (error && orders.length === 0) {
    return <ErrorState message={error} onRetry={() => fetchOrders(true)} />;
  }

  return (
    <div className="space-y-6 max-w-7xl mx-auto pb-12">
      {/* ── Top Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-[var(--color-border)]">
        <div>
          <div className="flex items-center gap-2">
            <div
              className="w-10 h-10 rounded-xl flex items-center justify-center shadow-sm"
              style={{ background: "var(--brand-espresso)" }}
            >
              <ClipboardList className="w-5 h-5 text-[var(--brand-gold)]" />
            </div>
            <div>
              <h1 className="text-xl sm:text-2xl font-black tracking-tight text-[var(--color-text)]">
                Waiter Operations
              </h1>
              <div className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)]">
                <span className="inline-flex items-center gap-1.5 font-medium text-emerald-600 dark:text-emerald-400">
                  <Radio className="w-3.5 h-3.5 animate-pulse" />
                  Live Channel: orders.waiter
                </span>
                <span>•</span>
                <span>Auto-refresh 30s</span>
              </div>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => fetchOrders(false)}
            disabled={isRefreshing}
            className="flex items-center justify-center gap-2 px-4 py-2 min-h-[44px] rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] font-semibold text-sm transition-all disabled:opacity-50 shadow-sm"
            aria-label="Refresh orders"
          >
            <RefreshCw className={`w-4 h-4 ${isRefreshing ? "animate-spin text-[var(--color-accent)]" : ""}`} />
            <span>{isRefreshing ? "Refreshing..." : "Refresh"}</span>
          </button>
        </div>
      </div>

      {/* ── Attention Alert for Ready Orders ── */}
      {readyCount > 0 && (
        <div className="p-4 rounded-xl border border-emerald-300 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-sm">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-full bg-emerald-600 text-white flex items-center justify-center shrink-0">
              <Sparkles className="w-5 h-5 animate-spin" />
            </div>
            <div>
              <p className="font-bold text-sm sm:text-base">
                {readyCount} {readyCount === 1 ? "Order is" : "Orders are"} READY for pickup and delivery!
              </p>
              <p className="text-xs text-emerald-700 dark:text-emerald-300">
                Kitchen has completed preparation. Please deliver to respective tables immediately.
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={() => setStatusFilter("ready")}
            className="px-4 py-2 min-h-[44px] rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs uppercase tracking-wider shrink-0 transition-colors shadow-sm"
          >
            View Ready Orders ({readyCount})
          </button>
        </div>
      )}

      {/* ── Quick Status Badges / Filter Tabs ── */}
      <div className="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none">
        <button
          type="button"
          onClick={() => setStatusFilter("all")}
          className={`flex items-center gap-2 px-4 py-2 min-h-[44px] rounded-lg text-xs sm:text-sm font-bold whitespace-nowrap transition-all border ${
            statusFilter === "all"
              ? "bg-[var(--color-accent)] text-white border-transparent shadow-sm"
              : "bg-[var(--color-surface)] text-[var(--color-text)] border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]"
          }`}
        >
          All Active
          <span className="px-2 py-0.5 rounded-full text-xs bg-black/20 text-inherit font-extrabold">
            {orders.length}
          </span>
        </button>

        <button
          type="button"
          onClick={() => setStatusFilter("ready")}
          className={`flex items-center gap-2 px-4 py-2 min-h-[44px] rounded-lg text-xs sm:text-sm font-bold whitespace-nowrap transition-all border ${
            statusFilter === "ready"
              ? "bg-emerald-600 text-white border-emerald-600 shadow-sm"
              : "bg-[var(--color-surface)] text-[var(--color-text)] border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]"
          }`}
        >
          <Sparkles className="w-4 h-4 text-emerald-500" />
          Ready to Deliver
          <span className="px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200 font-extrabold">
            {readyCount}
          </span>
        </button>

        <button
          type="button"
          onClick={() => setStatusFilter("pending")}
          className={`flex items-center gap-2 px-4 py-2 min-h-[44px] rounded-lg text-xs sm:text-sm font-bold whitespace-nowrap transition-all border ${
            statusFilter === "pending"
              ? "bg-amber-600 text-white border-amber-600 shadow-sm"
              : "bg-[var(--color-surface)] text-[var(--color-text)] border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]"
          }`}
        >
          <AlertCircle className="w-4 h-4 text-amber-500" />
          Pending Confirmation
          <span className="px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200 font-extrabold">
            {pendingCount}
          </span>
        </button>

        <button
          type="button"
          onClick={() => setStatusFilter("preparing")}
          className={`flex items-center gap-2 px-4 py-2 min-h-[44px] rounded-lg text-xs sm:text-sm font-bold whitespace-nowrap transition-all border ${
            statusFilter === "preparing"
              ? "bg-orange-600 text-white border-orange-600 shadow-sm"
              : "bg-[var(--color-surface)] text-[var(--color-text)] border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]"
          }`}
        >
          <ChefHat className="w-4 h-4 text-orange-500" />
          In Kitchen
          <span className="px-2 py-0.5 rounded-full text-xs bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200 font-extrabold">
            {preparingCount}
          </span>
        </button>

        <button
          type="button"
          onClick={() => setStatusFilter("delivered")}
          className={`flex items-center gap-2 px-4 py-2 min-h-[44px] rounded-lg text-xs sm:text-sm font-bold whitespace-nowrap transition-all border ${
            statusFilter === "delivered"
              ? "bg-gray-700 text-white border-gray-700 shadow-sm"
              : "bg-[var(--color-surface)] text-[var(--color-text)] border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]"
          }`}
        >
          <UtensilsCrossed className="w-4 h-4 text-gray-500" />
          Delivered
          <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300 font-extrabold">
            {deliveredCount}
          </span>
        </button>
      </div>

      {/* ── Order Display ── */}
      {orders.length === 0 ? (
        <div className="text-center py-20 border border-dashed border-[var(--color-border)] rounded-2xl bg-[var(--color-surface)]">
          <UtensilsCrossed className="w-12 h-12 mx-auto text-[var(--color-text-secondary)] mb-3 opacity-60" />
          <h2 className="text-lg font-bold text-[var(--color-text)]">No Active Dine-in Orders</h2>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1 max-w-sm mx-auto">
            When guests scan table QR codes and submit dine-in orders, they will appear here in real-time.
          </p>
        </div>
      ) : (
        <div className="space-y-8">
          {STATUS_PRIORITY.map((statusGroup) => {
            const groupList = groupedOrders[statusGroup];
            if (groupList.length === 0) return null;
            if (statusFilter !== "all" && statusFilter !== statusGroup) return null;

            const config = STATUS_CONFIG[statusGroup];
            const GroupIcon = config.icon;

            return (
              <section key={statusGroup} className="space-y-4">
                <div className="flex items-center justify-between pb-2 border-b border-[var(--color-border)]">
                  <div className="flex items-center gap-2.5">
                    <GroupIcon className={`w-5 h-5 ${config.textClass}`} />
                    <h2 className="text-base sm:text-lg font-extrabold text-[var(--color-text)]">
                      {config.label}
                    </h2>
                    <span className="px-2.5 py-0.5 text-xs font-bold rounded-full bg-[var(--color-surface-secondary)] text-[var(--color-text)]">
                      {groupList.length}
                    </span>
                  </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {groupList.map((order) => (
                    <OrderCard
                      key={order.id}
                      order={order}
                      isUpdating={updatingId === order.id}
                      onUpdateStatus={handleUpdateStatus}
                    />
                  ))}
                </div>
              </section>
            );
          })}
        </div>
      )}
    </div>
  );
}

// ── Order Card Component ───────────────────────────────────────────────────

interface OrderCardProps {
  order: DineInOrder;
  isUpdating: boolean;
  onUpdateStatus: (orderId: number, status: "confirmed" | "delivered" | "completed" | "cancelled") => Promise<void>;
}

function OrderCard({ order, isUpdating, onUpdateStatus }: OrderCardProps) {
  const status = (order.status || "pending") as StatusGroup;
  const config = STATUS_CONFIG[status] || STATUS_CONFIG.pending;
  const StatusIcon = config.icon;
  const elapsed = getElapsedTime(order.created_at);

  return (
    <div
      className={`rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-sm hover:shadow-md transition-all flex flex-col justify-between overflow-hidden ${config.bgClass}`}
    >
      <div className="p-4 sm:p-5 space-y-3">
        {/* Card Header */}
        <div className="flex items-start justify-between gap-2">
          <div>
            <span className="text-xs font-mono font-bold text-[var(--color-accent)] block">
              {order.order_number}
            </span>
            <div className="flex items-center gap-1.5 mt-0.5">
              <span className="px-2 py-0.5 rounded font-black text-xs bg-[var(--brand-espresso)] text-[var(--brand-gold)]">
                Table {order.table?.table_number || "—"}
              </span>
              <span className="text-xs text-[var(--color-text-secondary)] flex items-center gap-1">
                <Clock className="w-3 h-3" />
                {elapsed}
              </span>
            </div>
          </div>

          <span
            className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold border ${config.badgeClass}`}
          >
            <StatusIcon className="w-3 h-3" />
            <span className="capitalize">{order.status}</span>
          </span>
        </div>

        {/* Customer info */}
        <div className="flex items-center gap-1.5 text-xs text-[var(--color-text)] font-semibold pb-2 border-b border-[var(--color-border)]">
          <User className="w-3.5 h-3.5 text-[var(--color-text-secondary)]" />
          <span className="truncate">{order.customer_name || "Guest Customer"}</span>
          {order.customer_phone && (
            <span className="text-[var(--color-text-secondary)] font-normal truncate">
              ({order.customer_phone})
            </span>
          )}
        </div>

        {/* Items List */}
        <div className="space-y-1.5 pt-1">
          <p className="text-[11px] font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
            Ordered Items ({order.items?.length || 0})
          </p>
          <div className="space-y-1 max-h-48 overflow-y-auto pr-1">
            {order.items?.map((item) => (
              <div
                key={item.id}
                className="text-xs flex flex-col py-1 border-b border-[var(--color-border)]/50 last:border-0"
              >
                <div className="flex items-center justify-between font-medium">
                  <span className="text-[var(--color-text)]">
                    <span className="font-bold text-[var(--color-accent)] mr-1.5">
                      {item.quantity}×
                    </span>
                    {item.item_name}
                  </span>
                  <span className="text-[var(--color-text-secondary)] font-mono text-[11px]">
                    GH₵ {item.line_total}
                  </span>
                </div>

                {item.special_instructions && (
                  <div className="mt-1 px-2 py-1 rounded bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200 text-[11px] font-medium border border-amber-200 dark:border-amber-800 flex items-start gap-1">
                    <AlertTriangle className="w-3 h-3 shrink-0 mt-0.5 text-amber-600" />
                    <span>Note: {item.special_instructions}</span>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        {/* Customer Notes */}
        {order.customer_notes && (
          <div className="p-2 rounded-lg bg-[var(--color-surface-secondary)] text-xs border border-[var(--color-border)]">
            <span className="font-bold text-[var(--color-text)]">Special Note: </span>
            <span className="text-[var(--color-text-secondary)]">{order.customer_notes}</span>
          </div>
        )}

        {/* Order Subtotal / Total */}
        <div className="pt-2 flex items-center justify-between border-t border-[var(--color-border)] text-xs font-bold">
          <span className="text-[var(--color-text-secondary)]">Total Bill:</span>
          <span className="text-sm font-extrabold text-[var(--color-accent)] font-mono">
            GH₵ {order.total}
          </span>
        </div>
      </div>

      {/* Action Buttons (Min height 44px for touch targets) */}
      <div className="p-3 bg-[var(--color-surface-secondary)]/60 border-t border-[var(--color-border)]">
        {status === "pending" && (
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => onUpdateStatus(order.id, "confirmed")}
              disabled={isUpdating}
              className="flex-1 min-h-[44px] px-3 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs sm:text-sm flex items-center justify-center gap-1.5 transition-colors disabled:opacity-50 shadow-sm"
            >
              <CheckCircle2 className="w-4 h-4 shrink-0" />
              <span>{isUpdating ? "Confirming..." : "Confirm & Send"}</span>
            </button>
            <button
              type="button"
              onClick={() => onUpdateStatus(order.id, "cancelled")}
              disabled={isUpdating}
              className="min-h-[44px] px-3 py-2 rounded-lg border border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 font-semibold text-xs transition-colors disabled:opacity-50"
              title="Cancel Order"
            >
              Cancel
            </button>
          </div>
        )}

        {status === "ready" && (
          <button
            type="button"
            onClick={() => onUpdateStatus(order.id, "delivered")}
            disabled={isUpdating}
            className="w-full min-h-[44px] px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm flex items-center justify-center gap-2 transition-colors disabled:opacity-50 shadow-md animate-pulse-subtle"
          >
            <UtensilsCrossed className="w-4 h-4" />
            <span>{isUpdating ? "Delivering..." : "Deliver to Table"}</span>
            <ArrowRight className="w-4 h-4" />
          </button>
        )}

        {status === "delivered" && (
          <button
            type="button"
            onClick={() => onUpdateStatus(order.id, "completed")}
            disabled={isUpdating}
            className="w-full min-h-[44px] px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm flex items-center justify-center gap-2 transition-colors disabled:opacity-50 shadow-sm"
          >
            <CheckCircle2 className="w-4 h-4" />
            <span>{isUpdating ? "Completing..." : "Complete Order"}</span>
          </button>
        )}

        {status === "confirmed" && (
          <div className="flex items-center justify-between gap-2 min-h-[44px]">
            <div className="flex items-center gap-1.5 text-xs font-semibold text-[var(--color-text-secondary)]">
              <ChefHat className="w-4 h-4 text-blue-500 animate-bounce" />
              <span>In Kitchen (Queued)</span>
            </div>
            <button
              type="button"
              onClick={() => onUpdateStatus(order.id, "cancelled")}
              disabled={isUpdating}
              className="min-h-[36px] px-2.5 py-1 rounded-lg border border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 font-semibold text-xs transition-colors disabled:opacity-50"
            >
              Cancel
            </button>
          </div>
        )}

        {status === "preparing" && (
          <div className="flex items-center justify-center gap-2 min-h-[44px] text-xs font-semibold text-[var(--color-text-secondary)] py-1">
            <ChefHat className="w-4 h-4 text-orange-500 animate-bounce" />
            <span>In Kitchen (Cooking)</span>
          </div>
        )}

        {status === "completed" && (
          <div className="flex items-center justify-center gap-2 min-h-[44px] text-xs font-semibold text-muted-foreground py-1">
            <CheckCircle2 className="w-4 h-4 text-emerald-500" />
            <span>Order Finished</span>
          </div>
        )}
      </div>
    </div>
  );
}
