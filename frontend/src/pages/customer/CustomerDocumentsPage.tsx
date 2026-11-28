import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import customerApi from "@/lib/customerApi";
import { verificationGate } from "@/lib/verificationGate";
import { httpStatusOf } from "@/lib/sectionLoad";

type Doc = { id: number; document_number: string; document_type: "quotation" | "invoice"; status: string; issue_date: string | null; expiry_date: string | null; total: string; currency: string; organisation: { name: string } | null };

type LoadState = "loading" | "ready" | "restricted" | "expired" | "error";

export default function CustomerDocumentsPage() {
  const [type, setType] = useState<"quotation" | "invoice">("quotation");
  const [documents, setDocuments] = useState<Doc[]>([]);
  const [state, setState] = useState<LoadState>("loading");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setState("loading");
    setError("");
    try {
      const response = await customerApi.get<{ data: Doc[] }>(`/customer/${type === "quotation" ? "quotations" : "invoices"}`);
      setDocuments(response.data.data ?? []);
      setState("ready");
    } catch (cause) {
      setDocuments([]);
      if (verificationGate(cause)) {
        // The backend requires both contact details verified before billing
        // documents are released; say so instead of blaming the session.
        setState("restricted");
      } else if (httpStatusOf(cause) === 401) {
        setState("expired");
      } else {
        setError(
          (cause as { response?: { data?: { message?: string } } }).response?.data?.message
            ?? "Your documents could not be loaded. Please try again.",
        );
        setState("error");
      }
    }
  }, [type]);

  useEffect(() => {
    void load();
  }, [load]);

  return <main className="mx-auto max-w-5xl space-y-5 px-4 py-8 sm:px-6">
    <div><Link to="/account" className="text-sm font-semibold text-[var(--color-accent)]">← Your account</Link><h1 className="mt-2 text-2xl font-bold text-[var(--color-text)]">Quotations and invoices</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Issued documents for your customer account and eligible organisation memberships.</p></div>
    <div className="flex gap-2" role="tablist" aria-label="Commercial documents">
      {(["quotation", "invoice"] as const).map(value => <button key={value} role="tab" aria-selected={type === value} onClick={() => setType(value)} className={`min-h-11 rounded-xl border px-4 text-sm font-semibold ${type === value ? "bg-[var(--color-accent)] text-white" : "bg-[var(--color-surface)] text-[var(--color-text)]"}`} style={{ borderColor: "var(--color-border)" }}>{value === "quotation" ? "Quotations" : "Invoices"}</button>)}
    </div>

    {state === "loading" && <p role="status" className="rounded-xl border bg-[var(--color-surface)] p-5 text-sm text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>Loading your {type === "quotation" ? "quotations" : "invoices"}…</p>}

    {state === "restricted" && <div role="status" className="rounded-xl border bg-[var(--color-surface)] p-5 text-sm text-[var(--color-text)]" style={{ borderColor: "var(--color-border)" }}>
      <p className="font-semibold">Verify your contact details to view your documents.</p>
      <p className="mt-1 text-[var(--color-text-secondary)]">Quotations and invoices are released once both your email address and telephone number are verified.</p>
      <Link to="/account" className="mt-3 inline-flex min-h-11 items-center font-semibold text-[var(--color-accent)]">Go to verification</Link>
    </div>}

    {state === "expired" && <div role="alert" className="rounded-xl border bg-[var(--color-surface)] p-5 text-sm text-[var(--color-text)]" style={{ borderColor: "var(--color-border)" }}>
      <p className="font-semibold">Your session has expired.</p>
      <p className="mt-1 text-[var(--color-text-secondary)]">Sign in again to see your quotations and invoices.</p>
      <Link to="/account/login" className="mt-3 inline-flex min-h-11 items-center font-semibold text-[var(--color-accent)]">Sign in</Link>
    </div>}

    {state === "error" && <div role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-4 text-sm text-[var(--color-danger)]">
      <p>{error}</p>
      <button type="button" onClick={() => void load()} className="mt-2 min-h-9 font-semibold underline">Try again</button>
    </div>}

    {state === "ready" && (documents.length === 0
      ? <p className="rounded-xl border bg-[var(--color-surface)] p-5 text-sm text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>No issued {type === "quotation" ? "quotations" : "invoices"} are available.</p>
      : <div className="space-y-3">{documents.map(document => <Link key={document.id} to={`/account/documents/${document.document_type}/${document.id}`} className="grid gap-2 rounded-xl border bg-[var(--color-surface)] p-4 text-[var(--color-text)] no-underline hover:border-[var(--color-accent)] sm:grid-cols-[1fr_auto] sm:items-center" style={{ borderColor: "var(--color-border)" }}><div><h2 className="font-bold">{document.document_number}</h2><p className="text-sm text-[var(--color-text-secondary)]">{document.organisation?.name ? `${document.organisation.name} · ` : ""}{document.issue_date ? `Issued ${document.issue_date}` : ""}{document.expiry_date ? ` · Expires ${document.expiry_date}` : ""}</p></div><div className="flex items-center justify-between gap-4 sm:justify-end"><span className="rounded-full bg-[var(--color-accent)]/10 px-3 py-1 text-xs font-bold capitalize">{document.status}</span><span className="font-bold">{document.currency} {document.total}</span></div></Link>)}</div>)}
  </main>;
}
