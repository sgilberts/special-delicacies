import { useState, useEffect, useCallback } from "react";
import { Link } from "react-router-dom";
import {
  ShoppingBag,
  TrendingUp,
  Clock,
  Truck,
  Users,
  UtensilsCrossed,
  Table2,
  AlertCircle,
  ArrowRight,
  RefreshCw,
  DollarSign,
  ClipboardList,
} from "lucide-react";
import { cachedGet } from "@/lib/api";
import type { AdminDashboardStats, ApiResponse } from "@/types/api";
import { useAuth } from "@/app/providers/useAuth";
import { hasAnyPermission } from "@/lib/permissionAccess";

type PeriodOption = "today" | "this_week" | "this_month" | "all_time";

export default function AdminDashboardPage() {
  const { user } = useAuth();
  const userPermissions = user?.permissions ?? [];
  const canViewStaff = hasAnyPermission(userPermissions, ["staff.view", "staff.manage"]);
  const canManageRoles = hasAnyPermission(userPermissions, ["roles.manage"]);
  const [stats, setStats] = useState<AdminDashboardStats | null>(null);
  const [period, setPeriod] = useState<PeriodOption>("today");
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    document.title = "Administration Dashboard | Special Delicacies";
  }, []);

  const fetchStats = useCallback(
    async (showRefreshIndicator = false) => {
      if (showRefreshIndicator) {
        setRefreshing(true);
      } else {
        setLoading(true);
      }
      setError(null);

      try {
        const response = await cachedGet<ApiResponse<AdminDashboardStats>>(`/admin/dashboard/stats?period=${period}`, 10_000);
        if (response.data.success && response.data.data) {
          setStats(response.data.data);
        } else {
          setError("Failed to load dashboard operational statistics.");
        }
      } catch (err: unknown) {
        const message =
          err instanceof Error
            ? err.message
            : "An unexpected error occurred while loading dashboard metrics.";
        setError(message);
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [period]
  );

  useEffect(() => {
    fetchStats();
  }, [fetchStats]);

  const formatCurrency = (val: string | number) => {
    const num = typeof val === "string" ? parseFloat(val) : val;
    return isNaN(num) ? "GH₵ 0.00" : `GH₵ ${num.toFixed(2)}`;
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case "pending":
        return "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300";
      case "confirmed":
        return "bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300";
      case "preparing":
        return "bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300";
      case "ready":
        return "bg-indigo-100 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300";
      case "out_for_delivery":
        return "bg-cyan-100 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300";
      case "delivered":
        return "bg-teal-100 text-teal-800 dark:bg-teal-950/60 dark:text-teal-300";
      case "completed":
        return "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300";
      case "cancelled":
        return "bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300";
      default:
        return "bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300";
    }
  };

  return (
    <div className="space-y-6">
      {/* Top Header & Filter Controls */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-[var(--color-border)]">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Administration Dashboard
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1">
            Central operational overview and restaurant metrics
          </p>
        </div>

        <div className="flex items-center gap-2">
          {/* Period selector */}
          <div className="flex items-center bg-[var(--color-surface)] border border-[var(--color-border)] rounded-lg p-1 shadow-xs">
            {(
              [
                { id: "today", label: "Today" },
                { id: "this_week", label: "This Week" },
                { id: "this_month", label: "This Month" },
                { id: "all_time", label: "All Time" },
              ] as const
            ).map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => setPeriod(item.id)}
                className={`px-3 py-1.5 text-xs font-semibold rounded-md transition-colors ${
                  period === item.id
                    ? "bg-[var(--brand-copper)] text-white shadow-xs"
                    : "text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
                }`}
              >
                {item.label}
              </button>
            ))}
          </div>

          <button
            type="button"
            onClick={() => fetchStats(true)}
            disabled={loading || refreshing}
            className="p-2 border border-[var(--color-border)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] rounded-lg transition-colors"
            title="Refresh statistics"
          >
            <RefreshCw
              size={18}
              className={refreshing ? "animate-spin text-[var(--brand-copper)]" : ""}
            />
          </button>
        </div>
      </div>

      {/* Error state */}
      {error && (
        <div className="p-4 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/50 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <AlertCircle className="text-rose-600 dark:text-rose-400 shrink-0" size={20} />
            <p className="text-sm text-rose-700 dark:text-rose-300">{error}</p>
          </div>
          <button
            type="button"
            onClick={() => fetchStats()}
            className="px-3 py-1.5 text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white rounded-md transition-colors"
          >
            Retry
          </button>
        </div>
      )}

      {/* Loading state skeleton */}
      {loading && !stats && (
        <div className="space-y-6 animate-pulse">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {[1, 2, 3, 4].map((i) => (
              <div
                key={i}
                className="h-28 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl"
              />
            ))}
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
            {[1, 2, 3, 4, 5, 6, 7, 8].map((i) => (
              <div
                key={i}
                className="h-20 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-xl"
              />
            ))}
          </div>
          <div className="h-64 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl" />
        </div>
      )}

      {/* Content */}
      {stats && (
        <div className="space-y-6">
          {/* 1. Core Financial and High-Level KPI Cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {/* Total Sales */}
            <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs relative overflow-hidden flex flex-col justify-between">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">
                  Total Sales
                </span>
                <div className="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                  <TrendingUp size={20} />
                </div>
              </div>
              <div className="mt-3">
                <div className="text-2xl sm:text-3xl font-black text-[var(--color-text)]">
                  {stats.financials ? formatCurrency(stats.financials.total_sales) : "Restricted"}
                </div>
                <p className="text-xs text-[var(--color-text-secondary)] mt-1 flex items-center gap-1">
                  <span>{stats.financials ? "From completed transactions" : "Financial access is not assigned to your role."}</span>
                </p>
              </div>
            </div>

            {/* Total Orders */}
            <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs relative overflow-hidden flex flex-col justify-between">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">
                  Total Orders
                </span>
                <div className="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                  <ShoppingBag size={20} />
                </div>
              </div>
              <div className="mt-3">
                <div className="text-2xl sm:text-3xl font-black text-[var(--color-text)]">
                  {stats.orders.total}
                </div>
                <div className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)] mt-1">
                  <span>{stats.breakdown.dine_in.total} Dine-in</span>
                  <span>•</span>
                  <span>{stats.breakdown.delivery.total} Delivery</span>
                </div>
              </div>
            </div>

            {/* Cash Awaiting Confirmation */}
            <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs relative overflow-hidden flex flex-col justify-between">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">
                  Cash Awaiting Confirmation
                </span>
                <div className="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                  <DollarSign size={20} />
                </div>
              </div>
              <div className="mt-3">
                <div className="text-2xl sm:text-3xl font-black text-amber-600 dark:text-amber-400">
                  {stats.financials ? formatCurrency(stats.financials.cash_awaiting_confirmation_amount) : "Restricted"}
                </div>
                <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                  {stats.financials ? `${stats.financials.cash_awaiting_confirmation_count} orders delivered, awaiting cash receipt` : "Financial access is not assigned to your role."}
                </p>
              </div>
            </div>

            {/* Active Staff & Operational Capacity */}
            <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs relative overflow-hidden flex flex-col justify-between">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">
                  Active Staff
                </span>
                <div className="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                  <Users size={20} />
                </div>
              </div>
              <div className="mt-3">
                <div className="text-2xl sm:text-3xl font-black text-[var(--color-text)]">
                  {stats.resources.active_staff}{" "}
                  <span className="text-sm font-normal text-[var(--color-text-secondary)]">
                    / {stats.resources.total_staff} total
                  </span>
                </div>
                <div className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)] mt-1">
                  <span>{stats.resources.active_tables} tables</span>
                  <span>•</span>
                  <span>{stats.resources.active_menu_items} dishes available</span>
                </div>
              </div>
            </div>
          </div>

          {/* 2. Operational Order Pipeline (State Machine Overview) */}
          <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
                <ClipboardList size={18} className="text-[var(--brand-copper)]" />
                Live Order Pipeline
              </h2>
              <Link
                to="/admin/orders"
                className="text-xs font-semibold text-[var(--brand-copper)] hover:underline flex items-center gap-1"
              >
                View all orders <ArrowRight size={14} />
              </Link>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
              {[
                { label: "Pending", count: stats.orders.pending, color: "text-amber-600 dark:text-amber-400" },
                { label: "Confirmed", count: stats.orders.confirmed, color: "text-blue-600 dark:text-blue-400" },
                { label: "Preparing", count: stats.orders.preparing, color: "text-purple-600 dark:text-purple-400" },
                { label: "Ready", count: stats.orders.ready, color: "text-indigo-600 dark:text-indigo-400" },
                { label: "On Route", count: stats.orders.out_for_delivery, color: "text-cyan-600 dark:text-cyan-400" },
                { label: "Delivered", count: stats.orders.delivered, color: "text-teal-600 dark:text-teal-400" },
                { label: "Completed", count: stats.orders.completed, color: "text-emerald-600 dark:text-emerald-400" },
                { label: "Cancelled", count: stats.orders.cancelled, color: "text-rose-600 dark:text-rose-400" },
              ].map((pipe) => (
                <div
                  key={pipe.label}
                  className="p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-center flex flex-col justify-between"
                >
                  <span className="text-xs text-[var(--color-text-secondary)] font-medium">
                    {pipe.label}
                  </span>
                  <span className={`text-xl font-extrabold mt-1 ${pipe.color}`}>
                    {pipe.count}
                  </span>
                </div>
              ))}
            </div>
          </div>

          {/* 3. Resource Overview & Quick Actions */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Restaurant Resources Summary */}
            <div className="p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs space-y-4">
              <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
                <UtensilsCrossed size={18} className="text-[var(--brand-gold)]" />
                Restaurant Operational Assets
              </h2>

              <div className="space-y-3">
                <div className="p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-lg bg-[var(--brand-copper)]/10 text-[var(--brand-copper)] flex items-center justify-center">
                      <UtensilsCrossed size={18} />
                    </div>
                    <div>
                      <p className="text-sm font-semibold text-[var(--color-text)]">Active Menu Items</p>
                      <p className="text-xs text-[var(--color-text-secondary)]">Dishes currently available</p>
                    </div>
                  </div>
                  <div className="text-right">
                    <span className="text-base font-bold text-[var(--color-text)]">
                      {stats.resources.active_menu_items}
                    </span>
                    <span className="text-xs text-[var(--color-text-secondary)] block">
                      / {stats.resources.total_menu_items} total
                    </span>
                  </div>
                </div>

                <div className="p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-lg bg-[var(--brand-gold)]/10 text-[var(--brand-gold)] flex items-center justify-center">
                      <Table2 size={18} />
                    </div>
                    <div>
                      <p className="text-sm font-semibold text-[var(--color-text)]">Dining Tables</p>
                      <p className="text-xs text-[var(--color-text-secondary)]">Active dine-in tables</p>
                    </div>
                  </div>
                  <div className="text-right">
                    <span className="text-base font-bold text-[var(--color-text)]">
                      {stats.resources.active_tables}
                    </span>
                    <span className="text-xs text-[var(--color-text-secondary)] block">
                      / {stats.resources.total_tables} total
                    </span>
                  </div>
                </div>

                <div className="p-3 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-lg bg-[var(--brand-sage)]/10 text-[var(--brand-sage)] flex items-center justify-center">
                      <Truck size={18} />
                    </div>
                    <div>
                      <p className="text-sm font-semibold text-[var(--color-text)]">Delivery Activity</p>
                      <p className="text-xs text-[var(--color-text-secondary)]">Active in delivery workflow</p>
                    </div>
                  </div>
                  <div className="text-right">
                    <span className="text-base font-bold text-[var(--color-text)]">
                      {stats.breakdown.delivery.active}
                    </span>
                    <span className="text-xs text-[var(--color-text-secondary)] block">
                      / {stats.breakdown.delivery.total} total
                    </span>
                  </div>
                </div>
              </div>

              {/* Quick links */}
              <div className="pt-2 flex flex-wrap gap-2">
                {canViewStaff && (
                  <Link
                    to="/admin/staff"
                    className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-[var(--color-surface-secondary)] hover:bg-[var(--color-border)] text-[var(--color-text)] transition-colors"
                  >
                    Manage Staff
                  </Link>
                )}
                {canManageRoles && (
                  <Link
                    to="/admin/roles"
                    className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-[var(--color-surface-secondary)] hover:bg-[var(--color-border)] text-[var(--color-text)] transition-colors"
                  >
                    Roles & Permissions
                  </Link>
                )}
                <Link
                  to="/admin/settings"
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-[var(--color-surface-secondary)] hover:bg-[var(--color-border)] text-[var(--color-text)] transition-colors"
                >
                  Restaurant Settings
                </Link>
                <Link
                  to="/admin/content"
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-[var(--color-surface-secondary)] hover:bg-[var(--color-border)] text-[var(--color-text)] transition-colors"
                >
                  Website Content
                </Link>
                <Link
                  to="/admin/audit-logs"
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-[var(--color-surface-secondary)] hover:bg-[var(--color-border)] text-[var(--color-text)] transition-colors"
                >
                  Audit Trail
                </Link>
              </div>
            </div>

            {/* Recent Orders List */}
            <div className="lg:col-span-2 p-5 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs flex flex-col justify-between">
              <div>
                <div className="flex items-center justify-between mb-4">
                  <h2 className="text-base font-bold text-[var(--color-text)] flex items-center gap-2">
                    <Clock size={18} className="text-[var(--brand-copper)]" />
                    Recent Operational Orders
                  </h2>
                  <Link
                    to="/admin/orders"
                    className="text-xs font-semibold text-[var(--brand-copper)] hover:underline flex items-center gap-1"
                  >
                    View All <ArrowRight size={14} />
                  </Link>
                </div>

                {stats.recent_orders.length === 0 ? (
                  <div className="py-12 text-center text-sm text-[var(--color-text-secondary)]">
                    No orders recorded for this period.
                  </div>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                      <thead>
                        <tr className="border-b border-[var(--color-border)] text-[var(--color-text-secondary)]">
                          <th className="pb-2 font-semibold">Order</th>
                          <th className="pb-2 font-semibold">Type</th>
                          <th className="pb-2 font-semibold">Customer / Location</th>
                          <th className="pb-2 font-semibold">Total</th>
                          <th className="pb-2 font-semibold">Status</th>
                          <th className="pb-2 font-semibold">Payment</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-[var(--color-border)]">
                        {stats.recent_orders.map((ord) => (
                          <tr key={ord.id} className="hover:bg-[var(--color-surface-secondary)]/50">
                            <td className="py-2.5 font-mono font-bold text-[var(--color-text)]">
                              <Link
                                to={`/admin/orders?search=${ord.order_number}`}
                                className="hover:underline text-[var(--brand-copper)]"
                              >
                                #{ord.order_number}
                              </Link>
                            </td>
                            <td className="py-2.5 text-[var(--color-text-secondary)] capitalize">
                              {ord.order_type === "dine_in" ? "Dine-in" : ord.order_type === "pickup" ? "Customer pickup" : "Delivery"}
                            </td>
                            <td className="py-2.5 text-[var(--color-text)]">
                              <span className="font-semibold block">{ord.customer_name}</span>
                              <span className="text-[10px] text-[var(--color-text-secondary)]">
                                {ord.table?.table_number
                                  ? `Table ${ord.table.table_number}`
                                  : ord.delivery_zone?.name ?? "Delivery"}
                              </span>
                            </td>
                            <td className="py-2.5 font-semibold text-[var(--color-text)]">
                              {formatCurrency(ord.total)}
                            </td>
                            <td className="py-2.5">
                              <span
                                className={`px-2 py-0.5 rounded-full font-semibold text-[10px] uppercase tracking-wider ${getStatusBadge(
                                  ord.status
                                )}`}
                              >
                                {ord.status.replace(/_/g, " ")}
                              </span>
                            </td>
                            <td className="py-2.5">
                              <span
                                className={`px-2 py-0.5 rounded-full font-semibold text-[10px] ${
                                  ord.payment_status === "paid"
                                    ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                                    : "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                                }`}
                              >
                                {ord.payment_status === "paid" ? `Paid${ord.payment_details?.gateway_name ? ` via ${ord.payment_details.gateway_name}` : ""}` : ord.payment_method === "gateway" ? "Gateway Pending" : "Cash Pending"}
                              </span>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
