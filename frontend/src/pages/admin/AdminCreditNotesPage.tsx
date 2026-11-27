import { useCallback, useEffect, useState } from "react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";

type Payer = { type: string; id: number; name: string };
type EligibleInvoice = { id: number; document_number: string; payer: Payer; currency: string; total: string; remaining_creditable: string };
type CreditNote = {
  id: number; document_number: string; status: string; payer: Payer; currency: string; total: string;
  originating_document: { id: number; document_number: string; total: string; status: string } | null;
  created_by: string | null; issued_by: string | null; issued_at: string | null; voided_at: string | null; created_at: string;
};
type CreditNoteDetail = CreditNote & {
  subtotal: string; discount_amount: string; tax_amount: string; notes: string | null;
  lines: { description: string; quantity: string; unit_price: string; line_total: string }[];
  credit_entry: { id: number; reference: string; amount: string; remaining_reversible: string } | null;
  applied_to_invoice: string;
  histories: { action: string; actor: string | null; created_at: string }[];
};
type Page<T> = { data: T[] };

export default function AdminCreditNotesPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.roles?.includes("super-admin") ?? false;
  const permissions = user?.permissions ?? [];
  const canView = isSuperAdmin || permissions.some(p => ["financials.credit_notes.view", "financials.credit_notes.create", "financials.credit_notes.approve"].includes(p));
  const canCreate = isSuperAdmin || permissions.includes("financials.credit_notes.create");
  const canApprove = isSuperAdmin || permissions.includes("financials.credit_notes.approve");

  const [invoices, setInvoices] = useState<EligibleInvoice[]>([]);
  const [notes, setNotes] = useState<CreditNote[]>([]);
  const [detail, setDetail] = useState<CreditNoteDetail | null>(null);
  const [invoiceId, setInvoiceId] = useState<number | null>(null);
  const [amount, setAmount] = useState("");
  const [description, setDescription] = useState("");
  const [notesText, setNotesText] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    const [noteResponse, invoiceResponse] = await Promise.all([
      canView ? api.get<Page<CreditNote>>("/admin/financials/credit-notes", { params: { per_page: 50 } }) : Promise.resolve({ data: { data: [] as CreditNote[] } }),
      canCreate ? api.get<Page<EligibleInvoice>>("/admin/financials/credit-notes/eligible-invoices", { params: { per_page: 50 } }) : Promise.resolve({ data: { data: [] as EligibleInvoice[] } }),
    ]);
    setNotes(noteResponse.data.data);
    setInvoices(invoiceResponse.data.data);
  }, [canView, canCreate]);

  useEffect(() => { void refresh().catch(() => setError("Credit note information could not be loaded.")); }, [refresh]);

  async function run(action: () => Promise<void>, success: string) {
    setBusy(true); setError(""); setMessage("");
    try { await action(); setMessage(success); await refresh(); }
    catch (cause) {
      const response = (cause as { response?: { data?: { message?: string } } }).response;
      setError(response?.data?.message ?? "The credit note operation was rejected.");
    } finally { setBusy(false); }
  }

  const createNote = () => {
    if (!invoiceId) { setError("Select the invoice to credit."); return; }
    if (!window.confirm(`Create a draft credit note for ${amount}? It will not affect balances until a different staff member issues it.`)) return;
    void run(async () => {
      await api.post("/admin/financials/credit-notes", {
        originating_document_id: invoiceId, amount, description: description || undefined, notes: notesText || undefined,
      });
      setInvoiceId(null); setAmount(""); setDescription(""); setNotesText("");
    }, "Draft credit note created.");
  };

  const openDetail = (id: number) => {
    void run(async () => { const response = await api.get<{ data: CreditNoteDetail }>(`/admin/financials/credit-notes/${id}`); setDetail(response.data.data); }, "Credit note detail loaded.");
  };

  const issue = (note: CreditNote) => {
    if (!window.confirm(`Issue credit note ${note.document_number}? This creates customer credit and may apply it to the invoice.`)) return;
    void run(async () => { await api.post(`/admin/financials/credit-notes/${note.id}/issue`); }, "Credit note issued.");
  };

  const voidNote = (note: CreditNote) => {
    const reason = window.prompt("Reason for voiding this credit note:"); if (!reason) return;
    if (!window.confirm("Void this credit note? Unused credit will be reversed.")) return;
    void run(async () => { await api.post(`/admin/financials/credit-notes/${note.id}/void`, { reason }); }, "Credit note voided.");
  };

  const controlClass = "mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 text-sm";
  const selectedInvoice = invoices.find(invoice => invoice.id === invoiceId);

  if (!canView) return <p className="p-6 text-sm text-[var(--color-text-secondary)]">You do not have permission to view credit notes.</p>;

  return <div className="mx-auto max-w-7xl space-y-6">
    <header>
      <p className="text-xs font-bold uppercase tracking-[.18em] text-[var(--color-accent)]">Stage 4C · Finance</p>
      <h1 className="mt-1 text-2xl font-black">Credit notes</h1>
      <p className="mt-1 text-sm text-[var(--color-text-secondary)]">A credit note is its own document linked to an issued invoice. Issuing creates customer credit; the applied portion reduces the invoice balance exactly once.</p>
    </header>
    {message && <p role="status" className="rounded-xl bg-[var(--color-success)]/10 p-3 text-sm">{message}</p>}
    {error && <p role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}

    {canCreate && <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
      <h2 className="text-lg font-bold">Create a draft credit note</h2>
      <div className="mt-3 grid gap-3 md:grid-cols-3">
        <label className="text-sm">Originating invoice
          <select className={controlClass} value={invoiceId ?? ""} onChange={event => { const id = Number(event.target.value) || null; setInvoiceId(id); const invoice = invoices.find(item => item.id === id); setAmount(invoice?.remaining_creditable ?? ""); }}>
            <option value="">Select an issued invoice…</option>
            {invoices.map(invoice => <option key={invoice.id} value={invoice.id}>{invoice.document_number} · {invoice.payer.name} · {invoice.currency} {invoice.remaining_creditable} creditable</option>)}
          </select>
        </label>
        <label className="text-sm">Credit amount {selectedInvoice ? <span className="text-[var(--color-text-secondary)]">(max {selectedInvoice.currency} {selectedInvoice.remaining_creditable})</span> : null}
          <input className={controlClass} inputMode="decimal" value={amount} onChange={event => setAmount(event.target.value)} />
        </label>
        <label className="text-sm">Line description
          <input className={controlClass} value={description} onChange={event => setDescription(event.target.value)} placeholder="Optional" />
        </label>
      </div>
      <label className="mt-3 block text-sm">Notes
        <input className={controlClass} value={notesText} onChange={event => setNotesText(event.target.value)} placeholder="Optional internal note" />
      </label>
      <button type="button" disabled={busy} onClick={createNote} className="mt-3 min-h-11 rounded-lg bg-[var(--color-accent)] px-4 font-semibold text-white disabled:opacity-50">Create draft</button>
    </section>}

    <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
      <h2 className="text-lg font-bold">Credit notes</h2>
      <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[860px] text-left text-sm">
        <thead className="text-[var(--color-text-secondary)]"><tr><th className="p-2">Credit note</th><th className="p-2">Payer</th><th className="p-2">Originating invoice</th><th className="p-2">Amount</th><th className="p-2">Status</th><th className="p-2">Actions</th></tr></thead>
        <tbody>{notes.map(note => <tr key={note.id} className="border-t align-top" style={{ borderColor: "var(--color-border)" }}>
          <td className="p-2 font-semibold">{note.document_number}<small className="block font-normal text-[var(--color-text-secondary)]">{note.created_by ?? "—"}</small></td>
          <td className="p-2">{note.payer.name}</td>
          <td className="p-2">{note.originating_document?.document_number ?? "—"}</td>
          <td className="p-2 font-semibold">{note.currency} {note.total}</td>
          <td className="p-2 font-semibold">{note.status}{note.voided_at ? <small className="block font-normal text-[var(--color-text-secondary)]">voided {new Date(note.voided_at).toLocaleDateString()}</small> : null}</td>
          <td className="p-2"><div className="flex flex-wrap gap-1">
            <button type="button" onClick={() => openDetail(note.id)} className="min-h-9 rounded-lg border px-2 font-semibold" style={{ borderColor: "var(--color-border)" }}>View</button>
            {canApprove && note.status === "draft" && <button type="button" disabled={busy} onClick={() => issue(note)} className="min-h-9 rounded-lg border px-2 font-semibold" style={{ borderColor: "var(--color-border)" }}>Issue</button>}
            {canApprove && note.status === "issued" && <button type="button" disabled={busy} onClick={() => voidNote(note)} className="min-h-9 rounded-lg border px-2 font-semibold text-[var(--color-danger)]" style={{ borderColor: "var(--color-border)" }}>Void</button>}
          </div></td>
        </tr>)}
        {notes.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-[var(--color-text-secondary)]">No credit notes recorded.</td></tr>}
        </tbody>
      </table></div>
    </section>

    {detail && <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
      <div className="flex items-start justify-between gap-3"><div><h2 className="text-lg font-bold">{detail.document_number}</h2><p className="text-sm text-[var(--color-text-secondary)]">{detail.currency} {detail.total} · {detail.status} · invoice {detail.originating_document?.document_number}</p></div><button type="button" onClick={() => setDetail(null)} className="min-h-9 rounded-lg border px-3 font-semibold" style={{ borderColor: "var(--color-border)" }}>Close</button></div>
      <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[520px] text-left text-sm"><thead className="text-[var(--color-text-secondary)]"><tr><th className="p-2">Description</th><th className="p-2">Qty</th><th className="p-2">Unit price</th><th className="p-2">Line total</th></tr></thead><tbody>{detail.lines.map((line, index) => <tr key={index} className="border-t" style={{ borderColor: "var(--color-border)" }}><td className="p-2">{line.description}</td><td className="p-2">{line.quantity}</td><td className="p-2">{detail.currency} {line.unit_price}</td><td className="p-2 font-semibold">{detail.currency} {line.line_total}</td></tr>)}</tbody></table></div>
      <dl className="mt-3 grid gap-2 text-sm md:grid-cols-2">
        <div><dt className="text-[var(--color-text-secondary)]">Applied to invoice</dt><dd>{detail.currency} {detail.applied_to_invoice}</dd></div>
        <div><dt className="text-[var(--color-text-secondary)]">Remaining reversible credit</dt><dd>{detail.credit_entry ? `${detail.currency} ${detail.credit_entry.remaining_reversible}` : "—"}</dd></div>
      </dl>
      <h3 className="mt-4 font-semibold">History</h3>
      <ul className="mt-2 space-y-1 text-sm">{detail.histories.map((history, index) => <li key={index} className="border-t py-2" style={{ borderColor: "var(--color-border)" }}><span className="font-semibold">{history.action}</span> · {history.actor ?? "System"} · {new Date(history.created_at).toLocaleString()}</li>)}</ul>
    </section>}
  </div>;
}
