import { useCallback, useEffect, useMemo, useState } from "react";
import { AlertCircle, CheckCircle2, FileMinus, RefreshCw } from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { hasAnyPermission } from "@/lib/permissionAccess";

type Payer = { type: string; id: number; name: string } | null;
type CreditNoteRow = {
  id: number; credit_note_number: string | null; status: string; currency: string; total: string;
  applied_amount: string; remaining_amount: string; payer: Payer;
  original_document: { id: number; document_number: string } | null; original_document_number: string;
  created_by: string | null; approved_by: string | null; issued_by: string | null;
  issue_date: string | null; created_at: string;
};
type CreditNoteLine = {
  id: number; original_line_id: number | null; original_description: string | null; description: string;
  unit_of_measure: string; quantity: string; unit_price: string; discount_amount: string;
  line_subtotal: string; tax_rate: string | null; tax_amount: string; line_total: string;
};
type CreditNoteApplication = {
  id: number; amount: string; currency: string; operation_reference: string;
  invoice_outstanding_before: string; invoice_outstanding_after: string; applied_by: string | null; applied_at: string;
};
type CreditNoteDetail = CreditNoteRow & {
  reason: string; notes: string | null; original_document_date: string | null; subtotal: string;
  discount_amount: string; discount_description: string | null; tax_amount: string; total: string;
  remaining_creditable: string; original_document_outstanding: string;
  lines: CreditNoteLine[]; applications: CreditNoteApplication[];
  submitted_at: string | null; approved_at: string | null; issued_at: string | null;
  cancelled_at: string | null; cancellation_reason: string | null;
};
type EligibleDocument = {
  id: number; document_number: string; status: string; issue_date: string | null; currency: string;
  total: string; payer: Payer; remaining_creditable: string;
  payment_summary: { outstanding_balance: string; allocated_amount: string; credit_applied_amount: string; credit_note_reduction: string; payment_state: string };
};
type DocumentLine = {
  id: number; description: string; unit_of_measure: string; quantity: string; unit_price: string;
  discount_amount: string; line_subtotal: string; tax_rate: string | null; tax_amount: string; line_total: string;
  credited_quantity: string;
};
type DocumentContext = EligibleDocument & { lines: DocumentLine[]; credit_notes: CreditNoteRow[] };
type AuditEvent = { action: string; description: string; actor: string; created_at: string };
type Page<T> = { data: T[]; meta?: { current_page: number; last_page: number; total: number } };
type ApiData<T> = { data: T };

const panel = "rounded-2xl border bg-[var(--color-surface)] p-4 text-[var(--color-text)] sm:p-5";
const input = "min-h-11 w-full rounded-xl border bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]";
const button = "inline-flex min-h-11 items-center justify-center rounded-xl px-4 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50";
const outline = "border";
const money = (currency: string, amount: string) => `${currency} ${amount}`;
const dateTime = (value: string | null) => (value ? new Date(value).toLocaleString() : "—");
const label = (value: string) => value.replaceAll("_", " ");
const round2 = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100;
const errorMessage = (exception: unknown, fallback: string) =>
  (exception as { response?: { data?: { message?: string } } }).response?.data?.message || fallback;

function StatusBadge({ status }: { status: string }) {
  const tone = ["issued", "fully_applied"].includes(status)
    ? "bg-[var(--color-accent)]/15 text-[var(--color-accent)]"
    : status === "cancelled"
      ? "bg-[var(--color-danger)]/15 text-[var(--color-danger)]"
      : "bg-[var(--color-background)] text-[var(--color-text-secondary)]";
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold capitalize ${tone}`}>{label(status)}</span>;
}

type PendingAction = {
  note: CreditNoteDetail; action: string; title: string; description: string;
  textKind?: "reason" | "apply"; danger?: boolean;
};

export default function AdminCreditNotesPage() {
  const { user } = useAuth();
  const can = useCallback((slugs: string[]) => hasAnyPermission(user?.permissions, slugs, user?.roles), [user?.permissions, user?.roles]);
  const canView = can(["financials.credit_notes.view"]);
  const canCreate = can(["financials.credit_notes.create"]);
  const canSubmit = can(["financials.credit_notes.submit"]);
  const canApprove = can(["financials.credit_notes.approve"]);
  const canIssue = can(["financials.credit_notes.issue"]);
  const canApply = can(["financials.credit_notes.apply"]);
  const canCancel = can(["financials.credit_notes.cancel"]);

  const [notes, setNotes] = useState<CreditNoteRow[]>([]);
  const [documents, setDocuments] = useState<EligibleDocument[]>([]);
  const [statusFilter, setStatusFilter] = useState("");
  const [search, setSearch] = useState("");
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [detail, setDetail] = useState<CreditNoteDetail | null>(null);
  const [history, setHistory] = useState<AuditEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const [creating, setCreating] = useState(false);
  const [context, setContext] = useState<DocumentContext | null>(null);
  const [quantities, setQuantities] = useState<Record<number, string>>({});
  const [discounts, setDiscounts] = useState<Record<number, string>>({});
  const [reason, setReason] = useState("");
  const [notesText, setNotesText] = useState("");

  const [pending, setPending] = useState<PendingAction | null>(null);
  const [pendingText, setPendingText] = useState("");

  const loadNotes = useCallback(async () => {
    if (!canView) return;
    setLoading(true); setError("");
    try {
      const params = new URLSearchParams({ per_page: "100" });
      if (statusFilter) params.set("status", statusFilter);
      if (search.trim()) params.set("search", search.trim());
      const response = await api.get<Page<CreditNoteRow>>(`/admin/credit-notes?${params.toString()}`);
      setNotes(response.data.data);
    } catch (exception: unknown) {
      setError(errorMessage(exception, "Could not load credit notes."));
    } finally { setLoading(false); }
  }, [canView, search, statusFilter]);

  const loadDocuments = useCallback(async () => {
    if (!canCreate) return;
    try {
      const response = await api.get<Page<EligibleDocument>>("/admin/credit-notes/eligible-documents?per_page=100");
      setDocuments(response.data.data);
    } catch (exception: unknown) {
      setError(errorMessage(exception, "Could not load creditable invoices."));
    }
  }, [canCreate]);

  useEffect(() => { void loadNotes(); }, [loadNotes]);
  useEffect(() => { void loadDocuments(); }, [loadDocuments]);

  const loadDetail = useCallback(async (id: number) => {
    try {
      const [detailResponse, historyResponse] = await Promise.all([
        api.get<ApiData<CreditNoteDetail>>(`/admin/credit-notes/${id}`),
        api.get<ApiData<AuditEvent[]>>(`/admin/credit-notes/${id}/history`),
      ]);
      setDetail(detailResponse.data.data);
      setHistory(historyResponse.data.data);
    } catch (exception: unknown) {
      setDetail(null); setHistory([]);
      setError(errorMessage(exception, "Could not load this credit note."));
    }
  }, []);

  useEffect(() => {
    if (selectedId === null) { setDetail(null); setHistory([]); return; }
    void loadDetail(selectedId);
  }, [selectedId, loadDetail]);

  async function chooseDocument(documentId: number) {
    setError(""); setNotice("");
    setQuantities({}); setDiscounts({}); setReason(""); setNotesText("");
    try {
      const response = await api.get<ApiData<DocumentContext>>(`/admin/credit-notes/eligible-documents/${documentId}`);
      setContext(response.data.data);
    } catch (exception: unknown) {
      setContext(null);
      setError(errorMessage(exception, "Could not load this invoice."));
    }
  }

  const estimate = useMemo(() => {
    if (!context) return { subtotal: 0, tax: 0, total: 0 };
    let subtotal = 0; let tax = 0;
    for (const line of context.lines) {
      const quantity = Number(quantities[line.id] ?? 0);
      if (!Number.isFinite(quantity) || quantity <= 0) continue;
      const gross = round2(quantity * Number(line.unit_price));
      const discount = Number(discounts[line.id] ?? 0) || 0;
      const lineSubtotal = round2(gross - discount);
      const rate = line.tax_rate ? Number(line.tax_rate) : 0;
      subtotal = round2(subtotal + lineSubtotal);
      tax = round2(tax + round2(lineSubtotal * rate / 100));
    }
    return { subtotal, tax, total: round2(subtotal + tax) };
  }, [context, quantities, discounts]);

  const requestedLines = useMemo(() => {
    if (!context) return [];
    return context.lines.flatMap((line) => {
      const quantity = Number(quantities[line.id] ?? 0);
      if (!Number.isFinite(quantity) || quantity <= 0) return [];
      return [{
        commercial_document_line_id: line.id,
        quantity: quantity.toFixed(3),
        unit_price: line.unit_price,
        discount_amount: (Number(discounts[line.id] ?? 0) || 0).toFixed(2),
      }];
    });
  }, [context, quantities, discounts]);

  async function createCreditNote() {
    if (!context || busy || requestedLines.length === 0) return;
    setBusy(true); setError(""); setNotice("");
    try {
      const response = await api.post<ApiData<CreditNoteDetail>>("/admin/credit-notes", {
        commercial_document_id: context.id,
        reason: reason.trim(),
        notes: notesText.trim() || undefined,
        lines: requestedLines,
      });
      setNotice(`Credit note ${response.data.data.credit_note_number} drafted for ${money(response.data.data.currency, response.data.data.total)}. It is not effective until it is submitted, approved and issued.`);
      setCreating(false); setContext(null);
      setSelectedId(response.data.data.id);
      await Promise.all([loadNotes(), loadDocuments()]);
    } catch (exception: unknown) {
      setError(errorMessage(exception, "The credit note could not be created."));
    } finally { setBusy(false); }
  }

  async function runAction(note: CreditNoteDetail, action: string, payload: Record<string, string>) {
    setBusy(true); setError(""); setNotice("");
    try {
      const response = await api.post<ApiData<CreditNoteDetail>>(`/admin/credit-notes/${note.id}/${action}`, payload);
      setDetail(response.data.data);
      setNotice(`Credit note ${response.data.data.credit_note_number} is now ${label(response.data.data.status)}.`);
      setPending(null); setPendingText("");
      await Promise.all([loadNotes(), loadDocuments(), loadDetail(note.id)]);
    } catch (exception: unknown) {
      setError(errorMessage(exception, "The credit note action could not be completed."));
    } finally { setBusy(false); }
  }

  function askAction(note: CreditNoteDetail, action: string, title: string, description: string, options: { textKind?: "reason" | "apply"; danger?: boolean } = {}) {
    setPending({ note, action, title, description, ...options });
    setPendingText(options.textKind === "apply" ? note.remaining_amount : "");
  }

  const applyMaximum = pending?.textKind === "apply"
    ? Math.min(Number(pending.note.remaining_amount), Number(pending.note.original_document_outstanding))
    : 0;

  async function confirmPending() {
    if (!pending) return;
    const payload: Record<string, string> = pending.textKind === "reason"
      ? { reason: pendingText.trim() }
      : pending.textKind === "apply"
        ? { amount: pendingText.trim(), operation_reference: crypto.randomUUID() }
        : {};
    await runAction(pending.note, pending.action, payload);
  }

  const pendingValid = !pending?.textKind
    || (pending.textKind === "reason" && pendingText.trim().length >= 3)
    || (pending.textKind === "apply" && /^\d+(\.\d{1,2})?$/.test(pendingText.trim()) && Number(pendingText) > 0 && Number(pendingText) <= applyMaximum);

  if (!canView) {
    return <div className="p-6"><p className={`${panel} text-sm`}>You do not have permission to view credit notes.</p></div>;
  }

  return <div className="space-y-5 p-4 sm:p-6 lg:p-8">
    <header>
      <p className="text-xs font-bold uppercase tracking-wider text-[var(--color-accent)]">Commercial</p>
      <h1 className="mt-1 flex items-center gap-2 text-2xl font-black text-[var(--color-text)]"><FileMinus size={25} /> Credit notes</h1>
      <p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">A credit note reduces a previously issued invoice. It is not a refund and never posts customer credit by itself. Issued credit notes are immutable.</p>
    </header>

    {error && <p role="alert" className="flex gap-2 rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]"><AlertCircle size={18} className="shrink-0" />{error}</p>}
    {notice && <p role="status" className="flex gap-2 rounded-xl bg-[var(--color-accent)]/10 p-3 text-sm"><CheckCircle2 size={18} className="shrink-0 text-[var(--color-accent)]" />{notice}</p>}

    {canCreate && <section className={panel}>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div><h2 className="text-lg font-bold">Creditable invoices</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Issued, non-voided invoices with value that has not already been credited.</p></div>
        <div className="flex gap-2">
          <button type="button" onClick={() => void loadDocuments()} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}><RefreshCw size={16} className="mr-2" />Refresh</button>
          <button type="button" onClick={() => { setCreating(true); setContext(null); }} className={`${button} bg-[var(--color-accent)] text-white`}>New credit note</button>
        </div>
      </div>
      {creating && <div className="mt-4 grid gap-3 rounded-xl bg-[var(--color-background)] p-3 sm:grid-cols-2">
        {documents.length === 0 ? <p className="text-sm text-[var(--color-text-secondary)]">No invoice currently has a creditable balance.</p> : <label className="text-sm font-semibold sm:col-span-2">Original invoice
          <select className={`${input} mt-1`} value={context?.id ?? ""} onChange={(event) => void chooseDocument(Number(event.target.value))}>
            <option value="">Choose an invoice</option>
            {documents.map((document) => <option key={document.id} value={document.id}>{document.document_number} · {document.payer?.name || "—"} · {money(document.currency, document.remaining_creditable)} creditable</option>)}
          </select>
        </label>}
      </div>}
      {creating && context && <div className="mt-4 space-y-4">
        <div className="rounded-xl border p-3 text-sm" style={{ borderColor: "var(--color-border)" }}>
          <p className="font-bold">{context.document_number} · {context.payer?.name}</p>
          <p className="mt-1 text-[var(--color-text-secondary)]">Invoice total {money(context.currency, context.total)} · outstanding {money(context.currency, context.payment_summary.outstanding_balance)} · remaining creditable {money(context.currency, context.remaining_creditable)}</p>
        </div>
        <div className="space-y-3">
          {context.lines.map((line) => {
            const remaining = Number(line.quantity) - Number(line.credited_quantity);
            return <div key={line.id} className="grid gap-3 rounded-xl border p-3 sm:grid-cols-[1fr_auto_auto] sm:items-end" style={{ borderColor: "var(--color-border)" }}>
              <div><p className="font-semibold">{line.description}</p><p className="text-xs text-[var(--color-text-secondary)]">{money(context.currency, line.unit_price)} per {line.unit_of_measure} · {line.quantity} originally invoiced · {line.credited_quantity} already credited · tax {line.tax_rate ? `${Number(line.tax_rate)}%` : "none"}</p></div>
              <label className="text-xs font-semibold">Quantity to credit<input inputMode="decimal" className={`${input} mt-1 sm:w-32`} value={quantities[line.id] ?? ""} onChange={(event) => setQuantities((current) => ({ ...current, [line.id]: event.target.value }))} placeholder="0" aria-label={`Quantity to credit for ${line.description}`} /><span className="mt-1 block font-normal text-[var(--color-text-secondary)]">Max {remaining.toFixed(3)}</span></label>
              <label className="text-xs font-semibold">Line discount<input inputMode="decimal" className={`${input} mt-1 sm:w-32`} value={discounts[line.id] ?? ""} onChange={(event) => setDiscounts((current) => ({ ...current, [line.id]: event.target.value }))} placeholder="0.00" aria-label={`Discount for ${line.description}`} /><span className="mt-1 block font-normal text-[var(--color-text-secondary)]">Original {money(context.currency, line.discount_amount)}</span></label>
            </div>;
          })}
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm font-semibold">Reason (required)<input className={`${input} mt-1`} value={reason} onChange={(event) => setReason(event.target.value)} maxLength={2000} placeholder="Why is this invoice being credited?" /></label>
          <label className="text-sm font-semibold">Notes <span className="font-normal text-[var(--color-text-secondary)]">(optional)</span><input className={`${input} mt-1`} value={notesText} onChange={(event) => setNotesText(event.target.value)} maxLength={2000} /></label>
        </div>
        <div className="flex flex-col gap-2 rounded-xl bg-[var(--color-background)] p-3 text-sm sm:flex-row sm:items-center sm:justify-between">
          <p className="text-xs text-[var(--color-text-secondary)]">Estimated subtotal {money(context.currency, estimate.subtotal.toFixed(2))} · tax {money(context.currency, estimate.tax.toFixed(2))} · <strong>total {money(context.currency, estimate.total.toFixed(2))}</strong>. The server recalculates and validates every amount.</p>
          <div className="flex gap-2">
            <button type="button" onClick={() => { setCreating(false); setContext(null); }} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}>Cancel</button>
            <button type="button" disabled={busy || requestedLines.length === 0 || reason.trim().length < 3} onClick={() => void createCreditNote()} className={`${button} bg-[var(--color-accent)] text-white`}>{busy ? "Saving…" : "Create draft"}</button>
          </div>
        </div>
      </div>}
    </section>}

    <section className={panel}>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><h2 className="text-lg font-bold">Credit note register</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Document numbers are assigned on creation and never reused.</p></div>
        <div className="flex flex-col gap-2 sm:flex-row">
          <label className="text-sm font-semibold">Status
            <select className={`${input} mt-1`} value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
              <option value="">All statuses</option>
              {["draft", "submitted", "approved", "issued", "partially_applied", "fully_applied", "cancelled"].map((status) => <option key={status} value={status}>{label(status)}</option>)}
            </select>
          </label>
          <label className="text-sm font-semibold">Search
            <input className={`${input} mt-1`} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Number, invoice or payer" />
          </label>
        </div>
      </div>
      <div className="mt-4 overflow-x-auto">
        <table className="w-full min-w-[820px] text-left text-sm">
          <thead><tr className="border-b text-xs uppercase text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>
            <th className="py-3 pr-3">Credit note</th><th className="py-3 pr-3">Original invoice</th><th className="py-3 pr-3">Payer</th><th className="py-3 pr-3">Total</th><th className="py-3 pr-3">Applied</th><th className="py-3 pr-3">Status</th><th className="py-3">Action</th>
          </tr></thead>
          <tbody>{notes.map((note) => <tr key={note.id} className="border-b align-top" style={{ borderColor: "var(--color-border)" }}>
            <td className="py-3 pr-3"><p className="font-semibold">{note.credit_note_number || `#${note.id}`}</p><p className="text-xs text-[var(--color-text-secondary)]">{note.issue_date ? `Issued ${note.issue_date}` : `Created ${dateTime(note.created_at)}`}</p></td>
            <td className="py-3 pr-3">{note.original_document_number}</td>
            <td className="py-3 pr-3">{note.payer?.name || "—"}</td>
            <td className="py-3 pr-3 font-semibold">{money(note.currency, note.total)}</td>
            <td className="py-3 pr-3">{money(note.currency, note.applied_amount)}<p className="text-xs text-[var(--color-text-secondary)]">{money(note.currency, note.remaining_amount)} remaining</p></td>
            <td className="py-3 pr-3"><StatusBadge status={note.status} /></td>
            <td className="py-3"><button type="button" onClick={() => setSelectedId(note.id)} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}>{selectedId === note.id ? "Selected" : "View"}</button></td>
          </tr>)}</tbody>
        </table>
        {loading && <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">Loading credit notes…</p>}
        {!loading && notes.length === 0 && <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">No credit notes match this filter.</p>}
      </div>
    </section>

    {detail && <section className={panel}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div><h2 className="text-lg font-bold">{detail.credit_note_number}</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Against {detail.original_document_number} · {detail.payer?.name} · {detail.original_document_date || "—"}</p></div>
        <StatusBadge status={detail.status} />
      </div>

      <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Subtotal</dt><dd className="font-semibold">{money(detail.currency, detail.subtotal)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Tax</dt><dd className="font-semibold">{money(detail.currency, detail.tax_amount)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Discount</dt><dd className="font-semibold">{money(detail.currency, detail.discount_amount)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Total credit</dt><dd className="font-bold">{money(detail.currency, detail.total)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Applied</dt><dd className="font-semibold">{money(detail.currency, detail.applied_amount)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Remaining on note</dt><dd className="font-semibold">{money(detail.currency, detail.remaining_amount)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Invoice outstanding</dt><dd className="font-semibold">{money(detail.currency, detail.original_document_outstanding)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Remaining creditable</dt><dd className="font-semibold">{money(detail.currency, detail.remaining_creditable)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Issued</dt><dd className="font-semibold">{dateTime(detail.issued_at)}</dd></div>
      </dl>

      <div className="mt-4 rounded-xl bg-[var(--color-background)] p-3 text-sm">
        <p><strong>Reason:</strong> {detail.reason}</p>
        {detail.notes && <p className="mt-1"><strong>Notes:</strong> {detail.notes}</p>}
        {detail.cancellation_reason && <p className="mt-1 text-[var(--color-danger)]"><strong>Cancelled:</strong> {detail.cancellation_reason}</p>}
        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Created by {detail.created_by || "Unknown / unavailable"}{detail.approved_by ? ` · approved by ${detail.approved_by}` : ""}{detail.issued_by ? ` · issued by ${detail.issued_by}` : ""}</p>
      </div>

      <h3 className="mt-5 text-base font-bold">Credited lines</h3>
      <div className="mt-2 overflow-x-auto">
        <table className="w-full min-w-[680px] text-left text-sm">
          <thead><tr className="border-b text-xs uppercase text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}><th className="py-2 pr-3">Description</th><th className="py-2 pr-3">Qty</th><th className="py-2 pr-3">Unit price</th><th className="py-2 pr-3">Discount</th><th className="py-2 pr-3">Tax</th><th className="py-2">Line total</th></tr></thead>
          <tbody>{detail.lines.map((line) => <tr key={line.id} className="border-b" style={{ borderColor: "var(--color-border)" }}>
            <td className="py-2 pr-3">{line.description}{line.original_description && line.original_description !== line.description && <span className="block text-xs text-[var(--color-text-secondary)]">from {line.original_description}</span>}</td>
            <td className="py-2 pr-3">{line.quantity}</td>
            <td className="py-2 pr-3">{money(detail.currency, line.unit_price)}</td>
            <td className="py-2 pr-3">{money(detail.currency, line.discount_amount)}</td>
            <td className="py-2 pr-3">{line.tax_rate ? `${Number(line.tax_rate)}% · ${money(detail.currency, line.tax_amount)}` : "None"}</td>
            <td className="py-2 font-semibold">{money(detail.currency, line.line_total)}</td>
          </tr>)}</tbody>
        </table>
      </div>

      <div className="mt-4 flex flex-wrap gap-2">
        {detail.status === "draft" && canSubmit && <button type="button" className={`${button} bg-[var(--color-accent)] text-white`} onClick={() => askAction(detail, "submit", "Submit credit note", "Submitting makes this credit note effective against the invoice's remaining creditable amount.")}>Submit</button>}
        {detail.status === "submitted" && canApprove && <button type="button" className={`${button} bg-[var(--color-accent)] text-white`} onClick={() => askAction(detail, "approve", "Approve credit note", "Approval must come from a different user from the one who created it.")}>Approve</button>}
        {detail.status === "approved" && canIssue && <button type="button" className={`${button} bg-[var(--color-accent)] text-white`} onClick={() => askAction(detail, "issue", "Issue credit note", "Issuing makes this a formal, immutable commercial document. It can no longer be edited or cancelled.")}>Issue</button>}
        {["issued", "partially_applied"].includes(detail.status) && canApply && <button type="button" className={`${button} bg-[var(--color-accent)] text-white`} onClick={() => askAction(detail, "apply", "Apply credit note", "Applying reduces the original invoice's outstanding balance. It never posts customer credit or moves money.", { textKind: "apply" })}>Apply to invoice</button>}
        {["draft", "submitted", "approved"].includes(detail.status) && canCancel && <button type="button" className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }} onClick={() => askAction(detail, "cancel", "Cancel credit note", "Cancelling releases the credited amount. An issued credit note cannot be cancelled.", { textKind: "reason" })}>Cancel</button>}
      </div>

      <h3 className="mt-6 text-base font-bold">Applications</h3>
      {detail.applications.length === 0 ? <p className="mt-2 text-sm text-[var(--color-text-secondary)]">This credit note has not been applied to its invoice.</p> : <ul className="mt-2 space-y-2">{detail.applications.map((application) => <li key={application.id} className="rounded-xl border p-3 text-sm" style={{ borderColor: "var(--color-border)" }}><div className="flex flex-wrap justify-between gap-2"><p className="font-semibold">{money(application.currency, application.amount)}</p><p className="text-xs text-[var(--color-text-secondary)]">{application.applied_by || "Unknown / unavailable"} · {dateTime(application.applied_at)}</p></div><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Invoice outstanding {money(application.currency, application.invoice_outstanding_before)} → {money(application.currency, application.invoice_outstanding_after)} · reference {application.operation_reference}</p></li>)}</ul>}

      <h3 className="mt-6 text-base font-bold">Audit history</h3>
      {history.length === 0 ? <p className="mt-2 text-sm text-[var(--color-text-secondary)]">No audit events recorded yet.</p> : <ol className="mt-2 space-y-2">{history.map((event, index) => <li key={`${event.action}-${index}`} className="rounded-xl border p-3 text-sm" style={{ borderColor: "var(--color-border)" }}><div className="flex flex-wrap justify-between gap-2"><p className="font-semibold capitalize">{label(event.action)}</p><p className="text-xs text-[var(--color-text-secondary)]">{event.actor} · {dateTime(event.created_at)}</p></div><p className="mt-1 text-[var(--color-text-secondary)]">{event.description}</p></li>)}</ol>}
    </section>}

    {pending && <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 sm:items-center sm:p-4" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) setPending(null); }}>
      <section role="dialog" aria-modal="true" aria-labelledby="credit-note-action-title" className="w-full rounded-t-2xl border bg-[var(--color-surface)] p-4 text-[var(--color-text)] shadow-2xl sm:max-w-xl sm:rounded-2xl sm:p-6" style={{ borderColor: "var(--color-border)" }}>
        <h2 id="credit-note-action-title" className="text-xl font-black">{pending.title}</h2>
        <p className="mt-2 text-sm text-[var(--color-text-secondary)]">{pending.description}</p>
        <p className="mt-3 rounded-xl bg-[var(--color-background)] p-3 text-sm"><strong>{pending.note.credit_note_number}</strong> · {money(pending.note.currency, pending.note.total)}</p>
        {pending.textKind === "reason" && <label className="mt-4 block text-sm font-semibold">Reason (required)<textarea className={`${input} mt-1 min-h-24 py-3`} value={pendingText} onChange={(event) => setPendingText(event.target.value)} maxLength={2000} required /></label>}
        {pending.textKind === "apply" && <label className="mt-4 block text-sm font-semibold">Amount to apply ({pending.note.currency})
          <input inputMode="decimal" className={`${input} mt-1`} value={pendingText} onChange={(event) => setPendingText(event.target.value)} placeholder="0.00" />
          <span className="mt-1 block text-xs font-normal text-[var(--color-text-secondary)]">Maximum {money(pending.note.currency, applyMaximum.toFixed(2))} (note remaining {money(pending.note.currency, pending.note.remaining_amount)} · invoice outstanding {money(pending.note.currency, pending.note.original_document_outstanding)})</span>
        </label>}
        <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" disabled={busy} onClick={() => setPending(null)} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}>Cancel</button>
          <button type="button" disabled={busy || !pendingValid} onClick={() => void confirmPending()} className={`${button} ${pending.danger ? "bg-[var(--color-danger)]" : "bg-[var(--color-accent)]"} text-white`}>{busy ? "Working…" : "Confirm"}</button>
        </div>
      </section>
    </div>}
  </div>;
}
