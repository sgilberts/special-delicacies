import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { LoaderCircle, MapPin, Search } from "lucide-react";
import api from "@/lib/api";
import echo from "@/lib/echo";
import type { ApiResponse, PaymentDetails } from "@/types/api";

interface TrackedOrder {
  order_number: string;
  tracking_channel: string;
  order_type: string;
  status: string;
  total: string;
  payment_details: PaymentDetails;
  items: Array<{ item_name: string; quantity: number }>;
  table: { table_number: string } | null;
  delivery_zone: { name: string } | null;
  created_at: string;
  status_history: Array<{ status: string; created_at: string }>;
}

export default function OrderTrackingPage() {
  const [params] = useSearchParams();
  const [reference, setReference] = useState(params.get("order_number") || "");
  const [order, setOrder] = useState<TrackedOrder | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const track = useCallback(async (value: string) => {
    const orderNumber = value.trim();
    if (!orderNumber) return;
    setLoading(true);
    setError("");
    try {
      const response = await api.get<ApiResponse<TrackedOrder>>(`/orders/track/${encodeURIComponent(orderNumber)}`);
      if (!response.data.success) throw new Error(response.data.message || "Order not found.");
      setOrder(response.data.data);
      setReference(response.data.data.order_number);
    } catch (cause) {
      setOrder(null);
      setError((cause as { response?: { data?: { message?: string } }; message?: string })?.response?.data?.message || "We could not find that order. Check the reference and try again.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    document.title = "Track Your Order | Special Delicacies";
    const initial = params.get("order_number");
    if (initial) void track(initial);
  // Load only when the route's initial order number changes.
  }, [params, track]);

  useEffect(() => {
    if (!order?.tracking_channel) return;
    const channelName = order.tracking_channel;
    const channel = echo.channel(channelName);
    const refresh = () => { void track(order.order_number); };
    channel.listen(".order.tracking.updated", refresh);
    // Polling recovers any updates missed while the socket was disconnected.
    const timer = window.setInterval(refresh, 30_000);
    const onVisible = () => { if (document.visibilityState === "visible") refresh(); };
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", onVisible);
      echo.leaveChannel(channelName);
    };
  }, [order?.order_number, order?.tracking_channel, track]);

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    void track(reference);
  };

  return <main className="min-h-screen bg-[var(--color-background)] px-4 py-10 text-[var(--color-text)] sm:py-14">
    <div className="mx-auto w-full max-w-2xl space-y-5">
      <section className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6 shadow-[var(--shadow-md)] sm:p-8">
        <p className="text-xs font-bold uppercase tracking-[.18em] text-[var(--color-accent)]">Special Delicacies</p>
        <h1 className="mt-2 text-2xl font-black sm:text-3xl">Track your order</h1>
        <p className="mt-2 text-sm text-[var(--color-text-secondary)]">Enter the order reference from your confirmation.</p>
        <form onSubmit={submit} className="mt-5 flex flex-col gap-2 sm:flex-row">
          <input aria-label="Order reference" required value={reference} onChange={(event) => setReference(event.target.value)} placeholder="SD-20261124-…" className="min-h-11 min-w-0 flex-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3.5 font-mono text-sm outline-none focus:ring-2 focus:ring-[var(--color-accent)]" />
          <button type="submit" disabled={loading} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-5 text-sm font-bold text-[var(--color-background)] disabled:opacity-50">{loading ? <LoaderCircle size={16} className="animate-spin" /> : <Search size={16} />}Track order</button>
        </form>
        {error && <p role="alert" className="mt-3 text-sm text-[var(--color-danger)]">{error}</p>}
      </section>

      {order && <section aria-live="polite" className="space-y-4 rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-[var(--shadow-sm)] sm:p-7">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div><p className="font-mono text-lg font-black">{order.order_number}</p><p className="mt-1 text-sm capitalize text-[var(--color-text-secondary)]">{order.order_type.replaceAll("_", " ")} · {order.status.replaceAll("_", " ")}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{new Date(order.created_at).toLocaleString()}</p></div>
          <span className="rounded-full bg-[var(--color-surface-secondary)] px-3 py-1 text-xs font-bold capitalize">{order.payment_details.status === "paid" ? "Paid" : "Payment pending"}</span>
        </div>
        <div className="grid gap-3 text-sm sm:grid-cols-2">
          <div className="rounded-xl bg-[var(--color-background)] p-3"><p className="text-xs text-[var(--color-text-secondary)]">Payment</p><p className="mt-1 font-semibold">{order.payment_details.gateway_name || (order.payment_details.method === "cash" ? "Cash" : "Online payment")}</p><p className="mt-1 text-xs">GH₵ {Number(order.payment_details.amount).toFixed(2)} · {order.payment_details.status}</p>{order.payment_details.reference && <p className="mt-1 break-all font-mono text-[11px] text-[var(--color-text-secondary)]">Reference: {order.payment_details.reference}</p>}</div>
          <div className="rounded-xl bg-[var(--color-background)] p-3"><p className="text-xs text-[var(--color-text-secondary)]">Fulfilment</p><p className="mt-1 flex items-center gap-2 font-semibold"><MapPin size={15} />{order.table ? `Table ${order.table.table_number}` : order.delivery_zone?.name || "Home delivery"}</p><p className="mt-1 text-xs">Order total · GH₵ {Number(order.total).toFixed(2)}</p></div>
        </div>
        <div className="border-t border-[var(--color-border)] pt-3"><h2 className="text-sm font-bold">Items</h2><ul className="mt-2 space-y-1">{order.items.map((item, i) => <li key={`${item.item_name}-${i}`} className="text-sm text-[var(--color-text-secondary)]">{item.quantity} × {item.item_name}</li>)}</ul></div>
        <div className="border-t border-[var(--color-border)] pt-3"><h2 className="text-sm font-bold">Order progress</h2><ol className="mt-2 space-y-2">{order.status_history.map((entry, i) => <li key={`${entry.status}-${entry.created_at}-${i}`} className="flex items-center justify-between gap-3 text-sm"><span className="font-semibold capitalize">{entry.status.replaceAll("_", " ")}</span><time className="text-xs text-[var(--color-text-secondary)]">{new Date(entry.created_at).toLocaleString()}</time></li>)}{!order.status_history.length && <li className="text-sm capitalize text-[var(--color-text-secondary)]">{order.status.replaceAll("_", " ")}</li>}</ol></div>
      </section>}
      <div className="text-center"><Link to="/" className="text-sm font-semibold text-[var(--color-accent)] hover:underline">Return to homepage</Link></div>
    </div>
  </main>;
}
