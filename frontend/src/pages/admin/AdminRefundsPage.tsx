import { useCallback, useEffect, useState } from "react";
import { AlertCircle, CheckCircle2, RefreshCw, Undo2, WalletCards } from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { hasAnyPermission } from "@/lib/permissionAccess";

type Payer = { type: string; id: number; name: string } | null;
type RefundRow = {
  id: number; refund_reference: string | null; status: string; refund_method: string; amount: string; currency: string;
  payer: Payer; payment: { id: number; transaction_reference: string; provider: string | null; payment_method: string } | null;
  order: { id: number; order_number: string } | null;
  commercial_document: { id: number; document_number: string } | null;
  initiator: string | null; approver: string | null;
};
type RefundDetail = RefundRow & {
  reason: string; notes: string | null; allocation: { id: number; document_number: string | null; amount: string } | null;
  reviewed_at: string | null; approved_at: string | null; processing_at: string | null; completed_at: string | null;
  failed_at: string | null; cancelled_at: string | null; reversed_at: string | null;
  rejection_reason: string | null; failure_reason: string | null; cancellation_reason: string | null; reversal_reason: string | null;
  gateway_reference: string | null; gateway_status: string | null; external_transaction_reference: string | null;
  gateway_provider: string | null; automated_gateway_refund_available: boolean; refundable_balance: string;
  credit_ledger_entry: { id: number; amount: string; currency: string; credit_balance_after: string; created_at: string } | null;
  created_at: string; requested_at: string;
};
type EligiblePayment = {
  id: number; transaction_reference: string; provider: string | null; payment_method: string; amount: string; currency: string;
  status: string; received_at: string | null; payer: Payer; refundable_balance: string; unallocated_amount: string;
  order: { id: number; order_number: string } | null;
};
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
const METHOD_LABELS: Record<string, string> = {
  original_payment_method: "Original payment method",
  customer_credit: "Customer account credit",
  manual: "Manual (cash / bank transfer)",
};
const errorMessage = (exception: unknown, fallback: string) =>
  (exception as { response?: { data?: { message?: string } } }).response?.data?.message || fallback;

function StatusBadge({ status }: { status: string }) {
  const tone = ["completed"].includes(status)
    ? "bg-[var(--color-accent)]/15 text-[var(--color-accent)]"
    : ["failed", "rejected"].includes(status)
      ? "bg-[var(--color-danger)]/15 text-[var(--color-danger)]"
      : "bg-[var(--color-background)] text-[var(--color-text-secondary)]";
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold capitalize ${tone}`}>{label(status)}</span>;
}

type PendingAction = {
  refund: RefundDetail; action: string; title: string; description: string;
  textKind?: "reason" | "evidence"; danger?: boolean;
};

export default function AdminRefundsPage() {
  const { user } = useAuth();
  const can = useCallback((slugs: string[]) => hasAnyPermission(user?.permissions, slugs, user?.roles), [user?.permissions, user?.roles]);
  const canView = can(["financials.refunds.view"]);
  const canCreate = can(["financials.refunds.create"]);
  const canSubmit = can(["financials.refunds.submit"]);
  const canReview = can(["financials.refunds.review"]);
  const canApprove = can(["financials.refunds.approve"]);
  const canProcess = can(["financials.refunds.process"]);
  const canComplete = can(["financials.refunds.complete"]);
  const canCancel = can(["financials.refunds.cancel"]);
  const canReverse = can(["financials.refunds.reverse"]);

  const [refunds, setRefunds] = useState<RefundRow[]>([]);
  const [payments, setPayments] = useState<EligiblePayment[]>([]);
  const [statusFilter, setStatusFilter] = useState("");
  const [search, setSearch] = useState("");
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [detail, setDetail] = useState<RefundDetail | null>(null);
  const [history, setHistory] = useState<AuditEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const [createFor, setCreateFor] = useState<EligiblePayment | null>(null);
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState("original_payment_method");
  const [reason, setReason] = useState("");
  const [notes, setNotes] = useState("");

  const [pending, setPending] = useState<PendingAction | null>(null);
  const [pendingText, setPendingText] = useState("");

  const loadRefunds = useCallback(async () => {
    if (!canView) return;
    setLoading(true); setError("");
    try {
      const params = new URLSearchParams({ per_page: "100" });
      if (statusFilter) params.set("status", statusFilter);
      if (search.trim()) params.set("search", search.trim());
      const response = await api.get<Page<RefundRow>>(`/admin/refunds?${params.toString()}`);
      setRefunds(response.data.data);
    } catch (exception: unknown) {
      setError(errorMessage(exception, "Could not load refunds."));
    } finally { setLoading(false); }
  }, [canView, search, statusFilter]);

  const loadPayments = useCallback(async () => {
    if (!canCreate) return;
    try {
      const response = await api.get<Page<EligiblePayment>>("/admin/refunds/eligible-payments?per_page=100");
      setPayments(response.data.data);
    } catch (exception: unknown) {
      setError(errorMessage(exception, "Could not load refundable payments."));
    }
  }, [canCreate]);

  useEffect(() => { void loadRefunds(); }, [loadRefunds]);
  useEffect(() => { void loadPayments(); }, [loadPayments]);

  const loadDetail = useCallback(async (id: number) => {
    setError("");
    try {
      const [detailResponse, historyResponse] = await Promise.all([
        api.get<ApiData<RefundDetail>>(`/admin/refunds/${id}`),
        api.get<ApiData<AuditEvent[]>>(`/admin/refunds/${id}/history`),
      ]);
      setDetail(detailResponse.data.data);
      setHistory(historyResponse.data.data);
    } catch (exception: unknown) {
      setDetail(null); setHistory([]);
      setError(errorMessage(exception, "Could not load this refund."));
    }
  }, []);

  useEffect(() => {
    if (selectedId === null) { setDetail(null); setHistory([]); return; }
    void loadDetail(selectedId);
  }, [selectedId, loadDetail]);

  function openCreate(payment: EligiblePayment) {
    setCreateFor(payment);
    setAmount(payment.refundable_balance);
    setMethod(payment.provider && payment.provider !== "cash" ? "original_payment_method" : "manual");
    setReason(""); setNotes(""); setError(""); setNotice("");
  }

  async function createRefund() {
    if (!createFor || busy) return;
    setBusy(true); setError(""); setNotice("");
    try {
      const response = await api.post<ApiData<RefundDetail>>("/admin/refunds", {
        payment_transaction_id: createFor.id,
        amount: amount.trim(),
        refund_method: method,
        reason: reason.trim(),
        notes: notes.trim() || undefined,
        operation_reference: crypto.randomUUID(),
      });
      setNotice(`Refund ${response.data.data.refund_reference} drafted. It must be submitted, approved and processed before any money moves.`);
      setCreateFor(null);
      setSelectedId(response.data.data.id);
      await Promise.all([loadRefunds(), loadPayments()]);
    } catch (exception: unknown) {
      setError(errorMessage(exception, "The refund could not be created."));
    } finally { setBusy(false); }
  }

  async function runAction(refund: RefundDetail, action: string, payload: Record<string, string>) {
    setBusy(true); setError(""); setNotice("");
    try {
      const response = await api.post<ApiData<RefundDetail>>(`/admin/refunds/${refund.id}/${action}`, payload);
      setDetail(response.data.data);
      setNotice(`Refund ${response.data.data.refund_reference} is now ${label(response.data.data.status)}.`);
      setPending(null); setPendingText("");
      await Promise.all([loadRefunds(), loadPayments(), loadDetail(refund.id)]);
    } catch (exception: unknown) {
      setError(errorMessage(exception, "The refund action could not be completed."));
    } finally { setBusy(false); }
  }

  function askAction(refund: RefundDetail, action: string, title: string, description: string, options: { textKind?: "reason" | "evidence"; danger?: boolean } = {}) {
    setPending({ refund, action, title, description, ...options });
    setPendingText("");
  }

  async function confirmPending() {
    if (!pending) return;
    const payload: Record<string, string> = pending.textKind === "reason"
      ? { reason: pendingText.trim() }
      : pending.textKind === "evidence"
        ? { evidence_reference: pendingText.trim() }
        : {};
    await runAction(pending.refund, pending.action, payload);
  }

  const pendingValid = !pending?.textKind || pendingText.trim().length >= 3;

  if (!canView) {
    return <div className="p-6"><p className={`${panel} text-sm`}>You do not have permission to view refunds.</p></div>;
  }

  return <div className="space-y-5 p-4 sm:p-6 lg:p-8">
    <header>
      <p className="text-xs font-bold uppercase tracking-wider text-[var(--color-accent)]">Finance</p>
      <h1 className="mt-1 flex items-center gap-2 text-2xl font-black text-[var(--color-text)]"><WalletCards size={25} /> Refunds</h1>
      <p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">Refunds are separately authorised money movements. The server calculates the refundable balance, enforces the lifecycle, and only marks a refund completed with verified processing evidence.</p>
    </header>

    {error && <p role="alert" className="flex gap-2 rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]"><AlertCircle size={18} className="shrink-0" />{error}</p>}
    {notice && <p role="status" className="flex gap-2 rounded-xl bg-[var(--color-accent)]/10 p-3 text-sm"><CheckCircle2 size={18} className="shrink-0 text-[var(--color-accent)]" />{notice}</p>}

    {canCreate && <section className={panel}>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div><h2 className="text-lg font-bold">Eligible payments</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Verified, received payments with a refundable balance. Drafts do not reserve funds; submitting does.</p></div>
        <button type="button" onClick={() => void loadPayments()} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}><RefreshCw size={16} className="mr-2" />Refresh</button>
      </div>
      <div className="mt-4 overflow-x-auto">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead><tr className="border-b text-xs uppercase text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>
            <th className="py-3 pr-3">Receipt</th><th className="py-3 pr-3">Payer</th><th className="py-3 pr-3">Received</th><th className="py-3 pr-3">Payment</th><th className="py-3 pr-3">Refundable</th><th className="py-3">Action</th>
          </tr></thead>
          <tbody>{payments.map((payment) => <tr key={payment.id} className="border-b align-top" style={{ borderColor: "var(--color-border)" }}>
            <td className="py-3 pr-3"><p className="break-all font-semibold">{payment.transaction_reference}</p><p className="text-xs text-[var(--color-text-secondary)]">{payment.provider || payment.payment_method}{payment.order ? ` · ${payment.order.order_number}` : ""}</p></td>
            <td className="py-3 pr-3">{payment.payer?.name || "—"}<p className="text-xs capitalize text-[var(--color-text-secondary)]">{payment.payer?.type || ""}</p></td>
            <td className="py-3 pr-3">{dateTime(payment.received_at)}</td>
            <td className="py-3 pr-3">{money(payment.currency, payment.amount)}</td>
            <td className="py-3 pr-3 font-bold">{money(payment.currency, payment.refundable_balance)}</td>
            <td className="py-3"><button type="button" onClick={() => openCreate(payment)} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}>Refund</button></td>
          </tr>)}</tbody>
        </table>
        {payments.length === 0 && <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">No payments currently have a refundable balance.</p>}
      </div>
    </section>}

    {createFor && <section className={panel}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div><h2 className="text-lg font-bold">New refund draft</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">{createFor.payer?.name} · {money(createFor.currency, createFor.refundable_balance)} refundable</p></div>
        <button type="button" onClick={() => setCreateFor(null)} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}>Close</button>
      </div>
      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        <label className="text-sm font-semibold">Amount ({createFor.currency})
          <input inputMode="decimal" className={`${input} mt-1`} value={amount} onChange={(event) => setAmount(event.target.value)} placeholder="0.00" />
          <span className="mt-1 block text-xs font-normal text-[var(--color-text-secondary)]">Server maximum: {money(createFor.currency, createFor.refundable_balance)}</span>
        </label>
        <label className="text-sm font-semibold">Refund method
          <select className={`${input} mt-1`} value={method} onChange={(event) => setMethod(event.target.value)}>
            <option value="original_payment_method">Original payment method</option>
            <option value="customer_credit">Customer account credit</option>
            <option value="manual">Manual (cash / bank transfer)</option>
          </select>
          <span className="mt-1 block text-xs font-normal text-[var(--color-text-secondary)]">Original-method refunds use the existing gateway account; provider completion must be verified.</span>
        </label>
        <label className="text-sm font-semibold sm:col-span-2">Reason (required)
          <input className={`${input} mt-1`} value={reason} onChange={(event) => setReason(event.target.value)} maxLength={2000} placeholder="Why is this refund being requested?" />
        </label>
        <label className="text-sm font-semibold sm:col-span-2">Supporting notes <span className="font-normal text-[var(--color-text-secondary)]">(optional)</span>
          <textarea className={`${input} mt-1 min-h-24 py-3`} value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={2000} />
        </label>
      </div>
      <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" onClick={() => setCreateFor(null)} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}>Cancel</button>
        <button type="button" disabled={busy || !/^\d+(\.\d{1,2})?$/.test(amount.trim()) || Number(amount) <= 0 || Number(amount) > Number(createFor.refundable_balance) || reason.trim().length < 3} onClick={() => void createRefund()} className={`${button} bg-[var(--color-accent)] text-white`}>{busy ? "Saving…" : "Create draft"}</button>
      </div>
    </section>}

    <section className={panel}>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><h2 className="text-lg font-bold">Refund register</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Every state change is recorded against the refund and its actor.</p></div>
        <div className="flex flex-col gap-2 sm:flex-row">
          <label className="text-sm font-semibold">Status
            <select className={`${input} mt-1`} value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
              <option value="">All statuses</option>
              {["draft", "submitted", "pending_review", "approved", "processing", "completed", "failed", "rejected", "cancelled", "reversed"].map((status) => <option key={status} value={status}>{label(status)}</option>)}
            </select>
          </label>
          <label className="text-sm font-semibold">Search
            <input className={`${input} mt-1`} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Reference, payer or receipt" />
          </label>
        </div>
      </div>
      <div className="mt-4 overflow-x-auto">
        <table className="w-full min-w-[820px] text-left text-sm">
          <thead><tr className="border-b text-xs uppercase text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>
            <th className="py-3 pr-3">Refund</th><th className="py-3 pr-3">Payer</th><th className="py-3 pr-3">Method</th><th className="py-3 pr-3">Amount</th><th className="py-3 pr-3">Status</th><th className="py-3">Action</th>
          </tr></thead>
          <tbody>{refunds.map((refund) => <tr key={refund.id} className="border-b align-top" style={{ borderColor: "var(--color-border)" }}>
            <td className="py-3 pr-3"><p className="font-semibold">{refund.refund_reference || `#${refund.id}`}</p><p className="break-all text-xs text-[var(--color-text-secondary)]">{refund.payment?.transaction_reference || "—"}{refund.commercial_document ? ` · ${refund.commercial_document.document_number}` : ""}</p></td>
            <td className="py-3 pr-3">{refund.payer?.name || "—"}</td>
            <td className="py-3 pr-3 text-xs">{METHOD_LABELS[refund.refund_method] || label(refund.refund_method)}</td>
            <td className="py-3 pr-3 font-semibold">{money(refund.currency, refund.amount)}</td>
            <td className="py-3 pr-3"><StatusBadge status={refund.status} /></td>
            <td className="py-3"><button type="button" onClick={() => setSelectedId(refund.id)} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}>{selectedId === refund.id ? "Selected" : "View"}</button></td>
          </tr>)}</tbody>
        </table>
        {loading && <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">Loading refunds…</p>}
        {!loading && refunds.length === 0 && <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">No refunds match this filter.</p>}
      </div>
    </section>

    {detail && <section className={panel}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div><h2 className="text-lg font-bold">{detail.refund_reference}</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">{detail.payer?.name} · {money(detail.currency, detail.amount)} · {METHOD_LABELS[detail.refund_method] || label(detail.refund_method)}</p></div>
        <StatusBadge status={detail.status} />
      </div>

      <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Original payment</dt><dd className="break-all font-semibold">{detail.payment?.transaction_reference || "—"}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Order / document</dt><dd className="font-semibold">{detail.order?.order_number || detail.commercial_document?.document_number || "—"}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Refundable balance</dt><dd className="font-semibold">{money(detail.currency, detail.refundable_balance)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Requested by</dt><dd className="font-semibold">{detail.initiator || "Unknown / unavailable"}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Approved by</dt><dd className="font-semibold">{detail.approver || "—"}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Requested</dt><dd className="font-semibold">{dateTime(detail.requested_at || detail.created_at)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Approved</dt><dd className="font-semibold">{dateTime(detail.approved_at)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Processing</dt><dd className="font-semibold">{dateTime(detail.processing_at)}</dd></div>
        <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Completed</dt><dd className="font-semibold">{dateTime(detail.completed_at)}</dd></div>
        {detail.gateway_reference && <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Provider reference</dt><dd className="break-all font-semibold">{detail.gateway_reference} ({detail.gateway_status || "unknown"})</dd></div>}
        {detail.external_transaction_reference && <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Evidence reference</dt><dd className="break-all font-semibold">{detail.external_transaction_reference}</dd></div>}
        {detail.credit_ledger_entry && <div><dt className="text-xs uppercase text-[var(--color-text-secondary)]">Credit ledger</dt><dd className="font-semibold">#{detail.credit_ledger_entry.id} · balance {money(detail.credit_ledger_entry.currency, detail.credit_ledger_entry.credit_balance_after)}</dd></div>}
      </dl>

      <div className="mt-4 rounded-xl bg-[var(--color-background)] p-3 text-sm">
        <p><strong>Reason:</strong> {detail.reason}</p>
        {detail.notes && <p className="mt-1"><strong>Notes:</strong> {detail.notes}</p>}
        {detail.rejection_reason && <p className="mt-1 text-[var(--color-danger)]"><strong>Rejected:</strong> {detail.rejection_reason}</p>}
        {detail.failure_reason && <p className="mt-1 text-[var(--color-danger)]"><strong>Failure:</strong> {detail.failure_reason}</p>}
        {detail.cancellation_reason && <p className="mt-1"><strong>Cancelled:</strong> {detail.cancellation_reason}</p>}
        {detail.reversal_reason && <p className="mt-1"><strong>Reversed:</strong> {detail.reversal_reason}</p>}
      </div>

      <div className="mt-4 flex flex-wrap gap-2">
        {detail.status === "draft" && canSubmit && <button type="button" className={`${button} bg-[var(--color-accent)] text-white`} onClick={() => askAction(detail, "submit", "Submit refund", "Submitting reserves this amount against the original payment so it cannot be refunded twice.")}>Submit</button>}
        {detail.status === "submitted" && canReview && <button type="button" className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }} onClick={() => askAction(detail, "review", "Move to pending review", "Record that this refund is now under formal review.")}>Start review</button>}
        {["submitted", "pending_review"].includes(detail.status) && canApprove && <>
          <button type="button" className={`${button} bg-[var(--color-accent)] text-white`} onClick={() => askAction(detail, "approve", "Approve refund", "Approval must come from a different user from the one who requested it.")}>Approve</button>
          <button type="button" className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }} onClick={() => askAction(detail, "reject", "Reject refund", "Rejection closes the request without moving money.", { textKind: "reason", danger: true })}>Reject</button>
        </>}
        {detail.status === "approved" && canProcess && <button type="button" className={`${button} bg-[var(--color-accent)] text-white`} onClick={() => askAction(detail, "process", "Process refund", detail.refund_method === "customer_credit" ? "This posts the refund to the customer credit ledger as an immutable entry." : detail.refund_method === "manual" ? "This marks the refund as being handled outside the gateway. It must then be completed with an external reference." : "This sends the refund to the original payment provider. Completion still requires verified provider evidence.")}>Process</button>}
        {detail.status === "processing" && detail.refund_method === "original_payment_method" && canProcess && <button type="button" className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }} onClick={() => askAction(detail, "verify", "Verify with provider", "Ask the provider for the authoritative refund status. Only a verified completed status marks this refund complete.")}>Verify with provider</button>}
        {detail.status === "processing" && canComplete && <button type="button" className={`${button} bg-[var(--color-accent)] text-white`} onClick={() => askAction(detail, "complete", "Record completion", "Completion requires verified processing evidence. Record the provider or external transaction reference.", { textKind: "evidence" })}>Record completion</button>}
        {detail.status === "processing" && canProcess && <button type="button" className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }} onClick={() => askAction(detail, "fail", "Record failure", "Record why the refund did not complete. No money is moved by this action.", { textKind: "reason", danger: true })}>Record failure</button>}
        {detail.status === "failed" && canProcess && <button type="button" className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }} onClick={() => askAction(detail, "retry", "Retry refund", "A retry verifies an existing provider reference instead of submitting a second refund.")}>Retry</button>}
        {["draft", "submitted", "pending_review", "approved"].includes(detail.status) && canCancel && <button type="button" className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }} onClick={() => askAction(detail, "cancel", "Cancel refund", "Cancelling releases any reserved amount. This cannot be undone.", { textKind: "reason" })}>Cancel refund</button>}
        {detail.status === "completed" && canReverse && <button type="button" className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }} onClick={() => askAction(detail, "reverse", "Reverse refund record", "A reversal records audited correction evidence. It does not move money and does not edit the credit ledger.", { textKind: "reason", danger: true })}><Undo2 size={16} className="mr-2" />Reverse</button>}
      </div>

      <h3 className="mt-6 text-base font-bold">Transaction history</h3>
      {history.length === 0 ? <p className="mt-2 text-sm text-[var(--color-text-secondary)]">No audit events recorded yet.</p> : <ol className="mt-2 space-y-2">{history.map((event, index) => <li key={`${event.action}-${index}`} className="rounded-xl border p-3 text-sm" style={{ borderColor: "var(--color-border)" }}><div className="flex flex-wrap justify-between gap-2"><p className="font-semibold capitalize">{label(event.action)}</p><p className="text-xs text-[var(--color-text-secondary)]">{event.actor} · {dateTime(event.created_at)}</p></div><p className="mt-1 text-[var(--color-text-secondary)]">{event.description}</p></li>)}</ol>}
    </section>}

    {pending && <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 sm:items-center sm:p-4" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) setPending(null); }}>
      <section role="dialog" aria-modal="true" aria-labelledby="refund-action-title" className="w-full rounded-t-2xl border bg-[var(--color-surface)] p-4 text-[var(--color-text)] shadow-2xl sm:max-w-xl sm:rounded-2xl sm:p-6" style={{ borderColor: "var(--color-border)" }}>
        <h2 id="refund-action-title" className="text-xl font-black">{pending.title}</h2>
        <p className="mt-2 text-sm text-[var(--color-text-secondary)]">{pending.description}</p>
        <p className="mt-3 rounded-xl bg-[var(--color-background)] p-3 text-sm"><strong>{pending.refund.refund_reference}</strong> · {money(pending.refund.currency, pending.refund.amount)}</p>
        {pending.textKind && <label className="mt-4 block text-sm font-semibold">{pending.textKind === "reason" ? "Reason (required)" : "Evidence reference (required)"}
          <textarea className={`${input} mt-1 min-h-24 py-3`} value={pendingText} onChange={(event) => setPendingText(event.target.value)} maxLength={2000} required />
        </label>}
        <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" disabled={busy} onClick={() => setPending(null)} className={`${button} ${outline}`} style={{ borderColor: "var(--color-border)" }}>Cancel</button>
          <button type="button" disabled={busy || !pendingValid} onClick={() => void confirmPending()} className={`${button} ${pending.danger ? "bg-[var(--color-danger)]" : "bg-[var(--color-accent)]"} text-white`}>{busy ? "Working…" : "Confirm"}</button>
        </div>
      </section>
    </div>}
  </div>;
}
