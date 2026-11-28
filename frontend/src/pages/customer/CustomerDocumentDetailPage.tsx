import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import customerApi from "@/lib/customerApi";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";
import { verificationGate } from "@/lib/verificationGate";
import { httpStatusOf } from "@/lib/sectionLoad";

type Line = { id: number; description: string; detail: string | null; unit_of_measure: string; quantity: string; unit_price: string; discount_amount: string; line_subtotal: string; tax_amount: string; line_total: string };
type Document = { id: number; document_number: string; document_type: "quotation" | "invoice"; status: string; issue_date: string | null; expiry_date: string | null; currency: string; subtotal: string; discount_amount: string; tax_amount: string; total: string; discount_description: string | null; notes: string | null; terms: string | null; void_reason: string | null; customer: { name: string; email: string | null; phone: string } | null; organisation: { name: string; reference: string } | null; lines: Line[]; payment_summary?: { invoice_total: string; allocated_amount: string; outstanding_balance: string; payment_state: "unpaid" | "partially_paid" | "fully_paid"; payments: { amount: string; currency: string; transaction_reference: string | null; allocated_at: string; reversal: { reason: string; reversed_at: string } | null }[] } };
type History = { action: string; from_status: string | null; to_status: string | null; created_at: string; actor: { type: string; name: string | null } };

export default function CustomerDocumentDetailPage() {
  const { content } = useRestaurantContent();
  const { documentType = "quotation", documentId = "" } = useParams();
  const isQuotation = documentType === "quotation";
  const resource = isQuotation ? "quotations" : "invoices";
  const [document, setDocument] = useState<Document | null>(null);
  const [history, setHistory] = useState<History[]>([]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function load() {
    setError("");
    try {
      const [docResponse, historyResponse] = await Promise.all([
        customerApi.get<{ data: Document }>(`/customer/${resource}/${documentId}`),
        customerApi.get<{ data: History[] }>(`/customer/${resource}/${documentId}/history`),
      ]);
      setDocument(docResponse.data.data); setHistory(historyResponse.data.data);
    } catch (cause) {
      if (verificationGate(cause)) {
        setError("Verify both your email address and telephone number to view this document. Go to your account to complete verification.");
      } else if (httpStatusOf(cause) === 401) {
        setError("Your session has expired. Sign in again to view this document.");
      } else {
        setError("This document is unavailable for your account.");
      }
    }
  }
  useEffect(() => { void load(); }, [documentId, resource]);

  async function respond(action: "accept" | "decline") {
    setBusy(true); setError("");
    try { await customerApi.post(`/customer/quotations/${documentId}/${action}`); await load(); }
    catch (exception: unknown) { const message = (exception as { response?: { data?: { message?: string } } }).response?.data?.message; setError(message || "We could not update this quotation."); }
    finally { setBusy(false); }
  }

  if (!document) return <main className="mx-auto max-w-4xl px-4 py-8 sm:px-6"><Link to="/account/documents" className="text-sm font-semibold text-[var(--color-accent)]">← Documents</Link>{error ? <div role="alert" className="mt-4 rounded-xl bg-[var(--color-danger)]/10 p-4 text-sm text-[var(--color-danger)]"><p>{error}</p><button type="button" onClick={() => void load()} className="mt-2 min-h-9 font-semibold underline">Try again</button></div> : <p role="status" className="mt-4 text-sm text-[var(--color-text-secondary)]">Loading document…</p>}</main>;

  return <main className="mx-auto max-w-4xl space-y-5 px-4 py-8 sm:px-6">
    <style>{`@media print { body { background: #fff !important; color: #111 !important; } .commercial-document-print, .commercial-document-print * { color: #111 !important; background-color: #fff !important; border-color: #ddd !important; box-shadow: none !important; } }`}</style>
    <div className="flex flex-wrap items-end justify-between gap-3 print:hidden"><div><Link to="/account/documents" className="text-sm font-semibold text-[var(--color-accent)]">← Quotations and invoices</Link><p className="mt-3 text-xs font-bold uppercase tracking-wider text-[var(--color-accent)]">{isQuotation ? "Quotation" : "Invoice"}</p><h1 className="mt-1 text-2xl font-bold text-[var(--color-text)]">{document.document_number}</h1></div><div className="flex items-center gap-3"><span className="rounded-full bg-[var(--color-accent)]/10 px-3 py-1.5 text-sm font-bold capitalize text-[var(--color-text)]">{document.status}</span><button onClick={() => window.print()} className="min-h-10 rounded-lg border px-3 text-sm font-semibold text-[var(--color-text)]" style={{ borderColor: "var(--color-border)" }}>Print / Save PDF</button></div></div>
    {error && <p role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-4 text-sm text-[var(--color-danger)]">{error}</p>}
    <section className="commercial-document-print rounded-2xl border bg-[var(--color-surface)] p-5 text-[var(--color-text)]" style={{ borderColor: "var(--color-border)" }}>
      <div className="mb-5 flex flex-wrap items-start justify-between gap-4 border-b pb-4" style={{ borderColor: "var(--color-border)" }}><div className="flex items-center gap-3">{content.restaurant_logo && <img src={assetUrl(content.restaurant_logo) || undefined} alt="" className="h-12 w-12 rounded-lg object-contain" />}<div><p className="text-lg font-black">{content.restaurant_name}</p><p className="text-sm text-[var(--color-text-secondary)]">{content.restaurant_address}</p></div></div><div className="text-sm text-[var(--color-text-secondary)]"><p>{content.restaurant_phone}</p><p>{content.restaurant_email}</p></div><div className="hidden print:block"><p className="text-xs font-bold uppercase">{isQuotation ? "Quotation" : "Invoice"}</p><p className="font-bold">{document.document_number}</p></div></div>
      <div className="grid gap-4 sm:grid-cols-2"><div><p className="text-xs uppercase text-[var(--color-text-secondary)]">Prepared for</p><p className="mt-1 font-semibold">{document.organisation?.name || document.customer?.name}</p>{document.organisation && <p className="text-sm text-[var(--color-text-secondary)]">{document.organisation.reference}</p>}{document.customer?.email && <p className="text-sm text-[var(--color-text-secondary)]">{document.customer.email}</p>}</div><div className="sm:text-right"><p className="text-sm text-[var(--color-text-secondary)]">Issue date: {document.issue_date || "—"}</p>{isQuotation && <p className="text-sm text-[var(--color-text-secondary)]">Expiry date: {document.expiry_date || "No expiry date"}</p>}</div></div>
      <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[540px] text-left text-sm"><thead><tr className="border-b text-xs uppercase text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}><th className="py-3 pr-3">Description</th><th className="py-3 pr-3">Qty</th><th className="py-3 pr-3">Unit price</th><th className="py-3 pr-3">Discount</th><th className="py-3 text-right">Line total</th></tr></thead><tbody>{document.lines.map(line => <tr key={line.id} className="border-b" style={{ borderColor: "var(--color-border)" }}><td className="py-3 pr-3"><p className="font-semibold">{line.description}</p>{line.detail && <p className="text-xs text-[var(--color-text-secondary)]">{line.detail}</p>}</td><td className="py-3 pr-3">{line.quantity} {line.unit_of_measure}</td><td className="py-3 pr-3">{document.currency} {line.unit_price}</td><td className="py-3 pr-3">{document.currency} {line.discount_amount}</td><td className="py-3 text-right font-semibold">{document.currency} {line.line_total}</td></tr>)}</tbody></table></div>
      <div className="ml-auto mt-5 max-w-xs space-y-2 text-sm"><div className="flex justify-between"><span>Subtotal</span><span>{document.currency} {document.subtotal}</span></div>{Number(document.discount_amount) > 0 && <div className="flex justify-between"><span>Discount</span><span>− {document.currency} {document.discount_amount}</span></div>}{Number(document.tax_amount) > 0 && <div className="flex justify-between"><span>Tax</span><span>{document.currency} {document.tax_amount}</span></div>}<div className="flex justify-between border-t pt-2 text-base font-bold" style={{ borderColor: "var(--color-border)" }}><span>Total</span><span>{document.currency} {document.total}</span></div></div>
      {document.notes && <div className="mt-5"><h2 className="font-bold">Notes</h2><p className="mt-1 whitespace-pre-wrap text-sm text-[var(--color-text-secondary)]">{document.notes}</p></div>}{document.terms && <div className="mt-4"><h2 className="font-bold">Terms</h2><p className="mt-1 whitespace-pre-wrap text-sm text-[var(--color-text-secondary)]">{document.terms}</p></div>}{document.void_reason && <p className="mt-4 rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm">Void reason: {document.void_reason}</p>}
      {isQuotation && document.status === "issued" && <div className="mt-6 flex flex-wrap gap-3 border-t pt-4" style={{ borderColor: "var(--color-border)" }}><button disabled={busy} onClick={() => void respond("accept")} className="min-h-11 rounded-xl bg-[var(--color-accent)] px-5 font-bold text-white disabled:opacity-50">Accept quotation</button><button disabled={busy} onClick={() => void respond("decline")} className="min-h-11 rounded-xl border px-5 font-semibold disabled:opacity-50" style={{ borderColor: "var(--color-border)" }}>Decline</button></div>}
    </section>
    {document.document_type === "invoice" && document.payment_summary && <section className="rounded-2xl border bg-[var(--color-surface)] p-5 text-[var(--color-text)]" style={{ borderColor: "var(--color-border)" }}><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-bold">Payments</h2><p className="text-sm text-[var(--color-text-secondary)]">Payments allocated to this invoice.</p></div><span className="rounded-full bg-[var(--color-accent)]/10 px-3 py-1.5 text-sm font-bold capitalize">{document.payment_summary.payment_state.replaceAll("_", " ")}</span></div><div className="mt-4 grid gap-3 sm:grid-cols-3">{[["Invoice amount", document.payment_summary.invoice_total], ["Allocated", document.payment_summary.allocated_amount], ["Balance", document.payment_summary.outstanding_balance]].map(([label, amount]) => <div key={label} className="rounded-xl bg-[var(--color-background)] p-3"><p className="text-xs text-[var(--color-text-secondary)]">{label}</p><p className="mt-1 font-bold">{document.currency} {amount}</p></div>)}</div>{document.payment_summary.payments.length > 0 && <div className="mt-3 divide-y" style={{ borderColor: "var(--color-border)" }}>{document.payment_summary.payments.map((payment, index) => <div key={`${payment.transaction_reference}-${payment.allocated_at}-${index}`} className="flex flex-wrap items-center justify-between gap-2 py-3"><div><p className="text-sm font-semibold">{payment.transaction_reference || "Payment"}{payment.reversal && <span className="ml-2 text-xs text-[var(--color-text-secondary)]">Reversed</span>}</p><p className="text-xs text-[var(--color-text-secondary)]">Allocated {new Date(payment.allocated_at).toLocaleString()}{payment.reversal ? ` · ${payment.reversal.reason}` : ""}</p></div><p className="font-bold">{payment.currency} {payment.amount}</p></div>)}</div>}</section>}
    <section className="rounded-2xl border bg-[var(--color-surface)] p-5 text-[var(--color-text)]" style={{ borderColor: "var(--color-border)" }}><h2 className="font-bold">Document history</h2><ol className="mt-3 space-y-3">{history.map((event, index) => <li key={`${event.action}-${index}`} className="border-l-2 pl-3" style={{ borderColor: "var(--color-accent)" }}><p className="text-sm font-semibold capitalize">{event.action.replaceAll("_", " ")}{event.to_status && ` · ${event.to_status}`}</p><p className="text-xs text-[var(--color-text-secondary)]">{event.actor.name || "System"} · {new Date(event.created_at).toLocaleString()}</p></li>)}</ol></section>
  </main>;
}
