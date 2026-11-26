import { useCallback, useEffect, useState } from "react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";

type Receipt = { id: number; reference: string; payer: { type: string; id: number; name: string }; amount: string; currency: string; intent: "deposit" | "advance" | null; reason: string | null; unallocated_amount: string; verified_at: string; received_at: string };
type Invoice = { id: number; document_number: string; payer: { type: string; id: number; name: string }; currency: string; total: string; payment_summary: { outstanding_balance: string; credit_applied_amount: string } };
type Balance = { payer: { type: string; id: number; name: string }; currency: string; available_balance: string };
type LedgerEntry = { id: number; type: string; amount: string; currency: string; reference: string; reason: string; source_reference: string | null; invoice_number: string | null; actor: string; created_at: string; credit_balance_after: string };
type Response<T> = { data: T[] };

const newReference = (prefix: string) => `${prefix}-${crypto.randomUUID()}`;

export default function AdminCustomerCreditPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.roles?.includes("super-admin") ?? false;
  const permissions = user?.permissions ?? [];
  const canViewDeposits = isSuperAdmin || permissions.includes("financials.deposits.view") || permissions.includes("financials.deposits.manage");
  const canManageDeposits = isSuperAdmin || permissions.includes("financials.deposits.manage");
  const canViewCredit = isSuperAdmin || permissions.includes("financials.customer_credit.view") || permissions.includes("financials.customer_credit.manage");
  const canManageCredit = isSuperAdmin || permissions.includes("financials.customer_credit.manage");
  const [receipts, setReceipts] = useState<Receipt[]>([]);
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [balances, setBalances] = useState<Balance[]>([]);
  const [ledger, setLedger] = useState<LedgerEntry[]>([]);
  const [selectedReceipt, setSelectedReceipt] = useState<number | null>(null);
  const [intent, setIntent] = useState<"deposit" | "advance">("advance");
  const [receiptReason, setReceiptReason] = useState("");
  const [designationAmount, setDesignationAmount] = useState("");
  const [designationReason, setDesignationReason] = useState("");
  const [selectedInvoice, setSelectedInvoice] = useState<number | null>(null);
  const [applicationAmount, setApplicationAmount] = useState("");
  const [applicationReason, setApplicationReason] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    const [r, i, b, l] = await Promise.all([
      canViewDeposits ? api.get<Response<Receipt>>("/admin/customer-credit/receipts") : Promise.resolve({ data: { data: [] as Receipt[] } }),
      canViewCredit ? api.get<Response<Invoice>>("/admin/customer-credit/invoices") : Promise.resolve({ data: { data: [] as Invoice[] } }),
      canViewCredit ? api.get<Response<Balance>>("/admin/customer-credit/balances") : Promise.resolve({ data: { data: [] as Balance[] } }),
      canViewCredit ? api.get<Response<LedgerEntry>>("/admin/customer-credit/ledger", { params: { per_page: 50 } }) : Promise.resolve({ data: { data: [] as LedgerEntry[] } }),
    ]);
    setReceipts(r.data.data); setInvoices(i.data.data); setBalances(b.data.data); setLedger(l.data.data);
  }, [canViewCredit, canViewDeposits]);

  useEffect(() => { void refresh().catch(() => setError("Credit and deposit information could not be loaded.")); }, [refresh]);

  async function mutate(path: string, payload: Record<string, unknown>, confirmation: string) {
    if (!window.confirm(confirmation)) return;
    setBusy(true); setError(""); setMessage("");
    try {
      const response = await api.post<{ data?: { entry_type?: string; amount?: string; currency?: string; payer?: { name: string }; payment_unallocated_before?: string; payment_unallocated_after?: string; credit_balance_before?: string; credit_balance_after?: string; invoice_outstanding_before?: string; invoice_outstanding_after?: string } }>(path, payload);
      const result = response.data.data;
      if (result?.entry_type === "designation") setMessage(`Credit recorded for ${result.payer?.name ?? "payer"}: ${result.currency} ${result.amount}. Receipt available balance ${result.payment_unallocated_before} → ${result.payment_unallocated_after}; credit balance ${result.credit_balance_before} → ${result.credit_balance_after}.`);
      else if (result?.entry_type === "application") setMessage(`Credit applied for ${result.payer?.name ?? "payer"}: ${result.currency} ${result.amount}. Available credit ${result.credit_balance_before} → ${result.credit_balance_after}; invoice outstanding ${result.invoice_outstanding_before} → ${result.invoice_outstanding_after}.`);
      else setMessage("Financial operation recorded.");
      await refresh();
    }
    catch (cause) {
      const response = (cause as { response?: { data?: { message?: string } } }).response;
      setError(response?.data?.message ?? "The operation was rejected. Review the current balances and try again.");
    } finally { setBusy(false); }
  }

  const receipt = receipts.find(item => item.id === selectedReceipt);
  const invoice = invoices.find(item => item.id === selectedInvoice);
  const controlClass = "mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 text-sm";

  return <div className="mx-auto max-w-7xl space-y-6">
    <header><p className="text-xs font-bold uppercase tracking-[.18em] text-[var(--color-accent)]">Stage 4B · Finance</p><h1 className="mt-1 text-2xl font-black">Deposits and customer credit</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Only verified, unallocated invoice receipts are eligible. Every designation and application is recorded in the credit ledger.</p></header>
    {message && <p role="status" className="rounded-xl bg-[var(--color-success)]/10 p-3 text-sm">{message}</p>}{error && <p role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}

    {canViewDeposits && <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
      <h2 className="text-lg font-bold">Eligible unallocated receipts</h2>
      <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="text-[var(--color-text-secondary)]"><tr><th className="p-2">Receipt</th><th className="p-2">Payer</th><th className="p-2">Intent</th><th className="p-2">Available</th><th className="p-2">Verified</th><th className="p-2"></th></tr></thead><tbody>
        {receipts.map(item => <tr key={item.id} className="border-t" style={{ borderColor: "var(--color-border)" }}><td className="p-2 font-semibold">{item.reference}<small className="block font-normal text-[var(--color-text-secondary)]">{item.currency} {item.amount}</small></td><td className="p-2">{item.payer.name}<small className="block text-[var(--color-text-secondary)]">{item.payer.type}</small></td><td className="p-2">{item.intent ? `${item.intent}: ${item.reason}` : "Not classified"}</td><td className="p-2 font-semibold">{item.currency} {item.unallocated_amount}</td><td className="p-2">{new Date(item.verified_at).toLocaleString()}</td><td className="p-2">{canManageDeposits && <button type="button" onClick={() => { setSelectedReceipt(item.id); setDesignationAmount(item.unallocated_amount); }} className="min-h-9 rounded-lg border px-3 font-semibold" style={{ borderColor: "var(--color-border)" }}>Select</button>}</td></tr>)}
        {receipts.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-[var(--color-text-secondary)]">No eligible unallocated receipts.</td></tr>}
      </tbody></table></div>
    </section>}

    {receipt && canManageDeposits && <section className="grid gap-5 rounded-2xl border bg-[var(--color-surface)] p-5 lg:grid-cols-2" style={{ borderColor: "var(--color-border)" }}>
      <div><h2 className="text-lg font-bold">Classify receipt intent</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">{receipt.reference} · {receipt.payer.name} · {receipt.currency} {receipt.unallocated_amount} available</p>
        {!receipt.intent ? <div className="mt-3 space-y-3"><label className="block text-sm font-semibold">Receipt intent<select value={intent} onChange={e => setIntent(e.target.value as "deposit" | "advance")} className={controlClass}><option value="advance">Advance payment</option><option value="deposit">Deposit</option></select></label><label className="block text-sm font-semibold">Reason or business reference<input value={receiptReason} onChange={e => setReceiptReason(e.target.value)} className={controlClass} maxLength={1000} /></label><button type="button" disabled={busy || receiptReason.trim().length < 3} onClick={() => void mutate(`/admin/customer-credit/receipts/${receipt.id}/intent`, { intent, operation_reference: newReference("receipt-intent"), reason: receiptReason }, `Classify ${receipt.reference} as ${intent} for ${receipt.payer.name}?`)} className="min-h-10 rounded-lg bg-[var(--color-accent)] px-4 font-semibold text-white disabled:opacity-50">Confirm intent</button></div> : <p className="mt-3 text-sm">Already classified as <strong>{receipt.intent}</strong>. This classification is retained in the audit history.</p>}
      </div>
      {canManageCredit && <div><h2 className="text-lg font-bold">Designate credit</h2>{receipt.intent ? <div className="mt-3 space-y-3"><label className="block text-sm font-semibold">Amount ({receipt.currency})<input type="number" inputMode="decimal" min="0.01" max={receipt.unallocated_amount} step="0.01" value={designationAmount} onChange={e => setDesignationAmount(e.target.value)} className={controlClass} /></label><label className="block text-sm font-semibold">Reason<input value={designationReason} onChange={e => setDesignationReason(e.target.value)} className={controlClass} maxLength={1000} /></label><button type="button" disabled={busy || Number(designationAmount) <= 0 || designationReason.trim().length < 3} onClick={() => void mutate("/admin/customer-credit/designations", { payment_transaction_id: receipt.id, amount: designationAmount, operation_reference: newReference("credit-designation"), reason: designationReason }, `Designate ${receipt.currency} ${designationAmount} from ${receipt.reference} as credit owned by ${receipt.payer.name}?`)} className="min-h-10 rounded-lg bg-[var(--color-accent)] px-4 font-semibold text-white disabled:opacity-50">Confirm credit designation</button></div> : <p className="mt-3 text-sm text-[var(--color-text-secondary)]">Classify the receipt first. Credit belongs to the receipt payer.</p>}</div>}
    </section>}

    {canViewCredit && <section className="grid gap-6 lg:grid-cols-2">
      <div className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}><h2 className="text-lg font-bold">Available balances</h2><div className="mt-3 space-y-2">{balances.map((item) => <div key={`${item.payer.type}-${item.payer.id}-${item.currency}`} className="flex justify-between gap-3 rounded-lg border p-3 text-sm" style={{ borderColor: "var(--color-border)" }}><span>{item.payer.name}<small className="block text-[var(--color-text-secondary)]">{item.payer.type}</small></span><strong>{item.currency} {item.available_balance}</strong></div>)}{balances.length === 0 && <p className="text-sm text-[var(--color-text-secondary)]">No customer credit balances.</p>}</div></div>
      {canManageCredit && <div className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}><h2 className="text-lg font-bold">Apply credit to an issued invoice</h2><div className="mt-3 space-y-3"><label className="block text-sm font-semibold">Invoice<select value={selectedInvoice ?? ""} onChange={e => setSelectedInvoice(Number(e.target.value) || null)} className={controlClass}><option value="">Choose invoice</option>{invoices.map(item => <option key={item.id} value={item.id}>{item.document_number} · {item.payer.name} · {item.currency} {item.payment_summary.outstanding_balance} outstanding</option>)}</select></label><label className="block text-sm font-semibold">Amount ({invoice?.currency ?? "currency"})<input type="number" inputMode="decimal" min="0.01" max={invoice?.payment_summary.outstanding_balance} step="0.01" value={applicationAmount} onChange={e => setApplicationAmount(e.target.value)} className={controlClass} /></label><label className="block text-sm font-semibold">Reason<input value={applicationReason} onChange={e => setApplicationReason(e.target.value)} className={controlClass} maxLength={1000} /></label><button type="button" disabled={busy || !invoice || Number(applicationAmount) <= 0 || applicationReason.trim().length < 3} onClick={() => invoice && void mutate("/admin/customer-credit/applications", { commercial_document_id: invoice.id, amount: applicationAmount, operation_reference: newReference("credit-application"), reason: applicationReason }, `Apply ${invoice.currency} ${applicationAmount} from ${invoice.payer.name}'s credit to invoice ${invoice.document_number}?`)} className="min-h-10 rounded-lg bg-[var(--color-accent)] px-4 font-semibold text-white disabled:opacity-50">Confirm application</button></div></div>}
    </section>}

    {canViewCredit && <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}><h2 className="text-lg font-bold">Credit ledger history</h2><div className="mt-3 overflow-x-auto"><table className="w-full min-w-[700px] text-left text-sm"><thead className="text-[var(--color-text-secondary)]"><tr><th className="p-2">Date</th><th className="p-2">Operation</th><th className="p-2">Reference</th><th className="p-2">Reason</th><th className="p-2">Actor</th><th className="p-2">Balance after</th></tr></thead><tbody>{ledger.map(entry => <tr key={entry.id} className="border-t" style={{ borderColor: "var(--color-border)" }}><td className="p-2">{new Date(entry.created_at).toLocaleString()}</td><td className="p-2">{entry.type === "designation" ? "Credit designated" : "Credit applied"} · {entry.currency} {entry.amount}</td><td className="p-2">{entry.source_reference ?? entry.invoice_number ?? entry.reference}</td><td className="p-2">{entry.reason}</td><td className="p-2">{entry.actor}</td><td className="p-2 font-semibold">{entry.currency} {entry.credit_balance_after}</td></tr>)}{ledger.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-[var(--color-text-secondary)]">No ledger entries.</td></tr>}</tbody></table></div></section>}
  </div>;
}
