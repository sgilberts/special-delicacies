import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import customerApi from "@/lib/customerApi";

type Doc = { id: number; document_number: string; document_type: "quotation" | "invoice"; status: string; issue_date: string | null; expiry_date: string | null; total: string; currency: string; organisation: { name: string } | null };

export default function CustomerDocumentsPage() {
  const [type, setType] = useState<"quotation" | "invoice">("quotation");
  const [documents, setDocuments] = useState<Doc[]>([]);
  const [error, setError] = useState("");

  useEffect(() => {
    setError("");
    customerApi.get<{ data: Doc[] }>(`/customer/${type === "quotation" ? "quotations" : "invoices"}`)
      .then(response => setDocuments(response.data.data))
      .catch(() => setError("We could not load your documents. Please sign in again."));
  }, [type]);

  return <main className="mx-auto max-w-5xl space-y-5 px-4 py-8 sm:px-6">
    <div><Link to="/account" className="text-sm font-semibold text-[var(--color-accent)]">← Your account</Link><h1 className="mt-2 text-2xl font-bold text-[var(--color-text)]">Quotations and invoices</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Issued documents for your customer account and eligible organisation memberships.</p></div>
    <div className="flex gap-2" role="tablist" aria-label="Commercial documents">
      {(["quotation", "invoice"] as const).map(value => <button key={value} role="tab" aria-selected={type === value} onClick={() => setType(value)} className={`min-h-11 rounded-xl border px-4 text-sm font-semibold ${type === value ? "bg-[var(--color-accent)] text-white" : "bg-[var(--color-surface)] text-[var(--color-text)]"}`} style={{ borderColor: "var(--color-border)" }}>{value === "quotation" ? "Quotations" : "Invoices"}</button>)}
    </div>
    {error && <p role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-4 text-sm text-[var(--color-danger)]">{error}</p>}
    {documents.length === 0 ? <p className="rounded-xl border bg-[var(--color-surface)] p-5 text-sm text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>No issued {type === "quotation" ? "quotations" : "invoices"} are available.</p> : <div className="space-y-3">{documents.map(document => <Link key={document.id} to={`/account/documents/${document.document_type}/${document.id}`} className="grid gap-2 rounded-xl border bg-[var(--color-surface)] p-4 text-[var(--color-text)] no-underline hover:border-[var(--color-accent)] sm:grid-cols-[1fr_auto] sm:items-center" style={{ borderColor: "var(--color-border)" }}><div><h2 className="font-bold">{document.document_number}</h2><p className="text-sm text-[var(--color-text-secondary)]">{document.organisation?.name ? `${document.organisation.name} · ` : ""}{document.issue_date ? `Issued ${document.issue_date}` : ""}{document.expiry_date ? ` · Expires ${document.expiry_date}` : ""}</p></div><div className="flex items-center justify-between gap-4 sm:justify-end"><span className="rounded-full bg-[var(--color-accent)]/10 px-3 py-1 text-xs font-bold capitalize">{document.status}</span><span className="font-bold">{document.currency} {document.total}</span></div></Link>)}</div>}
  </main>;
}
