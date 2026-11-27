import { useCallback, useEffect, useState } from "react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";

type Payer = { type: string; id: number; name: string };
type Refund = {
  id: number; refund_reference: string; status: string; amount: string; currency: string; reason: string;
  payer: Payer; payment: { transaction_reference: string; provider: string | null; payment_method: string | null } | null;
  provider: string | null; provider_refund_reference: string | null; verification_reference: string | null;
  requested_by: string | null; requested_at: string; approved_by: string | null; submitted_at: string | null;
  confirmed_at: string | null; failed_at: string | null; failure_reason: string | null; rejection_reason: string | null;
};
type RefundDetail = Refund & { events: { action: string; actor: string | null; reason: string | null; created_at: string }[]; provider_supports_refunds: boolean; provider_submission_enabled: boolean };
type Payment = { id: number; transaction_reference: string; provider: string | null; payment_method: string | null; amount: string; currency: string; payer: Payer; refundable_amount: string; received_at: string };
type Page<T> = { data: T[] };

const STATUS_TONE: Record<string, string> = {
  requested: "text-[var(--color-text-secondary)]", approved: "text-[var(--color-accent)]",
  submitted: "text-[var(--color-accent)]", confirmed: "text-[var(--color-success)]",
  rejected: "text-[var(--color-danger)]", failed: "text-[var(--color-danger)]",
};

export default function AdminRefundsPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.roles?.includes("super-admin") ?? false;
  const permissions = user?.permissions ?? [];
  const canView = isSuperAdmin || permissions.some(p => ["financials.refunds.view", "financials.refunds.initiate", "financials.refunds.approve"].includes(p));
  const canInitiate = isSuperAdmin || permissions.includes("financials.refunds.initiate");
  const canApprove = isSuperAdmin || permissions.includes("financials.refunds.approve");

  const [refunds, setRefunds] = useState<Refund[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [detail, setDetail] = useState<RefundDetail | null>(null);
  const [paymentId, setPaymentId] = useState<number | null>(null);
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    const [refundResponse, paymentResponse] = await Promise.all([
      canView ? api.get<Page<Refund>>("/admin/financials/refunds", { params: { per_page: 50 } }) : Promise.resolve({ data: { data: [] as Refund[] } }),
      canInitiate ? api.get<Page<Payment>>("/admin/financials/refunds/eligible-payments", { params: { per_page: 50 } }) : Promise.resolve({ data: { data: [] as Payment[] } }),
    ]);
    setRefunds(refundResponse.data.data);
    setPayments(paymentResponse.data.data);
  }, [canView, canInitiate]);

  useEffect(() => { void refresh().catch(() => setError("Refund information could not be loaded.")); }, [refresh]);

  async function run(action: () => Promise<void>, success: string) {
    setBusy(true); setError(""); setMessage("");
    try { await action(); setMessage(success); await refresh(); }
    catch (cause) {
      const response = (cause as { response?: { data?: { message?: string } } }).response;
      setError(response?.data?.message ?? "The refund operation was rejected.");
    } finally { setBusy(false); }
  }

  const createRefund = () => {
    if (!paymentId) { setError("Select an eligible payment first."); return; }
    if (!window.confirm(`Request a refund of ${amount}? This does not move money until it is approved, submitted and confirmed.`)) return;
    void run(async () => {
      await api.post("/admin/financials/refunds", {
        payment_transaction_id: paymentId, amount, reason,
        refund_reference: `refund-${crypto.randomUUID()}`,
      });
      setPaymentId(null); setAmount(""); setReason("");
    }, "Refund request recorded. It now needs a different approver.");
  };

  const openDetail = (id: number) => {
    void run(async () => { const response = await api.get<{ data: RefundDetail }>(`/admin/financials/refunds/${id}`); setDetail(response.data.data); }, "Refund detail loaded.");
  };

  const act = (refund: Refund, action: "approve" | "reject" | "submit" | "confirm" | "fail") => {
    let payload: Record<string, string> = {};
    if (action === "reject" || action === "fail") {
      const entered = window.prompt("Enter the reason:"); if (!entered) return; payload = { reason: entered };
    }
    if (action === "approve" && !window.confirm("Approve this refund? A different staff member must have requested it.")) return;
    if (action === "submit" && !window.confirm("Record this refund as submitted? Manual payouts need independent confirmation with evidence.")) return;
    if (action === "confirm") {
      const reference = window.prompt("Payment reference for the refund:"); if (!reference) return;
      const evidence = window.prompt("Describe the evidence (e.g. signed receipt):"); if (!evidence) return;
      payload = { provider_reference: reference, evidence };
    }
    void run(async () => { await api.post(`/admin/financials/refunds/${refund.id}/${action}`, payload); }, `Refund ${action} recorded.`);
  };

  const controlClass = "mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 text-sm";
  const selectedPayment = payments.find(p => p.id === paymentId);

  if (!canView) return <p className="p-6 text-sm text-[var(--color-text-secondary)]">You do not have permission to view refunds.</p>;

  return <div className="mx-auto max-w-7xl space-y-6">
    <header>
      <p className="text-xs font-bold uppercase tracking-[.18em] text-[var(--color-accent)]">Stage 4C · Finance</p>
      <h1 className="mt-1 text-2xl font-black">Refunds</h1>
      <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Requested → approved → submitted → confirmed. A browser return never confirms a refund; only provider verification or independent evidence does.</p>
    </header>
    {message && <p role="status" className="rounded-xl bg-[var(--color-success)]/10 p-3 text-sm">{message}</p>}
    {error && <p role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}

    {canInitiate && <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
      <h2 className="text-lg font-bold">Request a refund</h2>
      <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Only the receipt’s uncommitted funds are refundable. Allocated or credited funds must be released first.</p>
      <div className="mt-3 grid gap-3 md:grid-cols-3">
        <label className="text-sm">Eligible payment
          <select className={controlClass} value={paymentId ?? ""} onChange={event => { const id = Number(event.target.value) || null; setPaymentId(id); const payment = payments.find(p => p.id === id); setAmount(payment?.refundable_amount ?? ""); }}>
            <option value="">Select a payment…</option>
            {payments.map(payment => <option key={payment.id} value={payment.id}>{payment.transaction_reference} · {payment.payer.name} · {payment.currency} {payment.refundable_amount}</option>)}
          </select>
        </label>
        <label className="text-sm">Amount {selectedPayment ? <span className="text-[var(--color-text-secondary)]">(max {selectedPayment.currency} {selectedPayment.refundable_amount})</span> : null}
          <input className={controlClass} inputMode="decimal" value={amount} onChange={event => setAmount(event.target.value)} />
        </label>
        <label className="text-sm">Reason
          <input className={controlClass} value={reason} onChange={event => setReason(event.target.value)} placeholder="Why is this refund due?" />
        </label>
      </div>
      <button type="button" disabled={busy} onClick={createRefund} className="mt-3 min-h-11 rounded-lg bg-[var(--color-accent)] px-4 font-semibold text-white disabled:opacity-50">Submit refund request</button>
    </section>}

    <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
      <h2 className="text-lg font-bold">Refund requests</h2>
      <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[900px] text-left text-sm">
        <thead className="text-[var(--color-text-secondary)]"><tr><th className="p-2">Reference</th><th className="p-2">Payer</th><th className="p-2">Amount</th><th className="p-2">Status</th><th className="p-2">Requested</th><th className="p-2">Actions</th></tr></thead>
        <tbody>{refunds.map(refund => <tr key={refund.id} className="border-t align-top" style={{ borderColor: "var(--color-border)" }}>
          <td className="p-2 font-semibold">{refund.refund_reference}<small className="block font-normal text-[var(--color-text-secondary)]">{refund.payment?.transaction_reference}</small></td>
          <td className="p-2">{refund.payer.name}<small className="block text-[var(--color-text-secondary)]">{refund.reason}</small></td>
          <td className="p-2 font-semibold">{refund.currency} {refund.amount}</td>
          <td className={`p-2 font-semibold ${STATUS_TONE[refund.status] ?? ""}`}>{refund.status}{refund.failure_reason ? <small className="block font-normal text-[var(--color-danger)]">{refund.failure_reason}</small> : null}{refund.rejection_reason ? <small className="block font-normal text-[var(--color-danger)]">{refund.rejection_reason}</small> : null}</td>
          <td className="p-2">{refund.requested_by ?? "—"}<small className="block text-[var(--color-text-secondary)]">{new Date(refund.requested_at).toLocaleString()}</small></td>
          <td className="p-2"><div className="flex flex-wrap gap-1">
            <button type="button" onClick={() => openDetail(refund.id)} className="min-h-9 rounded-lg border px-2 font-semibold" style={{ borderColor: "var(--color-border)" }}>View</button>
            {canApprove && refund.status === "requested" && <>
              <button type="button" disabled={busy} onClick={() => act(refund, "approve")} className="min-h-9 rounded-lg border px-2 font-semibold" style={{ borderColor: "var(--color-border)" }}>Approve</button>
              <button type="button" disabled={busy} onClick={() => act(refund, "reject")} className="min-h-9 rounded-lg border px-2 font-semibold text-[var(--color-danger)]" style={{ borderColor: "var(--color-border)" }}>Reject</button>
            </>}
            {canApprove && refund.status === "approved" && <>
              <button type="button" disabled={busy} onClick={() => act(refund, "submit")} className="min-h-9 rounded-lg border px-2 font-semibold" style={{ borderColor: "var(--color-border)" }}>Submit</button>
              <button type="button" disabled={busy} onClick={() => act(refund, "reject")} className="min-h-9 rounded-lg border px-2 font-semibold text-[var(--color-danger)]" style={{ borderColor: "var(--color-border)" }}>Reject</button>
            </>}
            {canApprove && refund.status === "submitted" && <>
              <button type="button" disabled={busy} onClick={() => act(refund, "confirm")} className="min-h-9 rounded-lg border px-2 font-semibold" style={{ borderColor: "var(--color-border)" }}>Confirm</button>
              <button type="button" disabled={busy} onClick={() => act(refund, "fail")} className="min-h-9 rounded-lg border px-2 font-semibold text-[var(--color-danger)]" style={{ borderColor: "var(--color-border)" }}>Mark failed</button>
            </>}
          </div></td>
        </tr>)}
        {refunds.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-[var(--color-text-secondary)]">No refunds recorded.</td></tr>}
        </tbody>
      </table></div>
    </section>

    {detail && <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
      <div className="flex items-start justify-between gap-3"><div><h2 className="text-lg font-bold">Refund {detail.refund_reference}</h2><p className="text-sm text-[var(--color-text-secondary)]">{detail.currency} {detail.amount} · {detail.status}</p></div><button type="button" onClick={() => setDetail(null)} className="min-h-9 rounded-lg border px-3 font-semibold" style={{ borderColor: "var(--color-border)" }}>Close</button></div>
      <dl className="mt-3 grid gap-2 text-sm md:grid-cols-2">
        <div><dt className="text-[var(--color-text-secondary)]">Payment</dt><dd>{detail.payment?.transaction_reference ?? "—"} ({detail.payment?.provider ?? "manual"})</dd></div>
        <div><dt className="text-[var(--color-text-secondary)]">Provider reference</dt><dd>{detail.provider_refund_reference ?? detail.verification_reference ?? "—"}</dd></div>
        <div><dt className="text-[var(--color-text-secondary)]">Provider refund API</dt><dd>{detail.provider_submission_enabled ? (detail.provider_supports_refunds ? "Enabled" : "Not supported by provider") : "Manual payout only (owner approval pending)"}</dd></div>
      </dl>
      <h3 className="mt-4 font-semibold">Audit history</h3>
      <ul className="mt-2 space-y-1 text-sm">{detail.events.map((event, index) => <li key={`${event.action}-${index}`} className="border-t py-2" style={{ borderColor: "var(--color-border)" }}><span className="font-semibold">{event.action}</span> · {event.actor ?? "System"} · {new Date(event.created_at).toLocaleString()}{event.reason ? <span className="block text-[var(--color-text-secondary)]">{event.reason}</span> : null}</li>)}</ul>
    </section>}
  </div>;
}
