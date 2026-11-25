import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { CheckCircle2, Clock3, CreditCard, Copy, LoaderCircle, MapPin, PackageCheck, RefreshCw, Search, XCircle } from "lucide-react";
import api from "@/lib/api";
import type { ApiResponse, PaymentDetails } from "@/types/api";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";

interface PaymentResult {
  order_number: string;
  order_status: string;
  payment_status: string;
  provider_payment_status: string | null;
  payment_details: PaymentDetails;
}

interface TrackedOrder {
  order_number: string;
  order_type: string;
  status: string;
  total: string;
  payment_details: PaymentDetails;
  items: Array<{ item_name: string; quantity: number }>;
  table: { table_number: string } | null;
  delivery_zone: { name: string } | null;
  created_at: string;
  updated_at: string;
  status_history: Array<{ status: string; created_at: string }>;
}

export default function PaymentResultPage() {
  const { content } = useRestaurantContent();
  const [searchParams] = useSearchParams();
  const reference = searchParams.get("reference") || searchParams.get("trxref") || searchParams.get("transaction_reference") || "";
  const referenceKey = [...new Set([searchParams.get("reference"), searchParams.get("trxref"), searchParams.get("transaction_reference")].filter((value): value is string => Boolean(value)))].join("\n");
  const references = referenceKey ? referenceKey.split("\n") : [];
  const returnStatus = (searchParams.get("status") || searchParams.get("payment_status") || "").toLowerCase();
  const cancelled = ["cancelled", "canceled", "failed", "failure"].includes(returnStatus)
    || searchParams.get("cancelled") === "true";
  const [result, setResult] = useState<PaymentResult | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [orderNumber, setOrderNumber] = useState(searchParams.get("order_number") || "");
  const [trackedOrder, setTrackedOrder] = useState<TrackedOrder | null>(null);
  const [tracking, setTracking] = useState(false);
  const [trackingError, setTrackingError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const trackOrder = useCallback(async (number: string) => {
    const cleaned = number.trim();
    if (!cleaned) return;
    setTracking(true);
    setTrackingError(null);
    try {
      const response = await api.get<ApiResponse<TrackedOrder>>(`/orders/track/${encodeURIComponent(cleaned)}`);
      if (!response.data.success) throw new Error(response.data.message || "Order could not be found.");
      setTrackedOrder(response.data.data);
      setOrderNumber(response.data.data.order_number);
    } catch (err: any) {
      setTrackedOrder(null);
      setTrackingError(err?.response?.data?.message || err?.message || "We could not find that order. Check the order number and try again.");
    } finally {
      setTracking(false);
    }
  }, []);

  const verify = useCallback(async () => {
    if (!reference) {
      setError("No payment reference was returned. Enter your order number below to check its status.");
      setLoading(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      let payment: PaymentResult | null = null;
      let lastError: unknown;
      for (const candidate of references) {
        try {
          const response = await api.get<ApiResponse<PaymentResult>>(`/payments/verify/${encodeURIComponent(candidate)}`);
          if (response.data.success) { payment = response.data.data; break; }
        } catch (err) {
          lastError = err;
          if ((err as { response?: { status?: number } })?.response?.status !== 404) throw err;
        }
      }
      if (!payment) throw lastError || new Error("Payment reference was not found.");
      setResult(payment);
      setOrderNumber(payment.order_number);
      if (payment.payment_status === "paid") sessionStorage.removeItem("delivery_cart");
      await trackOrder(payment.order_number);
    } catch (err: any) {
      setError(err?.response?.data?.message || "We could not confirm payment yet. You can retry or track the order below.");
    } finally {
      setLoading(false);
    }
  }, [reference, referenceKey, trackOrder]);

  useEffect(() => { document.title = `${content.restaurant_name} | Payment and Order Status`; }, [content.restaurant_name]);
  useEffect(() => { void verify(); }, [verify]);

  const submitTracking = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    void trackOrder(orderNumber);
  };

  const copyOrderNumber = async () => {
    if (!result?.order_number) return;
    try {
      await navigator.clipboard.writeText(result.order_number);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1800);
    } catch { setError("Could not copy the order reference. Please select and copy it manually."); }
  };

  const paid = result?.payment_status === "paid" || trackedOrder?.payment_details.status === "paid";
  const paymentCancelled = cancelled || ["cancelled", "canceled", "failed", "failure", "abandoned", "declined"].includes((result?.provider_payment_status || "").toLowerCase());
  const heading = loading
    ? "Checking your payment"
    : paid
      ? "Payment successful"
      : paymentCancelled
        ? "Payment cancelled"
        : result
          ? "Payment not completed yet"
          : "Payment status unavailable";

  return (
    <main className="min-h-screen bg-[var(--color-background)] px-4 py-8 text-[var(--color-text)] sm:py-12">
      <div className="mx-auto w-full max-w-2xl space-y-5">
        <section className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6 text-center shadow-[var(--shadow-md)] sm:p-9">
          <div className="mb-5 flex items-center justify-center gap-3">
            {content.restaurant_logo && <img src={assetUrl(content.restaurant_logo) ?? undefined} alt="" className="h-11 w-11 rounded-xl object-contain" />}
            <span className="text-sm font-bold">{content.restaurant_name}</span>
          </div>
          <div className="mx-auto mb-5 grid h-16 w-16 place-items-center rounded-2xl bg-[var(--color-surface-secondary)]">
            {loading ? <LoaderCircle size={30} className="animate-spin text-[var(--color-accent)]" /> : paid ? <CheckCircle2 size={30} className="text-[var(--color-success)]" /> : cancelled ? <XCircle size={30} className="text-[var(--color-danger)]" /> : result ? <Clock3 size={30} className="text-[var(--color-warning)]" /> : <XCircle size={30} className="text-[var(--color-danger)]" />}
          </div>
          <h1 className="text-2xl font-bold sm:text-3xl">{heading}</h1>
          <p className="mx-auto mt-3 max-w-lg text-sm leading-relaxed text-[var(--color-text-secondary)]">
            {error || (paid
              ? "Your payment has been confirmed by the payment provider. Your order is now visible to the restaurant team."
              : paymentCancelled
                ? "The payment was cancelled or declined. Your order number is still available below so you can check its current status or contact the restaurant."
                : "The provider has not confirmed a successful payment. Check again in a moment or use your order number to track progress.")}
          </p>
          {result && <div className="mt-5 rounded-2xl bg-[var(--color-background)] p-4 text-left"><p className="text-xs font-bold uppercase tracking-wide text-[var(--color-text-secondary)]">Your order reference</p><div className="mt-1 flex flex-wrap items-center justify-between gap-2"><p className="break-all font-mono text-lg font-black">{result.order_number}</p><button type="button" onClick={() => void copyOrderNumber()} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-[var(--color-border)] px-3 text-xs font-bold"><Copy size={14} />{copied ? "Copied" : "Copy"}</button></div><p className="mt-2 text-xs text-[var(--color-text-secondary)]">Payment reference · {result.payment_details.reference || reference}</p><p className="mt-1 text-sm font-semibold">GH₵ {Number(result.payment_details.amount).toFixed(2)} · {result.payment_details.gateway_name || "Online payment"}</p><p className="mt-1 text-xs capitalize text-[var(--color-text-secondary)]">{result.payment_status} · {trackedOrder?.order_type.replaceAll("_", " ") || "Order"}{trackedOrder?.created_at ? ` · ${new Date(trackedOrder.created_at).toLocaleString()}` : ""}</p></div>}
          {paid && result && <Link to={`/track-order?order_number=${encodeURIComponent(result.order_number)}`} className="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-5 text-sm font-semibold text-[var(--color-background)] hover:bg-[var(--color-primary-hover)]"><PackageCheck size={16} />Track my order</Link>}
          {!paid && reference && (
            <button type="button" onClick={() => void verify()} disabled={loading} className="mt-5 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-5 text-sm font-semibold text-[var(--color-background)] hover:bg-[var(--color-primary-hover)] disabled:opacity-50">
              <RefreshCw size={16} className={loading ? "animate-spin" : ""} /> Check payment again
            </button>
          )}
        </section>

        <section className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-[var(--shadow-sm)] sm:p-7">
          <div className="mb-4 flex items-center gap-3">
            <div className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--color-surface-secondary)] text-[var(--color-accent)]"><PackageCheck size={20} /></div>
            <div>
              <h2 className="font-bold">Track your order</h2>
              <p className="text-xs text-[var(--color-text-secondary)]">Enter the order number from your confirmation.</p>
            </div>
          </div>
          <form onSubmit={submitTracking} className="flex flex-col gap-2 sm:flex-row">
            <input aria-label="Order number" required value={orderNumber} onChange={(event) => setOrderNumber(event.target.value)} placeholder="e.g. SD-123456" className="min-h-11 min-w-0 flex-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3.5 text-sm text-[var(--color-text)] outline-none focus:ring-2 focus:ring-[var(--color-accent)]" />
            <button type="submit" disabled={tracking} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-5 text-sm font-semibold text-[var(--color-background)] hover:bg-[var(--color-primary-hover)] disabled:opacity-50">
              {tracking ? <LoaderCircle size={16} className="animate-spin" /> : <Search size={16} />} Track order
            </button>
          </form>
          {trackingError && <p role="alert" className="mt-3 text-sm text-[var(--color-danger)]">{trackingError}</p>}

          {trackedOrder && (
            <div className="mt-5 space-y-4 rounded-2xl border border-[var(--color-border)] bg-[var(--color-background)] p-4 sm:p-5">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div><p className="font-mono text-sm font-bold">{trackedOrder.order_number}</p><p className="mt-1 text-xs capitalize text-[var(--color-text-secondary)]">{trackedOrder.order_type.replaceAll("_", " ")} · {trackedOrder.status.replaceAll("_", " ")}</p></div>
                <span className="rounded-full bg-[var(--color-surface-secondary)] px-3 py-1 text-xs font-bold capitalize">{trackedOrder.payment_details.status === "paid" ? "Paid" : trackedOrder.payment_details.method === "gateway" ? "Online payment pending" : "Pay on delivery"}</span>
              </div>
              <div className="grid gap-3 text-sm sm:grid-cols-2">
                <div className="rounded-xl bg-[var(--color-surface)] p-3"><p className="text-xs text-[var(--color-text-secondary)]">Payment</p><p className="mt-1 flex items-center gap-2 font-semibold"><CreditCard size={15} />{trackedOrder.payment_details.gateway_name || "Cash on delivery"}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{trackedOrder.payment_details.status === "paid" ? "Confirmed" : "Not yet confirmed"} · GH₵ {Number(trackedOrder.payment_details.amount).toFixed(2)}</p>{trackedOrder.payment_details.reference && <p className="mt-1 break-all font-mono text-[11px] text-[var(--color-text-secondary)]">Ref: {trackedOrder.payment_details.reference}</p>}</div>
                <div className="rounded-xl bg-[var(--color-surface)] p-3"><p className="text-xs text-[var(--color-text-secondary)]">Fulfilment</p><p className="mt-1 flex items-center gap-2 font-semibold"><MapPin size={15} />{trackedOrder.table ? `Table ${trackedOrder.table.table_number}` : trackedOrder.delivery_zone?.name || "Home delivery"}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Order total · GH₵ {Number(trackedOrder.total).toFixed(2)}</p></div>
              </div>
              <div className="space-y-1 border-t border-[var(--color-border)] pt-3">
                {trackedOrder.items.map((item, index) => <p key={`${item.item_name}-${index}`} className="text-xs text-[var(--color-text-secondary)]">{item.quantity} × {item.item_name}</p>)}
              </div>
            </div>
          )}
        </section>

        <div className="text-center"><Link to="/" className="text-sm font-semibold text-[var(--color-accent)] hover:underline">Back to restaurant</Link></div>
      </div>
    </main>
  );
}
