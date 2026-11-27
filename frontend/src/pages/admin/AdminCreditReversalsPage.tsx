import { useCallback, useEffect, useState } from "react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";

type Payer = { type: string; id: number; name: string };
type EligibleEntry = {
  ledger_entry_id: number; entry_type: string; operation_reference: string; payer: Payer;
  amount: string; currency: string; remaining_reversible: string; eligible: boolean;
  source_reference: string | null; document_number: string | null; created_at: string;
};
type ReversalRequest = {
  id: number; operation_reference: string; status: string; amount: string; currency: string; reason: string;
  payer: Payer; original_entry: { id: number; reference: string; entry_type: string; amount: string } | null;
  requested_by: string | null; requested_at: string; approved_by: string | null; approved_at: string | null;
  rejected_at: string | null; rejection_reason: string | null; reversal_ledger_entry_id: number | null;
};
type Page<T> = { data: T[] };

export default function AdminCreditReversalsPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.roles?.includes("super-admin") ?? false;
  const permissions = user?.permissions ?? [];
  const canView = isSuperAdmin || permissions.some(p => ["financials.credit_reversals.view", "financials.credit_reversals.initiate", "financials.credit_reversals.approve"].includes(p));
  const canInitiate = isSuperAdmin || permissions.includes("financials.credit_reversals.initiate");
  const canApprove = isSuperAdmin || permissions.includes("financials.credit_reversals.approve");

  const [entries, setEntries] = useState<EligibleEntry[]>([]);
  const [requests, setRequests] = useState<ReversalRequest[]>([]);
  const [entryId, setEntryId] = useState<number | null>(null);
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    const [entryResponse, requestResponse] = await Promise.all([
      canInitiate ? api.get<Page<EligibleEntry>>("/admin/financials/credit-reversals/eligible", { params: { per_page: 50 } }) : Promise.resolve({ data: { data: [] as EligibleEntry[] } }),
      canView ? api.get<Page<ReversalRequest>>("/admin/financials/credit-reversals", { params: { per_page: 50 } }) : Promise.resolve({ data: { data: [] as ReversalRequest[] } }),
    ]);
    setEntries(entryResponse.data.data);
    setRequests(requestResponse.data.data);
  }, [canView, canInitiate]);

  useEffect(() => { void refresh().catch(() => setError("Credit reversal information could not be loaded.")); }, [refresh]);

  async function run(action: () => Promise<void>, success: string) {
    setBusy(true); setError(""); setMessage("");
    try { await action(); setMessage(success); await refresh(); }
    catch (cause) {
      const response = (cause as { response?: { data?: { message?: string } } }).response;
      setError(response?.data?.message ?? "The credit reversal operation was rejected.");
    } finally { setBusy(false); }
  }

  const initiate = () => {
    if (!entryId) { setError("Select a credit entry to reverse."); return; }
    if (!window.confirm(`Request a reversal of ${amount}? Only unconsumed credit can be reversed; a different staff member must approve it.`)) return;
    void run(async () => {
      await api.post("/admin/financials/credit-reversals", {
        ledger_entry_id: entryId, amount, reason, operation_reference: `credit-reversal-${crypto.randomUUID()}`,
      });
      setEntryId(null); setAmount(""); setReason("");
    }, "Credit reversal requested. It now needs a different approver.");
  };

  const approve = (request: ReversalRequest) => {
    if (!window.confirm("Approve this reversal? A new compensating ledger entry will be posted.")) return;
    void run(async () => { await api.post(`/admin/financials/credit-reversals/${request.id}/approve`); }, "Credit reversal approved and posted.");
  };

  const reject = (request: ReversalRequest) => {
    const entered = window.prompt("Reason for rejecting this reversal:"); if (!entered) return;
    void run(async () => { await api.post(`/admin/financials/credit-reversals/${request.id}/reject`, { reason: entered }); }, "Credit reversal rejected.");
  };

  const controlClass = "mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 text-sm";
  const selectedEntry = entries.find(entry => entry.ledger_entry_id === entryId);

  if (!canView) return <p className="p-6 text-sm text-[var(--color-text-secondary)]">You do not have permission to view credit reversals.</p>;

  return <div className="mx-auto max-w-7xl space-y-6">
    <header>
      <p className="text-xs font-bold uppercase tracking-[.18em] text-[var(--color-accent)]">Stage 4C · Finance</p>
      <h1 className="mt-1 text-2xl font-black">Customer credit reversals</h1>
      <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Reversals are new append-only ledger entries that reference the original credit. Consumed credit cannot be reversed.</p>
    </header>
    {message && <p role="status" className="rounded-xl bg-[var(--color-success)]/10 p-3 text-sm">{message}</p>}
    {error && <p role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}

    {canInitiate && <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
      <h2 className="text-lg font-bold">Request a reversal</h2>
      <div className="mt-3 grid gap-3 md:grid-cols-3">
        <label className="text-sm">Credit entry
          <select className={controlClass} value={entryId ?? ""} onChange={event => { const id = Number(event.target.value) || null; setEntryId(id); const entry = entries.find(item => item.ledger_entry_id === id); setAmount(entry?.remaining_reversible ?? ""); }}>
            <option value="">Select reversible credit…</option>
            {entries.filter(entry => entry.eligible).map(entry => <option key={entry.ledger_entry_id} value={entry.ledger_entry_id}>{entry.operation_reference} · {entry.payer.name} · {entry.currency} {entry.remaining_reversible} reversible</option>)}
          </select>
        </label>
        <label className="text-sm">Amount {selectedEntry ? <span className="text-[var(--color-text-secondary)]">(max {selectedEntry.currency} {selectedEntry.remaining_reversible})</span> : null}
          <input className={controlClass} inputMode="decimal" value={amount} onChange={event => setAmount(event.target.value)} />
        </label>
        <label className="text-sm">Reason
          <input className={controlClass} value={reason} onChange={event => setReason(event.target.value)} placeholder="Why is the credit being reversed?" />
        </label>
      </div>
      <button type="button" disabled={busy} onClick={initiate} className="mt-3 min-h-11 rounded-lg bg-[var(--color-accent)] px-4 font-semibold text-white disabled:opacity-50">Submit reversal request</button>
    </section>}

    <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
      <h2 className="text-lg font-bold">Reversal requests</h2>
      <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[900px] text-left text-sm">
        <thead className="text-[var(--color-text-secondary)]"><tr><th className="p-2">Reference</th><th className="p-2">Payer</th><th className="p-2">Original credit</th><th className="p-2">Amount</th><th className="p-2">Status</th><th className="p-2">Requested</th><th className="p-2">Actions</th></tr></thead>
        <tbody>{requests.map(request => <tr key={request.id} className="border-t align-top" style={{ borderColor: "var(--color-border)" }}>
          <td className="p-2 font-semibold">{request.operation_reference}</td>
          <td className="p-2">{request.payer.name}<small className="block text-[var(--color-text-secondary)]">{request.reason}</small></td>
          <td className="p-2">{request.original_entry?.reference ?? "—"}<small className="block text-[var(--color-text-secondary)]">{request.original_entry?.entry_type}</small></td>
          <td className="p-2 font-semibold">{request.currency} {request.amount}</td>
          <td className="p-2 font-semibold">{request.status}{request.rejection_reason ? <small className="block font-normal text-[var(--color-danger)]">{request.rejection_reason}</small> : null}{request.reversal_ledger_entry_id ? <small className="block font-normal text-[var(--color-text-secondary)]">ledger #{request.reversal_ledger_entry_id}</small> : null}</td>
          <td className="p-2">{request.requested_by ?? "—"}<small className="block text-[var(--color-text-secondary)]">{new Date(request.requested_at).toLocaleString()}</small></td>
          <td className="p-2"><div className="flex flex-wrap gap-1">
            {canApprove && request.status === "requested" && <>
              <button type="button" disabled={busy} onClick={() => approve(request)} className="min-h-9 rounded-lg border px-2 font-semibold" style={{ borderColor: "var(--color-border)" }}>Approve</button>
              <button type="button" disabled={busy} onClick={() => reject(request)} className="min-h-9 rounded-lg border px-2 font-semibold text-[var(--color-danger)]" style={{ borderColor: "var(--color-border)" }}>Reject</button>
            </>}
          </div></td>
        </tr>)}
        {requests.length === 0 && <tr><td colSpan={7} className="p-4 text-center text-[var(--color-text-secondary)]">No reversal requests recorded.</td></tr>}
        </tbody>
      </table></div>
    </section>
  </div>;
}
