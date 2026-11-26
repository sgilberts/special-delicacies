import { useCallback, useEffect, useMemo, useState } from "react";
import { AlertCircle, CheckCircle2, RefreshCw, WalletCards } from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { hasAnyPermission } from "@/lib/permissionAccess";

type Receipt = {
  id: number; transaction_reference: string; provider_reference: string | null; provider: string | null;
  payment_method: string; amount: string; allocated_amount: string; unallocated_amount: string;
  currency: string; verified_at: string; received_at: string; payer: { type: string; name: string };
};
type Invoice = {
  id: number; document_number: string; issue_date: string | null; currency: string; total: string;
  allocated_amount: string; outstanding_balance: string; payment_state: string; customer: string | null; organisation: string | null;
};
type AllocationHistory = { operation_reference: string; allocated_at: string; allocated_by: string | null; allocations: { id: number; document_id: number; document_number: string; amount: string; currency: string; reversal: { reason: string; reversed_at: string; reversed_by?: string | null } | null }[] };
type Page<T> = { data: T[]; meta?: { current_page: number; last_page: number; total: number } };
type ApiData<T> = { data: T };
type AllocationPreview = {
  payment: { transaction_reference: string; payer: string | null; currency: string; original_amount: string; allocated_amount: string; available_amount: string };
  invoices: { commercial_document_id: number; document_number: string; customer: string | null; organisation: string | null; currency: string; invoice_total: string; allocated_amount: string; proposed_amount: string; resulting_balance: string }[];
  proposed_total: string;
  remaining_unallocated: string;
};
const panel = "rounded-2xl border bg-[var(--color-surface)] p-4 text-[var(--color-text)] sm:p-5";
const input = "min-h-11 w-full rounded-xl border bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]";
const money = (currency: string, amount: string) => `${currency} ${amount}`;

export default function AdminPaymentAllocationsPage() {
  const { user } = useAuth();
  const canManage = hasAnyPermission(user?.permissions, ["financials.allocations.manage"], user?.roles);
  const canReverse = hasAnyPermission(user?.permissions, ["financials.allocations.reverse"], user?.roles);
  const [receipts, setReceipts] = useState<Receipt[]>([]);
  const [receiptId, setReceiptId] = useState("");
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [amounts, setAmounts] = useState<Record<number, string>>({});
  const [history, setHistory] = useState<AllocationHistory[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [search, setSearch] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [reason, setReason] = useState("");
  const [preview, setPreview] = useState<AllocationPreview | null>(null);
  const [reverseTarget, setReverseTarget] = useState<{ allocationId: number; documentNumber: string; amount: string; operationReference: string } | null>(null);
  const [reverseReason, setReverseReason] = useState("");
  const [confirmReverse, setConfirmReverse] = useState(false);

  const receipt = receipts.find((item) => String(item.id) === receiptId) ?? null;
  const proposed = useMemo(() => invoices.flatMap((invoice) => {
    const amount = amounts[invoice.id]?.trim();
    if (!amount || !/^\d+(\.\d{1,2})?$/.test(amount) || Number(amount) <= 0) return [];
    return [{ invoice, amount: Number(amount).toFixed(2) }];
  }), [amounts, invoices]);
  const canPrepare = !!receipt && proposed.length > 0 && !busy;

  const loadReceipts = useCallback(async () => {
    setLoading(true); setError("");
    try {
      const response = await api.get<Page<Receipt>>(`/admin/financials/payment-allocations/payments?per_page=100${search ? `&search=${encodeURIComponent(search)}` : ""}`);
      setReceipts(response.data.data);
      if (receiptId && !response.data.data.some((item) => String(item.id) === receiptId)) { setReceiptId(""); setPreview(null); }
    } catch (exception: unknown) {
      setError((exception as { response?: { data?: { message?: string } } }).response?.data?.message || "Could not load eligible payment receipts.");
    } finally { setLoading(false); }
  }, [receiptId, search]);

  useEffect(() => { void loadReceipts(); }, [loadReceipts]);

  useEffect(() => {
    if (!receiptId) { setInvoices([]); setHistory([]); setAmounts({}); return; }
    let active = true;
    setError(""); setAmounts({}); setPreview(null);
    Promise.all([
      api.get<Page<Invoice>>(`/admin/financials/payment-allocations/payments/${receiptId}/invoices?per_page=100`),
      api.get<ApiData<{ allocations: AllocationHistory[] }>>(`/admin/financials/payment-allocations/payments/${receiptId}`),
    ]).then(([invoiceResponse, paymentResponse]) => {
      if (!active) return;
      setInvoices(invoiceResponse.data.data);
      setHistory(paymentResponse.data.data.allocations);
    }).catch((exception: unknown) => {
      if (active) setError((exception as { response?: { data?: { message?: string } } }).response?.data?.message || "Could not load invoices for this receipt.");
    });
    return () => { active = false; };
  }, [receiptId]);

  async function prepareAllocation() {
    if (!receipt || !canPrepare) return;
    setBusy(true); setError(""); setNotice("");
    try {
      const response = await api.post<ApiData<AllocationPreview>>("/admin/financials/payment-allocations/preview", {
        payment_transaction_id: receipt.id,
        allocations: proposed.map(({ invoice, amount }) => ({ commercial_document_id: invoice.id, amount })),
      });
      setPreview(response.data.data);
    } catch (exception: unknown) {
      setPreview(null);
      setError((exception as { response?: { data?: { message?: string } } }).response?.data?.message || "Could not prepare an allocation preview.");
    } finally { setBusy(false); }
  }

  async function confirmAllocation() {
    if (!receipt || !preview || busy) return;
    setBusy(true); setError(""); setNotice("");
    try {
      await api.post("/admin/financials/payment-allocations", {
        payment_transaction_id: receipt.id,
        operation_reference: crypto.randomUUID(),
        reason: reason.trim() || undefined,
        allocations: preview.invoices.map(({ commercial_document_id, proposed_amount }) => ({ commercial_document_id, amount: proposed_amount })),
      });
      setNotice("Allocation recorded. Receipt and invoice balances have been refreshed.");
      setAmounts({}); setReason(""); setPreview(null);
      await loadReceipts();
      const [invoiceResponse, paymentResponse] = await Promise.all([
        api.get<Page<Invoice>>(`/admin/financials/payment-allocations/payments/${receipt.id}/invoices?per_page=100`),
        api.get<ApiData<{ allocations: AllocationHistory[] }>>(`/admin/financials/payment-allocations/payments/${receipt.id}`),
      ]);
      setInvoices(invoiceResponse.data.data); setHistory(paymentResponse.data.data.allocations);
    } catch (exception: unknown) {
      setError((exception as { response?: { data?: { message?: string } } }).response?.data?.message || "Allocation was not recorded.");
    } finally { setBusy(false); }
  }

  async function confirmReversal() {
    if (!reverseTarget || !reverseReason.trim() || busy) return;
    setBusy(true); setError(""); setNotice("");
    try {
      await api.post(`/admin/financials/payment-allocations/${reverseTarget.allocationId}/reverse`, {
        operation_reference: crypto.randomUUID(), reason: reverseReason.trim(),
      });
      setNotice(`Allocation ${reverseTarget.operationReference} reversed. Receipt and invoice balances have been refreshed.`);
      setReverseTarget(null); setReverseReason(""); setConfirmReverse(false);
      if (receipt) {
        await loadReceipts();
        const [invoiceResponse, paymentResponse] = await Promise.all([
          api.get<Page<Invoice>>(`/admin/financials/payment-allocations/payments/${receipt.id}/invoices?per_page=100`),
          api.get<ApiData<{ allocations: AllocationHistory[] }>>(`/admin/financials/payment-allocations/payments/${receipt.id}`),
        ]);
        setInvoices(invoiceResponse.data.data); setHistory(paymentResponse.data.data.allocations);
      }
    } catch (exception: unknown) {
      setError((exception as { response?: { data?: { message?: string } } }).response?.data?.message || "The allocation could not be reversed.");
    } finally { setBusy(false); }
  }

  return <div className="space-y-5 p-4 sm:p-6 lg:p-8">
    <header><p className="text-xs font-bold uppercase tracking-wider text-[var(--color-accent)]">Finance</p><h1 className="mt-1 flex items-center gap-2 text-2xl font-black text-[var(--color-text)]"><WalletCards size={25} /> Payment allocations</h1><p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">Assign verified invoice receipts to issued invoices. Order payments are excluded, and the server rechecks every balance before saving.</p></header>
    {error && <p role="alert" className="flex gap-2 rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]"><AlertCircle size={18} className="shrink-0" />{error}</p>}
    {notice && <p role="status" className="flex gap-2 rounded-xl bg-[var(--color-accent)]/10 p-3 text-sm"><CheckCircle2 size={18} className="shrink-0 text-[var(--color-accent)]" />{notice}</p>}
    <section className={panel}>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><label className="min-w-0 flex-1 text-sm font-semibold">Find receipt<input className={`${input} mt-1`} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Reference, payer or provider reference" /></label><button type="button" onClick={() => void loadReceipts()} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border px-4 text-sm font-semibold" style={{ borderColor: "var(--color-border)" }}><RefreshCw size={16} />Refresh</button></div>
      <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[720px] text-left text-sm"><thead><tr className="border-b text-xs uppercase text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}><th className="py-3 pr-3">Receipt</th><th className="py-3 pr-3">Payer</th><th className="py-3 pr-3">Received</th><th className="py-3 pr-3">Amount</th><th className="py-3 pr-3">Unallocated</th><th className="py-3">Action</th></tr></thead><tbody>{receipts.map((item) => <tr key={item.id} className="border-b" style={{ borderColor: "var(--color-border)" }}><td className="py-3 pr-3"><p className="break-all font-semibold">{item.transaction_reference}</p><p className="text-xs text-[var(--color-text-secondary)]">{item.provider || item.payment_method}</p></td><td className="py-3 pr-3">{item.payer.name}<p className="text-xs capitalize text-[var(--color-text-secondary)]">{item.payer.type}</p></td><td className="py-3 pr-3">{new Date(item.received_at).toLocaleString()}</td><td className="py-3 pr-3">{money(item.currency, item.amount)}</td><td className="py-3 pr-3 font-bold">{money(item.currency, item.unallocated_amount)}</td><td className="py-3"><button type="button" onClick={() => { setReceiptId(String(item.id)); setPreview(null); }} className="min-h-9 rounded-lg border px-3 text-xs font-bold" style={{ borderColor: "var(--color-border)" }}>{receiptId === String(item.id) ? "Selected" : "Allocate"}</button></td></tr>)}</tbody></table>{!loading && receipts.length === 0 && <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">No eligible invoice receipts are available.</p>}{loading && <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">Loading receipts…</p>}</div>
    </section>
    {receipt && <section className={panel}>
      <div><h2 className="text-lg font-bold">Allocate {receipt.transaction_reference}</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">{receipt.payer.name} · {money(receipt.currency, receipt.unallocated_amount)} available</p></div>
      {invoices.length === 0 ? <p className="mt-4 rounded-xl bg-[var(--color-background)] p-4 text-sm text-[var(--color-text-secondary)]">No issued invoices with an outstanding balance match this payer and currency.</p> : <div className="mt-4 space-y-3">{invoices.map((invoice) => <div key={invoice.id} className="grid gap-3 rounded-xl border p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-center" style={{ borderColor: "var(--color-border)" }}><div><p className="font-bold">{invoice.document_number}</p><p className="text-xs text-[var(--color-text-secondary)]">Issued {invoice.issue_date || "—"}{invoice.organisation ? ` · ${invoice.organisation}` : ""}</p></div><div className="text-sm"><p>Total {money(invoice.currency, invoice.total)}</p><p className="font-semibold">Balance {money(invoice.currency, invoice.outstanding_balance)}</p></div>{canManage && <label className="text-xs font-semibold">Allocation amount<input inputMode="decimal" className={`${input} mt-1`} value={amounts[invoice.id] ?? ""} onChange={(event) => { setAmounts((current) => ({ ...current, [invoice.id]: event.target.value })); setPreview(null); }} placeholder="0.00" aria-label={`Allocation amount for ${invoice.document_number}`} /></label>}</div>)}</div>}
      {canManage && invoices.length > 0 && <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end"><label className="text-sm font-semibold">Reason / reference note <span className="font-normal text-[var(--color-text-secondary)]">(optional)</span><input className={`${input} mt-1`} value={reason} onChange={(event) => setReason(event.target.value)} maxLength={1000} /></label><div className="flex flex-col gap-2 rounded-xl bg-[var(--color-background)] p-3 text-sm"><p className="max-w-sm text-xs text-[var(--color-text-secondary)]">Prepare a current server-validated preview before confirming. No financial records are written until the second step.</p><button type="button" disabled={!canPrepare} onClick={() => void prepareAllocation()} className="mt-1 min-h-11 rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white disabled:cursor-not-allowed disabled:opacity-50">{busy ? "Preparing…" : `Prepare ${proposed.length} allocation${proposed.length === 1 ? "" : "s"}`}</button></div></div>}
    </section>}
    {receipt && <section className={panel}><h2 className="text-lg font-bold">Allocation history</h2>{history.length === 0 ? <p className="mt-3 text-sm text-[var(--color-text-secondary)]">No allocations have been recorded for this receipt.</p> : <div className="mt-3 space-y-3">{history.map((operation) => <article key={operation.operation_reference} className="rounded-xl border p-3" style={{ borderColor: "var(--color-border)" }}><div className="flex flex-wrap justify-between gap-2"><p className="font-semibold">Operation {operation.operation_reference}</p><p className="text-xs text-[var(--color-text-secondary)]">{operation.allocated_by || "Unknown / unavailable"} · {new Date(operation.allocated_at).toLocaleString()}</p></div><ul className="mt-2 space-y-2 text-sm">{operation.allocations.map((line) => <li key={line.id} className="flex flex-wrap items-center justify-between gap-2 border-t pt-2" style={{ borderColor: "var(--color-border)" }}><span>{line.document_number} · {money(line.currency, line.amount)} · {line.reversal ? "Reversed" : "Active"}{line.reversal && <span className="block text-xs text-[var(--color-text-secondary)]">Reversed by {line.reversal.reversed_by || "Unknown / unavailable"} on {new Date(line.reversal.reversed_at).toLocaleString()}: {line.reversal.reason}</span>}</span>{!line.reversal && canReverse && <button type="button" className="min-h-9 rounded-lg border px-3 text-xs font-bold" style={{ borderColor: "var(--color-border)" }} onClick={() => { setReverseTarget({ allocationId: line.id, documentNumber: line.document_number, amount: line.amount, operationReference: operation.operation_reference }); setReverseReason(""); setConfirmReverse(false); }}>Reverse allocation</button>}</li>)}</ul></article>)}</div>}</section>}
    {preview && receipt && <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 sm:items-center sm:p-4" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) setPreview(null); }}><section role="dialog" aria-modal="true" aria-labelledby="allocation-preview-title" className="max-h-[92dvh] w-full overflow-y-auto rounded-t-2xl border bg-[var(--color-surface)] p-4 text-[var(--color-text)] shadow-2xl sm:max-w-3xl sm:rounded-2xl sm:p-6" style={{ borderColor: "var(--color-border)" }}><h2 id="allocation-preview-title" className="text-xl font-black">Review allocation</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Confirming creates financial allocation records and updates invoice payment balances.</p><div className="mt-4 grid gap-3 rounded-xl bg-[var(--color-background)] p-4 text-sm sm:grid-cols-2"><p>Receipt: <strong>{preview.payment.transaction_reference}</strong></p><p>Payer: <strong>{preview.payment.payer || "—"}</strong></p><p>Currency: <strong>{preview.payment.currency}</strong></p><p>Original amount: <strong>{money(preview.payment.currency, preview.payment.original_amount)}</strong></p><p>Previously allocated: <strong>{money(preview.payment.currency, preview.payment.allocated_amount)}</strong></p><p>Available before: <strong>{money(preview.payment.currency, preview.payment.available_amount)}</strong></p></div><div className="mt-4 space-y-3">{preview.invoices.map((item) => <article key={item.commercial_document_id} className="rounded-xl border p-3 text-sm" style={{ borderColor: "var(--color-border)" }}><div className="flex flex-wrap justify-between gap-2"><strong>{item.document_number}</strong><strong>{money(item.currency, item.proposed_amount)}</strong></div><p className="mt-1 text-[var(--color-text-secondary)]">Recipient: {item.organisation || item.customer || "—"} · Invoice total {money(item.currency, item.invoice_total)} · Previously allocated {money(item.currency, item.allocated_amount)}</p><p className="mt-1">Resulting balance <strong>{money(item.currency, item.resulting_balance)}</strong></p></article>)}</div><div className="mt-4 flex flex-wrap justify-between gap-3 rounded-xl bg-[var(--color-background)] p-4 text-sm"><p>Total to allocate: <strong>{money(preview.payment.currency, preview.proposed_total)}</strong></p><p>Receipt remaining: <strong>{money(preview.payment.currency, preview.remaining_unallocated)}</strong></p></div><div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" disabled={busy} onClick={() => setPreview(null)} className="min-h-11 rounded-xl border px-4 font-semibold" style={{ borderColor: "var(--color-border)" }}>Cancel</button><button type="button" disabled={busy} onClick={() => void confirmAllocation()} className="min-h-11 rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white disabled:opacity-50">{busy ? "Recording…" : "Confirm Allocation"}</button></div></section></div>}
    {reverseTarget && <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 sm:items-center sm:p-4"><section role="dialog" aria-modal="true" aria-labelledby="allocation-reversal-title" className="w-full rounded-t-2xl border bg-[var(--color-surface)] p-4 text-[var(--color-text)] shadow-2xl sm:max-w-xl sm:rounded-2xl sm:p-6" style={{ borderColor: "var(--color-border)" }}><h2 id="allocation-reversal-title" className="text-xl font-black">{confirmReverse ? "Confirm allocation reversal" : "Reverse allocation"}</h2>{!confirmReverse ? <><p className="mt-2 text-sm text-[var(--color-text-secondary)]">{reverseTarget.documentNumber} · {money(receipt?.currency || "", reverseTarget.amount)}</p><label className="mt-4 block text-sm font-semibold">Reason (required)<textarea className={`${input} mt-1 min-h-24 py-3`} value={reverseReason} onChange={(event) => setReverseReason(event.target.value)} maxLength={1000} required /></label><div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" onClick={() => setReverseTarget(null)} className="min-h-11 rounded-xl border px-4 font-semibold" style={{ borderColor: "var(--color-border)" }}>Cancel</button><button type="button" disabled={reverseReason.trim().length < 3} onClick={() => setConfirmReverse(true)} className="min-h-11 rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white disabled:opacity-50">Review reversal</button></div></> : <><p className="mt-3 rounded-xl bg-[var(--color-background)] p-4 text-sm">This is a reversal, not a refund. It preserves the original allocation, increases the invoice outstanding balance, and restores the same amount to the receipt’s unallocated funds.</p><p className="mt-3 text-sm"><strong>Reason:</strong> {reverseReason}</p><div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" disabled={busy} onClick={() => setConfirmReverse(false)} className="min-h-11 rounded-xl border px-4 font-semibold" style={{ borderColor: "var(--color-border)" }}>Back</button><button type="button" disabled={busy} onClick={() => void confirmReversal()} className="min-h-11 rounded-xl bg-[var(--color-danger)] px-4 font-bold text-white disabled:opacity-50">{busy ? "Reversing…" : "Confirm Reversal"}</button></div></>}</section></div>}
  </div>;
}
