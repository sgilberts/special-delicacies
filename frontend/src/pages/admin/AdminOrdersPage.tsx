import { useState, useEffect, useCallback } from "react";
import {
  ShoppingBag,
  Search,
  DollarSign,
  CheckCircle,
  Truck,
  Table2,
  RefreshCw,
  AlertCircle,
  X,
  Eye,
  Calendar,
} from "lucide-react";
import api, { cachedGet, invalidateCachedGets } from "@/lib/api";
import echo from "@/lib/echo";
import { useAuth } from "@/app/providers/useAuth";
import type { PlacedOrder, PaginatedResponse, ApiResponse } from "@/types/api";
import { useConfirm } from "@/components/ui/ConfirmDialog";

export default function AdminOrdersPage() {
  const { user } = useAuth();
  const canViewFinancialData = user?.roles.includes("super-admin") || user?.permissions.includes("financials.transactions.view") || false;
  const confirmDialog = useConfirm();
  const [orders, setOrders] = useState<PlacedOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Filters
  const [search, setSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [paymentFilter, setPaymentFilter] = useState("");
  const [awaitingPayment, setAwaitingPayment] = useState(false);
  const [fromDate, setFromDate] = useState("");
  const [toDate, setToDate] = useState("");
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalCount, setTotalCount] = useState(0);

  // Inspector modal
  const [selectedOrder, setSelectedOrder] = useState<PlacedOrder | null>(null);
  const [orderDetailLoading, setOrderDetailLoading] = useState(false);
  const [orderActionLoading, setOrderActionLoading] = useState(false);

  useEffect(() => {
    document.title = "Order Oversight | Special Delicacies Admin";
  }, []);

  const fetchOrders = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (search) params.append("search", search);
      if (typeFilter) params.append("order_type", typeFilter);
      if (statusFilter) params.append("status", statusFilter);
      if (paymentFilter) params.append("payment_status", paymentFilter);
      if (awaitingPayment) params.append("awaiting_payment", "1");
      if (fromDate) params.append("from_date", fromDate);
      if (toDate) params.append("to_date", toDate);
      params.append("page", page.toString());
      params.append("per_page", "20");

      const response = await cachedGet<PaginatedResponse<PlacedOrder>>(`/admin/orders?${params.toString()}`, 5_000);
      if (response.data.success) {
        setOrders(response.data.data);
        setTotalPages(response.data.meta.last_page);
        setTotalCount(response.data.meta.total);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to load orders.";
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, [search, typeFilter, statusFilter, paymentFilter, awaitingPayment, fromDate, toDate, page]);

  useEffect(() => {
    fetchOrders();
  }, [fetchOrders]);

  const fetchOrderDetail = useCallback(async (orderId: number) => {
    const response = await api.get<ApiResponse<PlacedOrder>>(`/admin/orders/${orderId}`);
    return response.data.data;
  }, []);

  useEffect(() => {
    if (!user?.permissions.includes("orders.view")) return;
    const channel = echo.private("orders");
    channel.listen(".order.payment.updated", (payload: { order_id?: number }) => {
      invalidateCachedGets();
      void fetchOrders();
      if (payload.order_id && selectedOrder?.id === payload.order_id) {
        void fetchOrderDetail(payload.order_id).then((detail) => {
          setSelectedOrder((current) => current?.id === detail.id ? detail : current);
        }).catch(() => undefined);
      }
    });
    return () => { channel.stopListening(".order.payment.updated"); echo.leave("orders"); };
  }, [user?.id, user?.permissions, selectedOrder?.id, fetchOrders, fetchOrderDetail]);

  const inspectOrder = async (orderId: number) => {
    setOrderDetailLoading(true);
    try {
      setSelectedOrder(await fetchOrderDetail(orderId));
    } catch {
      setError("Failed to load order details.");
    } finally {
      setOrderDetailLoading(false);
    }
  };

  const handleConfirmPayment = async (orderId: number) => {
    if (!await confirmDialog({ title: "Confirm cash payment?", message: "Confirm that cash payment was collected for this order?", confirmLabel: "Confirm payment" })) {
      return;
    }

    setOrderActionLoading(true);
    try {
      const res = await api.post<ApiResponse<PlacedOrder>>(`/admin/orders/${orderId}/confirm-payment`, {
        note: "Cash payment confirmed by Super Admin.",
      });

      if (res.data.success) {
        setSuccessMessage(`Payment for order #${res.data.data.order_number} confirmed successfully.`);
        setSelectedOrder(res.data.data);
        fetchOrders();
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to confirm payment.");
    } finally {
      setOrderActionLoading(false);
    }
  };

  const handleUpdateStatus = async (orderId: number, newStatus: string) => {
    setOrderActionLoading(true);
    try {
      const res = await api.patch<ApiResponse<PlacedOrder>>(`/admin/orders/${orderId}/status`, {
        status: newStatus,
        note: `Status transitioned to ${newStatus} by Super Admin.`,
      });

      if (res.data.success) {
        setSuccessMessage(`Order #${res.data.data.order_number} transitioned to ${newStatus}.`);
        setSelectedOrder(res.data.data);
        fetchOrders();
      }
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to update order status.");
    } finally {
      setOrderActionLoading(false);
    }
  };

  const formatCurrency = (val: string | number | null | undefined) => {
    if (val === null || val === undefined || val === "") return "Restricted";
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

  // Next valid transitions based on order state machine
  const getNextTransitions = (order: PlacedOrder): string[] => {
    const type = order.order_type;
    const status = order.status;

    if (type === "dine_in") {
      switch (status) {
        case "pending":
          return ["confirmed", "cancelled"];
        case "confirmed":
          return ["preparing", "cancelled"];
        case "preparing":
          return ["ready"];
        case "ready":
          return ["delivered"];
        case "delivered":
          return ["completed"];
        default:
          return [];
      }
    } else {
      switch (status) {
        case "pending":
          return ["confirmed", "cancelled"];
        case "confirmed":
          return ["preparing", "cancelled"];
        case "preparing":
          return ["ready"];
        case "ready":
          return ["out_for_delivery"];
        case "out_for_delivery":
          return ["delivered"];
        case "delivered":
          return ["completed"];
        default:
          return [];
      }
    }
  };

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-[var(--color-border)]">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Administrative Order Oversight
          </h1>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1">
            Supervise all dine-in and delivery orders, cash collections, and lifecycle transitions
          </p>
        </div>

        <div className="flex items-center gap-2 self-start sm:self-auto">
          <span className="text-xs font-semibold px-3 py-1.5 rounded-lg bg-[var(--color-surface)] border border-[var(--color-border)] text-[var(--color-text)]">
            Total: {totalCount} orders
          </span>
          <button
            type="button"
            onClick={() => fetchOrders()}
            className="p-2 border border-[var(--color-border)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)] hover:text-[var(--color-text)] rounded-xl transition-colors"
            title="Refresh orders"
          >
            <RefreshCw size={16} />
          </button>
        </div>
      </div>

      {/* Notifications */}
      {successMessage && (
        <div className="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/50 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <CheckCircle className="text-emerald-600 dark:text-emerald-400 shrink-0" size={20} />
            <p className="text-sm text-emerald-800 dark:text-emerald-300 font-medium">
              {successMessage}
            </p>
          </div>
          <button
            type="button"
            onClick={() => setSuccessMessage(null)}
            className="text-emerald-600 hover:text-emerald-800"
          >
            <X size={18} />
          </button>
        </div>
      )}

      {error && (
        <div className="p-4 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/50 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <AlertCircle className="text-rose-600 dark:text-rose-400 shrink-0" size={20} />
            <p className="text-sm text-rose-700 dark:text-rose-300">{error}</p>
          </div>
          <button
            type="button"
            onClick={() => setError(null)}
            className="text-rose-600 hover:text-rose-800"
          >
            <X size={18} />
          </button>
        </div>
      )}

      {/* Filter and Search Bar */}
      <div className="p-4 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs space-y-3">
        <div className="flex flex-col md:flex-row gap-3">
          <div className="relative flex-1">
            <Search
              size={18}
              className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]"
            />
            <input
              type="text"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              placeholder="Search by order #, customer name, phone, or table..."
              className="w-full pl-10 pr-4 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-sm text-[var(--color-text)] placeholder-[var(--color-text-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {/* Order type */}
            <select
              value={typeFilter}
              onChange={(e) => {
                setTypeFilter(e.target.value);
                setPage(1);
              }}
              className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            >
              <option value="">All Types</option>
              <option value="dine_in">Dine-in Only</option>
              <option value="delivery">Delivery Only</option>
            </select>

            {/* Status */}
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            >
              <option value="">All Statuses</option>
              <option value="pending">Pending</option>
              <option value="confirmed">Confirmed</option>
              <option value="preparing">Preparing</option>
              <option value="ready">Ready</option>
              <option value="out_for_delivery">Out for Delivery</option>
              <option value="delivered">Delivered</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>

            {/* Payment */}
            <select
              value={paymentFilter}
              onChange={(e) => {
                setPaymentFilter(e.target.value);
                setPage(1);
              }}
              className="px-3 py-2 bg-[var(--color-background)] border border-[var(--color-border)] rounded-xl text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            >
              <option value="">All Payments</option>
              <option value="paid">Paid</option>
              <option value="pending">Pending Cash</option>
            </select>
          </div>
        </div>

        {/* Secondary Filter Row: Date Range & Awaiting Payment Quick Filter */}
        <div className="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-[var(--color-border)]/60 text-xs">
          <div className="flex items-center gap-2">
            <span className="text-[var(--color-text-secondary)] font-semibold flex items-center gap-1">
              <Calendar size={14} /> Date Range:
            </span>
            <input
              type="date"
              value={fromDate}
              onChange={(e) => {
                setFromDate(e.target.value);
                setPage(1);
              }}
              className="px-2.5 py-1.5 bg-[var(--color-background)] border border-[var(--color-border)] rounded-lg text-xs text-[var(--color-text)]"
            />
            <span className="text-[var(--color-text-secondary)]">to</span>
            <input
              type="date"
              value={toDate}
              onChange={(e) => {
                setToDate(e.target.value);
                setPage(1);
              }}
              className="px-2.5 py-1.5 bg-[var(--color-background)] border border-[var(--color-border)] rounded-lg text-xs text-[var(--color-text)]"
            />
            {(fromDate || toDate) && (
              <button
                type="button"
                onClick={() => {
                  setFromDate("");
                  setToDate("");
                  setPage(1);
                }}
                className="text-xs text-[var(--brand-copper)] hover:underline ml-1"
              >
                Clear
              </button>
            )}
          </div>

          <label className="flex items-center gap-2 cursor-pointer select-none">
            <input
              type="checkbox"
              checked={awaitingPayment}
              onChange={(e) => {
                setAwaitingPayment(e.target.checked);
                setPage(1);
              }}
              className="w-4 h-4 rounded text-[var(--brand-copper)] focus:ring-[var(--brand-copper)]"
            />
            <span className="font-semibold text-amber-700 dark:text-amber-400">
              Only show delivered orders awaiting cash confirmation
            </span>
          </label>
        </div>
      </div>

      {/* Orders Table */}
      <div className="rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3">
            <RefreshCw size={24} className="animate-spin mx-auto text-[var(--brand-copper)]" />
            <p className="text-sm font-medium">Loading operational orders...</p>
          </div>
        ) : orders.length === 0 ? (
          <div className="p-12 text-center text-[var(--color-text-secondary)] space-y-3">
            <ShoppingBag size={32} className="mx-auto text-[var(--color-text-secondary)]/50" />
            <p className="text-base font-semibold text-[var(--color-text)]">No orders found</p>
            <p className="text-xs">Adjust your search or status filters to display records.</p>
          </div>
        ) : (
          <>
            {/* Desktop Table View */}
            <div className="hidden md:block overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-[var(--color-border)] text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)] bg-[var(--color-background)]/50">
                    <th className="py-3 px-5">Order #</th>
                    <th className="py-3 px-5">Type & Customer</th>
                    <th className="py-3 px-5">Placed At</th>
                    <th className="py-3 px-5">Status</th>
                    <th className="py-3 px-5">Total</th>
                    <th className="py-3 px-5">Payment</th>
                    <th className="py-3 px-5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[var(--color-border)]">
                  {orders.map((ord) => (
                    <tr key={ord.id} className="hover:bg-[var(--color-surface-secondary)]/40 transition-colors">
                      <td className="py-3.5 px-5 font-mono font-bold text-[var(--color-text)]">
                        #{ord.order_number}
                      </td>
                      <td className="py-3.5 px-5">
                        <div className="flex items-center gap-2">
                          <span
                            className={`p-1 rounded-md text-xs ${
                              ord.order_type === "dine_in"
                                ? "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                                : "bg-cyan-100 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300"
                            }`}
                          >
                            {ord.order_type === "dine_in" ? <Table2 size={14} /> : <Truck size={14} />}
                          </span>
                          <div>
                            <p className="font-semibold text-xs text-[var(--color-text)]">{ord.customer_name}</p>
                            <p className="text-[11px] text-[var(--color-text-secondary)]">
                              {ord.table?.table_number
                                ? `Table ${ord.table.table_number}`
                                : ord.delivery_zone?.name ?? "Delivery"}
                            </p>
                          </div>
                        </div>
                      </td>
                      <td className="py-3.5 px-5 text-xs text-[var(--color-text-secondary)]">
                        {new Date(ord.created_at).toLocaleString()}
                      </td>
                      <td className="py-3.5 px-5">
                        <span
                          className={`inline-flex px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wider ${getStatusBadge(
                            ord.status
                          )}`}
                        >
                          {ord.status.replace(/_/g, " ")}
                        </span>
                      </td>
                      <td className="py-3.5 px-5 font-bold text-[var(--color-text)]">
                        {canViewFinancialData ? formatCurrency(ord.total) : "Restricted"}
                      </td>
                      <td className="py-3.5 px-5">
                        <span
                          className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold ${
                            ord.payment_status === "paid"
                              ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                              : "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                          }`}
                        >
                          <DollarSign size={12} />
                          {ord.payment_status === "paid" ? `Paid${ord.payment_details?.gateway_name ? ` via ${ord.payment_details.gateway_name}` : ""}` : ord.payment_method === "gateway" ? "Gateway Pending" : "Cash Pending"}
                        </span>
                      </td>
                      <td className="py-3.5 px-5 text-right">
                        <button
                          type="button"
                          onClick={() => inspectOrder(ord.id)}
                          className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)] text-xs font-semibold text-[var(--color-text)] transition-colors"
                        >
                          <Eye size={14} /> Inspect
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Mobile Card View */}
            <div className="block md:hidden divide-y divide-[var(--color-border)]">
              {orders.map((ord) => (
                <div key={ord.id} className="p-4 space-y-3">
                  <div className="flex items-center justify-between">
                    <span className="font-mono font-bold text-sm text-[var(--color-text)]">
                      #{ord.order_number}
                    </span>
                    <span
                      className={`px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase ${getStatusBadge(
                        ord.status
                      )}`}
                    >
                      {ord.status.replace(/_/g, " ")}
                    </span>
                  </div>

                  <div className="flex items-center justify-between text-xs">
                    <div>
                      <p className="font-semibold text-[var(--color-text)]">{ord.customer_name}</p>
                      <p className="text-[var(--color-text-secondary)]">
                        {ord.table?.table_number
                          ? `Table ${ord.table.table_number}`
                          : ord.delivery_zone?.name ?? "Delivery"}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="font-bold text-sm text-[var(--color-text)]">{canViewFinancialData ? formatCurrency(ord.total) : "Restricted"}</p>
                      <span
                        className={`text-[10px] font-semibold ${
                          ord.payment_status === "paid"
                            ? "text-emerald-700 dark:text-emerald-400"
                            : "text-amber-700 dark:text-amber-400"
                        }`}
                      >
                        {ord.payment_status === "paid" ? `Paid${ord.payment_details?.gateway_name ? ` via ${ord.payment_details.gateway_name}` : ""}` : ord.payment_method === "gateway" ? "Gateway Pending" : "Cash Pending"}
                      </span>
                    </div>
                  </div>

                  <div className="flex items-center justify-between pt-2 border-t border-[var(--color-border)]">
                    <span className="text-[10px] text-[var(--color-text-secondary)]">
                      {new Date(ord.created_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}
                    </span>
                    <button
                      type="button"
                      onClick={() => inspectOrder(ord.id)}
                      className="px-3 py-1 rounded-lg border border-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] flex items-center gap-1"
                    >
                      <Eye size={12} /> Inspect
                    </button>
                  </div>
                </div>
              ))}
            </div>

            {/* Pagination Controls */}
            {totalPages > 1 && (
              <div className="p-4 border-t border-[var(--color-border)] flex items-center justify-between text-xs text-[var(--color-text-secondary)]">
                <span>
                  Page {page} of {totalPages} ({totalCount} total)
                </span>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] disabled:opacity-40 hover:bg-[var(--color-surface-secondary)] text-[var(--color-text)] font-semibold"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    disabled={page >= totalPages}
                    onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                    className="px-3 py-1.5 rounded-lg border border-[var(--color-border)] disabled:opacity-40 hover:bg-[var(--color-surface-secondary)] text-[var(--color-text)] font-semibold"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* ORDER INSPECTION MODAL */}
      {selectedOrder && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
          <div className="relative w-full max-w-2xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden my-8 max-h-[90vh] flex flex-col">
            {/* Detail loading overlay */}
            {orderDetailLoading && (
              <div className="absolute inset-0 z-10 bg-[var(--color-surface)]/70 flex items-center justify-center rounded-2xl">
                <RefreshCw className="w-6 h-6 animate-spin text-[var(--brand-copper)]" />
              </div>
            )}
            {/* Header */}
            <div className="p-5 border-b border-[var(--color-border)] flex items-center justify-between">
              <div>
                <div className="flex items-center gap-3">
                  <h2 className="text-xl font-mono font-bold text-[var(--color-text)]">
                    Order #{selectedOrder.order_number}
                  </h2>
                  <span
                    className={`px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase tracking-wider ${getStatusBadge(
                      selectedOrder.status
                    )}`}
                  >
                    {selectedOrder.status.replace(/_/g, " ")}
                  </span>
                </div>
                <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                  Placed on {new Date(selectedOrder.created_at).toLocaleString()}
                </p>
              </div>

              <button
                type="button"
                onClick={() => setSelectedOrder(null)}
                className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
              >
                <X size={20} />
              </button>
            </div>

            {/* Scrollable Content */}
            <div className="p-5 overflow-y-auto space-y-6 flex-1 text-xs">
              {/* Customer & Delivery Summary */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)]">
                <div>
                  <span className="text-[var(--color-text-secondary)] font-semibold block mb-1">
                    Customer Information
                  </span>
                  <p className="font-bold text-sm text-[var(--color-text)]">{selectedOrder.customer_name}</p>
                  <p className="text-[var(--color-text)] mt-0.5">{selectedOrder.customer_phone}</p>
                  {selectedOrder.customer_notes && (
                    <p className="text-[var(--color-text-secondary)] italic mt-2">
                      Note: "{selectedOrder.customer_notes}"
                    </p>
                  )}
                </div>

                <div>
                  <span className="text-[var(--color-text-secondary)] font-semibold block mb-1">
                    Order Type & Location
                  </span>
                  <p className="font-bold text-sm text-[var(--color-text)] capitalize">
                    {selectedOrder.order_type === "dine_in" ? "Dine-In" : "Home Delivery"}
                  </p>
                  {selectedOrder.table ? (
                    <p className="text-[var(--color-text)] mt-0.5">
                      Dining Table #{selectedOrder.table.table_number} (Capacity: {selectedOrder.table.capacity})
                    </p>
                  ) : (
                    <>
                      <p className="text-[var(--color-text)] mt-0.5">
                        Zone: {selectedOrder.delivery_zone?.name ?? "Standard Delivery"}
                      </p>
                      {selectedOrder.delivery_address && (
                        <p className="text-[var(--color-text-secondary)] mt-1">
                          Address: {selectedOrder.delivery_address}
                        </p>
                      )}
                    </>
                  )}
                </div>
              </div>

              {/* Items Snapshot Table */}
              <div>
                <h3 className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-2">
                  Itemized Order Snapshot
                </h3>
                <div className="rounded-xl border border-[var(--color-border)] overflow-hidden">
                  <table className="w-full text-left">
                    <thead>
                      <tr className="bg-[var(--color-background)]/80 text-[var(--color-text-secondary)] font-semibold border-b border-[var(--color-border)]">
                        <th className="py-2.5 px-3">Item</th>
                        <th className="py-2.5 px-3 text-center">Qty</th>
                        <th className="py-2.5 px-3 text-right">Unit Price</th>
                        <th className="py-2.5 px-3 text-right">Line Total</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-[var(--color-border)]">
                      {selectedOrder.items?.map((item) => (
                        <tr key={item.id}>
                          <td className="py-2.5 px-3">
                            <span className="font-semibold text-[var(--color-text)] block">
                              {item.item_name}
                            </span>
                            {item.special_instructions && (
                              <span className="text-[10px] text-[var(--color-text-secondary)] italic block">
                                Note: {item.special_instructions}
                              </span>
                            )}
                          </td>
                          <td className="py-2.5 px-3 text-center font-bold text-[var(--color-text)]">
                            {item.quantity}
                          </td>
                          <td className="py-2.5 px-3 text-right text-[var(--color-text-secondary)]">
                            {formatCurrency(item.unit_price)}
                          </td>
                          <td className="py-2.5 px-3 text-right font-semibold text-[var(--color-text)]">
                            {formatCurrency(item.line_total)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot className="bg-[var(--color-background)]/40 font-semibold border-t border-[var(--color-border)]">
                      <tr>
                        <td colSpan={3} className="py-2 px-3 text-right text-[var(--color-text-secondary)]">
                          Subtotal
                        </td>
                        <td className="py-2 px-3 text-right text-[var(--color-text)]">
                          {formatCurrency(selectedOrder.subtotal)}
                        </td>
                      </tr>
                      {parseFloat(selectedOrder.delivery_fee) > 0 && (
                        <tr>
                          <td colSpan={3} className="py-2 px-3 text-right text-[var(--color-text-secondary)]">
                            Delivery Fee
                          </td>
                          <td className="py-2 px-3 text-right text-[var(--color-text)]">
                            {formatCurrency(selectedOrder.delivery_fee)}
                          </td>
                        </tr>
                      )}
                      <tr className="border-t border-[var(--color-border)] text-sm font-bold text-[var(--brand-copper)]">
                        <td colSpan={3} className="py-2.5 px-3 text-right">
                          Grand Total
                        </td>
                        <td className="py-2.5 px-3 text-right font-black">
                          {formatCurrency(selectedOrder.total)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>

              {/* Payment Section & Confirmation */}
              <div className="p-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                  <span className="text-[var(--color-text-secondary)] font-semibold block">
                    Payment Status
                  </span>
                  <div className="flex items-center gap-2 mt-1">
                    <span
                      className={`px-2.5 py-1 rounded-full font-bold text-xs ${
                        selectedOrder.payment_status === "paid"
                          ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                          : ["failed", "cancelled"].includes((selectedOrder.payment_details?.transaction_status || "").toLowerCase())
                          ? "bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300"
                          : "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                      }`}
                    >
                      {selectedOrder.payment_status === "paid"
                        ? `Paid${selectedOrder.payment_details?.gateway_name ? ` via ${selectedOrder.payment_details.gateway_name}` : " & Settled"}`
                        : ["failed", "cancelled"].includes((selectedOrder.payment_details?.transaction_status || "").toLowerCase())
                        ? `Gateway ${selectedOrder.payment_details?.transaction_status}`
                        : selectedOrder.payment_method === "gateway" ? "Gateway Payment Pending" : "Awaiting Cash Confirmation"}
                    </span>
                    <span className="text-[var(--color-text-secondary)]">
                      {selectedOrder.payment_method === "gateway" && !canViewFinancialData
                        ? "Payment details limited"
                        : selectedOrder.payment_method === "gateway"
                        ? `Method: ${selectedOrder.payment_details?.gateway_name || "Online gateway"}${selectedOrder.payment_details?.reference ? ` · Ref ${selectedOrder.payment_details.reference}` : ""} · ${selectedOrder.payment_details?.currency || "GHS"} ${selectedOrder.payment_details?.amount || selectedOrder.total}${selectedOrder.payment_details?.verified_at ? ` · Verified ${new Date(selectedOrder.payment_details.verified_at).toLocaleString()}` : ""}`
                        : "Method: Pay on Delivery (Cash)"}
                    </span>
                  </div>
                </div>

                {((selectedOrder.payment_method !== "gateway" && selectedOrder.payment_status !== "paid") || (selectedOrder.payment_method === "gateway" && selectedOrder.order_type === "delivery" && selectedOrder.status === "delivered" && selectedOrder.payment_details?.delivery_fee_payment_method === "cash_on_delivery" && selectedOrder.payment_details.delivery_fee_payment_status === "pending")) && (
                  <button
                    type="button"
                    disabled={orderActionLoading}
                    onClick={() => handleConfirmPayment(selectedOrder.id)}
                    className="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs shadow-xs transition-colors self-start sm:self-auto disabled:opacity-50"
                  >
                    {orderActionLoading ? "Processing..." : selectedOrder.payment_method === "gateway" ? `Confirm Delivery Fee (GH₵ ${Number(selectedOrder.delivery_fee).toFixed(2)})` : "Confirm Cash Payment"}
                  </button>
                )}
              </div>

              {/* Order State Transition Actions */}
              {getNextTransitions(selectedOrder).length > 0 && (
                <div className="p-4 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] space-y-2">
                  <span className="text-[var(--color-text-secondary)] font-semibold block">
                    Permitted Lifecycle Actions (State Machine Protected)
                  </span>
                  <div className="flex flex-wrap gap-2 pt-1">
                    {getNextTransitions(selectedOrder).map((nxt) => (
                      <button
                        key={nxt}
                        type="button"
                        disabled={orderActionLoading}
                        onClick={() => handleUpdateStatus(selectedOrder.id, nxt)}
                        className={`px-3 py-1.5 rounded-lg text-xs font-semibold capitalize shadow-xs transition-colors disabled:opacity-50 ${
                          nxt === "cancelled"
                            ? "bg-rose-600 hover:bg-rose-700 text-white"
                            : "bg-[var(--brand-copper)] hover:bg-[var(--brand-copper)]/90 text-white"
                        }`}
                      >
                        Transition to {nxt.replace(/_/g, " ")}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {/* Order Status History Timeline */}
              {selectedOrder.status_histories && selectedOrder.status_histories.length > 0 && (
                <div>
                  <h3 className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-3">
                    Audit Timeline & Lifecycle History
                  </h3>
                  <div className="space-y-3 relative before:absolute before:left-3.5 before:top-2 before:bottom-2 before:w-0.5 before:bg-[var(--color-border)]">
                    {selectedOrder.status_histories.map((hist: any) => (
                      <div key={hist.id} className="relative pl-8">
                        <div className="absolute left-2.5 top-1 w-2.5 h-2.5 rounded-full bg-[var(--brand-copper)] ring-4 ring-[var(--color-surface)]" />
                        <div className="p-2.5 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)]">
                          <div className="flex items-center justify-between">
                            <span className="font-bold text-[var(--color-text)] uppercase text-[11px]">
                              {hist.new_status ? hist.new_status.replace(/_/g, " ") : "Order Initiated"}
                            </span>
                            <span className="text-[10px] text-[var(--color-text-secondary)]">
                              {new Date(hist.created_at).toLocaleTimeString([], {
                                hour: "2-digit",
                                minute: "2-digit",
                                second: "2-digit",
                              })}
                            </span>
                          </div>
                          {hist.note && (
                            <p className="text-[11px] text-[var(--color-text-secondary)] mt-1">
                              {hist.note}
                            </p>
                          )}
                          {hist.user && (
                            <span className="text-[10px] text-[var(--brand-copper)] font-medium block mt-1">
                              By {hist.user.name}
                            </span>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {/* Footer */}
            <div className="p-4 border-t border-[var(--color-border)] text-right bg-[var(--color-surface)]">
              <button
                type="button"
                onClick={() => setSelectedOrder(null)}
                className="px-4 py-2 rounded-xl bg-[var(--color-surface-secondary)] hover:bg-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] transition-colors"
              >
                Close Inspector
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
