import { useCallback, useEffect, useState } from "react";
import { ShieldAlert } from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { hasAnyPermission } from "@/lib/permissionAccess";
import { dateTime, daysAgo, money, titleCase, today } from "@/components/financial/format";

type Run = {
  id: number;
  reference: string;
  source: string;
  period_start: string;
  period_end: string;
  status: string;
  prepared_by: string | null;
  prepared_at: string;
  reviewed_by: string | null;
  reviewed_at: string | null;
  exception_count: number;
  resolution_notes: string | null;
  immutable: boolean;
};

type Snapshot = {
  period: { from: string; to: string; timezone: string };
  currency: string | null;
  receipts: Record<string, any>;
  invoices: Record<string, any>;
  exceptions: { code: string; label: string; count: number }[];
  settlements: { available: boolean; tier: string; reason: string; required_data: string[]; decisions_pending: string[] };
};

const controlClass = "mt-1 min-h-10 w-full rounded-lg border bg-[var(--color-input-background)] px-2 text-sm";
const controlStyle = { borderColor: "var(--color-border)" } as const;

const STATUS_TONE: Record<string, string> = {
  awaiting_review: "text-[var(--color-warning)]",
  reconciled: "text-[var(--color-success)]",
  reconciled_with_exceptions: "text-[var(--color-danger)]",
};

export default function FinancialReconciliationPage() {
  const { user } = useAuth();
  const permissions = user?.permissions ?? [];
  const canView = hasAnyPermission(permissions, ["financials.reconciliation.view"], user?.roles);
  const canPrepare = hasAnyPermission(permissions, ["financials.reconciliation.manage"], user?.roles);
  const canReview = hasAnyPermission(permissions, ["financials.reconciliation.review"], user?.roles);

  const [from, setFrom] = useState(daysAgo(29));
  const [to, setTo] = useState(today());
  const [currency, setCurrency] = useState("GHS");
  const [snapshot, setSnapshot] = useState<Snapshot | null>(null);
  const [runs, setRuns] = useState<Run[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [reviewing, setReviewing] = useState<Run | null>(null);
  const [reviewStatus, setReviewStatus] = useState<"reconciled" | "reconciled_with_exceptions">("reconciled_with_exceptions");
  const [reviewNotes, setReviewNotes] = useState("");

  const load = useCallback(async () => {
    if (!canView) return;
    setLoading(true);
    setError("");
    try {
      const [snapshotResponse, runsResponse] = await Promise.all([
        api.get("/admin/reconciliation", { params: { from, to, currency } }),
        api.get("/admin/reconciliation/runs", { params: { per_page: 20 } }),
      ]);
      setSnapshot(snapshotResponse.data.data as Snapshot);
      setRuns((runsResponse.data.data ?? []) as Run[]);
    } catch (cause) {
      const status = (cause as { response?: { status?: number } }).response?.status;
      setError(status === 403 ? "Your account does not have permission to view reconciliation." : "Reconciliation could not be loaded.");
      setSnapshot(null);
    } finally {
      setLoading(false);
    }
  }, [canView, from, to, currency]);

  useEffect(() => {
    void load();
  }, [load]);

  const prepare = async () => {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const response = await api.post("/admin/reconciliation/runs", { from, to, currency });
      setMessage(`Reconciliation ${response.data?.data?.reference ?? ""} prepared and awaiting review.`);
      await load();
    } catch (cause) {
      const detail = (cause as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? "The reconciliation could not be prepared.");
    } finally {
      setBusy(false);
    }
  };

  const review = async () => {
    if (!reviewing) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await api.post(`/admin/reconciliation/runs/${reviewing.id}/review`, {
        status: reviewStatus,
        resolution_notes: reviewNotes || null,
      });
      setMessage(`Reconciliation ${reviewing.reference} closed. Completed history cannot be edited.`);
      setReviewing(null);
      setReviewNotes("");
      await load();
    } catch (cause) {
      const detail = (cause as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? "The reconciliation could not be reviewed.");
    } finally {
      setBusy(false);
    }
  };

  if (!canView) {
    return (
      <section className="flex min-h-[40vh] flex-col items-center justify-center gap-3 text-center">
        <ShieldAlert size={40} className="text-[var(--color-danger)]" />
        <h1 className="text-xl font-bold">403 — Access denied</h1>
        <p className="text-sm text-[var(--color-text-secondary)]">Your account does not have permission to view reconciliation.</p>
      </section>
    );
  }

  const receipts = snapshot?.receipts ?? {};
  const invoices = snapshot?.invoices ?? {};
  const settlements = snapshot?.settlements;

  return (
    <div className="space-y-4">
      <header>
        <p className="text-xs font-bold uppercase tracking-widest text-[var(--color-accent)]">Financial management</p>
        <h1 className="mt-1 text-2xl font-black">Reconciliation</h1>
        <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
          Internal receipt and invoice reconciliation with an auditable run history. Provider settlement reconciliation stays unavailable until a genuine settlement feed exists.
        </p>
      </header>

      <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label className="text-sm">From<input type="date" className={controlClass} style={controlStyle} value={from} onChange={(event) => setFrom(event.target.value)} /></label>
          <label className="text-sm">To<input type="date" className={controlClass} style={controlStyle} value={to} onChange={(event) => setTo(event.target.value)} /></label>
          <label className="text-sm">Currency
            <select className={controlClass} style={controlStyle} value={currency} onChange={(event) => setCurrency(event.target.value)}>
              <option value="GHS">GHS</option>
            </select>
          </label>
          <div className="flex items-end">
            <button type="button" onClick={() => void load()} className="min-h-10 w-full rounded-lg border text-sm font-semibold" style={controlStyle}>Refresh</button>
          </div>
        </div>
        {canPrepare && (
          <button type="button" disabled={busy} onClick={() => void prepare()} className="mt-3 min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-semibold text-white disabled:opacity-50">
            Prepare reconciliation run
          </button>
        )}
      </section>

      {message && <p role="status" className="rounded-xl bg-[var(--color-success)]/10 p-3 text-sm">{message}</p>}
      {error && (
        <div role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">
          {error}
          <button type="button" onClick={() => void load()} className="ml-2 min-h-8 rounded-lg border px-2 font-semibold" style={controlStyle}>Retry</button>
        </div>
      )}
      {loading && <p className="text-sm text-[var(--color-text-secondary)]">Loading reconciliation…</p>}

      {!loading && snapshot && (
        <>
          <section className="grid gap-3 lg:grid-cols-2">
            <article className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
              <h2 className="text-lg font-bold">Receipt reconciliation</h2>
              <p className="text-sm text-[var(--color-text-secondary)]">Successful invoice receipts compared with their allocations, credit designations and available balances.</p>
              <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
                <Row label="Receipts" value={String(receipts.receipts ?? 0)} />
                <Row label="Receipt value" value={money(currency, receipts.receipt_amount)} />
                <Row label="Fully allocated" value={String(receipts.fully_allocated ?? 0)} />
                <Row label="Partially allocated" value={String(receipts.partially_allocated ?? 0)} />
                <Row label="Unallocated" value={String(receipts.unallocated ?? 0)} />
                <Row label="Reversed allocations" value={`${receipts.reversed_allocations ?? 0} · ${money(currency, receipts.reversed_amount)}`} />
                <Row label="Applied amount" value={money(currency, receipts.applied_amount)} />
                <Row label="Available amount" value={money(currency, receipts.available_amount)} />
              </dl>
            </article>
            <article className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
              <h2 className="text-lg font-bold">Invoice reconciliation</h2>
              <p className="text-sm text-[var(--color-text-secondary)]">Invoice totals compared with active allocations, applied credit and outstanding balances.</p>
              <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
                <Row label="Invoices" value={String(invoices.invoices ?? 0)} />
                <Row label="Invoice total" value={money(currency, invoices.invoice_total)} />
                <Row label="Allocated" value={money(currency, invoices.allocated_amount)} />
                <Row label="Credit applied" value={money(currency, invoices.credit_applied_amount)} />
                <Row label="Outstanding" value={money(currency, invoices.outstanding_amount)} />
                <Row label="Paid / partial / unpaid" value={`${invoices.fully_paid ?? 0} / ${invoices.partially_paid ?? 0} / ${invoices.unpaid ?? 0}`} />
                <Row label="Over-settled" value={String(invoices.over_settled ?? 0)} />
                <Row label="Exceptions" value={String((snapshot.exceptions ?? []).find((item) => item.code === "TOTAL")?.count ?? 0)} />
              </dl>
            </article>
          </section>

          <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
            <h2 className="text-lg font-bold">Exceptions</h2>
            <ul className="mt-2">
              {(snapshot.exceptions ?? []).map((item) => (
                <li key={item.code} className="flex items-center justify-between border-t py-2 text-sm" style={controlStyle}>
                  <span>{item.code} · {item.label}</span>
                  <span className={`font-semibold tabular-nums ${item.code !== "TOTAL" && item.count > 0 ? "text-[var(--color-danger)]" : "text-[var(--color-text-secondary)]"}`}>{item.count}</span>
                </li>
              ))}
            </ul>
          </section>

          {settlements && (
            <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
              <h2 className="text-lg font-bold">Provider settlement reconciliation</h2>
              {settlements.available ? (
                <p className="mt-1 text-sm">Settlement data is available.</p>
              ) : (
                <>
                  <p className="mt-1 rounded-lg bg-[var(--color-warning)]/10 p-3 text-sm">
                    <strong>Not configured.</strong> {settlements.reason}
                  </p>
                  <p className="mt-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Required data before this can be enabled</p>
                  <ul className="mt-1 list-disc pl-5 text-sm">{settlements.required_data.map((item) => <li key={item}>{item}</li>)}</ul>
                  <p className="mt-2 text-xs text-[var(--color-text-secondary)]">Owner decisions pending: {settlements.decisions_pending.join(", ")}. No settlement batch, fee or payout figure is estimated.</p>
                </>
              )}
            </section>
          )}

          <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
            <h2 className="text-lg font-bold">Reconciliation history</h2>
            <div className="mt-2 overflow-x-auto">
              <table className="w-full min-w-[820px] text-left text-sm">
                <thead className="text-[var(--color-text-secondary)]"><tr>
                  <th className="p-2">Reference</th><th className="p-2">Period</th><th className="p-2">Status</th>
                  <th className="p-2">Prepared</th><th className="p-2">Reviewed</th><th className="p-2 text-right">Exceptions</th><th className="p-2">Action</th>
                </tr></thead>
                <tbody>
                  {runs.map((run) => (
                    <tr key={run.id} className="border-t align-top" style={controlStyle}>
                      <td className="p-2 font-semibold">{run.reference}<small className="block font-normal text-[var(--color-text-secondary)]">{titleCase(run.source)}</small></td>
                      <td className="p-2">{run.period_start} → {run.period_end}</td>
                      <td className={`p-2 font-semibold ${STATUS_TONE[run.status] ?? ""}`}>{titleCase(run.status)}{run.immutable && <small className="block font-normal text-[var(--color-text-secondary)]">closed · read-only</small>}</td>
                      <td className="p-2">{run.prepared_by ?? "—"}<small className="block text-[var(--color-text-secondary)]">{dateTime(run.prepared_at)}</small></td>
                      <td className="p-2">{run.reviewed_by ?? "—"}<small className="block text-[var(--color-text-secondary)]">{run.reviewed_at ? dateTime(run.reviewed_at) : "awaiting review"}</small></td>
                      <td className="p-2 text-right tabular-nums">{run.exception_count}</td>
                      <td className="p-2">
                        {canReview && !run.immutable
                          ? <button type="button" onClick={() => { setReviewing(run); setReviewNotes(run.resolution_notes ?? ""); }} className="min-h-9 rounded-lg border px-2 font-semibold" style={controlStyle}>Review</button>
                          : <span className="text-[var(--color-text-secondary)]">—</span>}
                      </td>
                    </tr>
                  ))}
                  {runs.length === 0 && <tr><td colSpan={7} className="p-4 text-center text-[var(--color-text-secondary)]">No reconciliation runs yet.</td></tr>}
                </tbody>
              </table>
            </div>
          </section>

          {reviewing && (
            <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
              <div className="flex items-start justify-between gap-2">
                <div>
                  <h2 className="text-lg font-bold">Review {reviewing.reference}</h2>
                  <p className="text-sm text-[var(--color-text-secondary)]">{reviewing.exception_count} exception(s) recorded. Closing records your name and is final.</p>
                </div>
                <button type="button" onClick={() => setReviewing(null)} className="min-h-9 rounded-lg border px-3 text-sm font-semibold" style={controlStyle}>Cancel</button>
              </div>
              <div className="mt-3 grid gap-3 md:grid-cols-2">
                <label className="text-sm">Outcome
                  <select className={controlClass} style={controlStyle} value={reviewStatus} onChange={(event) => setReviewStatus(event.target.value as typeof reviewStatus)}>
                    <option value="reconciled">Reconciled (no exceptions)</option>
                    <option value="reconciled_with_exceptions">Reconciled with exceptions</option>
                  </select>
                </label>
                <label className="text-sm">Resolution notes
                  <textarea className={`${controlClass} min-h-20`} style={controlStyle} value={reviewNotes} onChange={(event) => setReviewNotes(event.target.value)} placeholder="How were the exceptions resolved or accepted?" />
                </label>
              </div>
              <button type="button" disabled={busy} onClick={() => void review()} className="mt-3 min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-semibold text-white disabled:opacity-50">Close reconciliation</button>
            </section>
          )}
        </>
      )}
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-[var(--color-text-secondary)]">{label}</dt>
      <dd className="font-semibold tabular-nums">{value}</dd>
    </div>
  );
}
