import { useCallback, useEffect, useState } from "react";
import { RefreshCw, ShieldCheck } from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { hasAnyPermission } from "@/lib/permissionAccess";
import { dateOnly, dateTime, money, titleCase } from "@/components/financial/format";

type Amount = { minor: number; decimal: string };

type ConnectionStatus = {
  provider: string;
  configured: boolean;
  settlement_enabled: boolean;
  state: string;
  state_label: string;
  currency: string;
  read_only: boolean;
  last_success_at: string | null;
  last_failure_at: string | null;
  last_error: { state: string; message: string; http_status: number | null } | null;
  restrictions: string[];
};

type Settlement = {
  provider: string;
  id: string | null;
  status: string;
  status_is_terminal: boolean;
  currency: string | null;
  currency_supported: boolean;
  settlement_date: string | null;
  provider_amounts: Record<string, Amount>;
  transactions_count: number | null;
  created_at: string | null;
  updated_at: string | null;
};

type ProviderTransaction = {
  id: string | null;
  reference: string | null;
  status: string;
  currency: string | null;
  currency_supported: boolean;
  amount: Amount | null;
  fees: Amount | null;
  channel: string | null;
  paid_at: string | null;
  settlement: string | null;
};

type LocalRecord = {
  id: number;
  transaction_reference: string | null;
  provider_reference: string | null;
  status: string;
  currency: string;
  amount: Amount;
  received_at: string | null;
};

type PreviewRow = {
  classification: string;
  classification_label: string;
  explanation: string;
  reference: string | null;
  provider: ProviderTransaction | null;
  local: LocalRecord | null;
  difference: Amount | null;
};

type Preview = {
  settlement: Settlement;
  currency: string | null;
  provider_source: string;
  summary: {
    provider_transactions: number;
    local_records: number;
    classifications: Record<string, number>;
    provider_total: Amount;
    matched_local_total: Amount;
    difference: Amount;
    quarantined: number;
    needs_attention: number;
  };
  local_scope: { provider: string; status: string; currency: string | null; date_basis: string; limit: number; truncated: boolean };
  rows: PreviewRow[];
  read_only: boolean;
  mutations_applied: boolean;
  restrictions: Record<string, string>;
  generated_at: string;
};

type ListMeta = { page: number; page_count: number; per_page: number | null; total: number; filtered: boolean };

const controlClass = "mt-1 min-h-10 w-full rounded-lg border bg-[var(--color-input-background)] px-2 text-sm";
const controlStyle = { borderColor: "var(--color-border)" } as const;

const ATTENTION_CLASSIFICATIONS = new Set([
  "amount_mismatch",
  "currency_mismatch",
  "provider_only",
  "local_only",
  "duplicate_provider_transaction",
  "other_settlement",
  "refund_or_reversal",
  "undetermined",
]);

function stateTone(state: string): string {
  switch (state) {
    case "authentication_failed":
      return "border-[var(--color-danger)]/40 text-[var(--color-danger)]";
    case "provider_unavailable":
    case "rate_limited":
    case "invalid_response":
    case "request_rejected":
    case "not_configured":
      return "border-[var(--color-warning)]/40 text-[var(--color-warning)]";
    case "connected":
      return "border-[var(--color-accent)]/40 text-[var(--color-accent)]";
    default:
      return "border-[var(--color-border)] text-[var(--color-text-secondary)]";
  }
}

function apiError(cause: unknown): { state?: string; message: string } {
  const response = (cause as { response?: { data?: { state?: string; message?: string } } }).response;
  return {
    state: response?.data?.state,
    message: response?.data?.message ?? "Paystack settlement data could not be loaded.",
  };
}

function providerAmount(amounts: Record<string, Amount>, key: string): string {
  return amounts[key] ? money("GHS", amounts[key].decimal) : "—";
}

/**
 * Read-only Paystack settlement section for the reconciliation workbench.
 *
 * Retrieval success, an inspected settlement, a generated preview and a
 * financially reconciled settlement are four different states and are labelled
 * as such. Nothing in this panel posts a fee, matches a receipt, closes a run or
 * writes a ledger row.
 */
export default function PaystackSettlementPanel() {
  const { user } = useAuth();
  const permissions = user?.permissions ?? [];
  const canView = hasAnyPermission(permissions, ["financials.reports.settlements.view"], user?.roles);
  const canTest = hasAnyPermission(permissions, ["financials.reconciliation.manage"], user?.roles);

  const [status, setStatus] = useState<ConnectionStatus | null>(null);
  const [statusLoading, setStatusLoading] = useState(true);
  const [statusError, setStatusError] = useState("");
  const [testing, setTesting] = useState(false);

  const [draft, setDraft] = useState({ status: "", from: "", to: "", search: "" });
  const [settlements, setSettlements] = useState<Settlement[]>([]);
  const [meta, setMeta] = useState<ListMeta | null>(null);
  const [page, setPage] = useState(1);
  const [listLoading, setListLoading] = useState(false);
  const [listError, setListError] = useState<{ state?: string; message: string } | null>(null);

  const [selected, setSelected] = useState<Settlement | null>(null);
  const [transactions, setTransactions] = useState<{ source: string; rows: ProviderTransaction[] } | null>(null);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState("");

  const loadStatus = useCallback(async () => {
    setStatusLoading(true);
    setStatusError("");
    try {
      const response = await api.get("/admin/reconciliation/paystack/status");
      setStatus(response.data.data as ConnectionStatus);
    } catch (cause) {
      const statusCode = (cause as { response?: { status?: number } }).response?.status;
      setStatusError(statusCode === 403 ? "Your account does not have permission to view settlement data." : apiError(cause).message);
      setStatus(null);
    } finally {
      setStatusLoading(false);
    }
  }, []);

  useEffect(() => {
    if (canView) void loadStatus();
  }, [canView, loadStatus]);

  const loadSettlements = useCallback(async (nextPage: number, filters: { status: string; from: string; to: string; search: string }) => {
    setListLoading(true);
    setListError(null);
    try {
      const response = await api.get("/admin/reconciliation/paystack/settlements", {
        params: {
          page: nextPage,
          per_page: 20,
          status: filters.status || undefined,
          from: filters.from || undefined,
          to: filters.to || undefined,
          search: filters.search || undefined,
        },
      });
      setSettlements((response.data.data ?? []) as Settlement[]);
      setMeta((response.data.meta ?? null) as ListMeta | null);
      setPage(nextPage);
    } catch (cause) {
      setListError(apiError(cause));
      setSettlements([]);
      setMeta(null);
    } finally {
      setListLoading(false);
    }
  }, []);

  useEffect(() => {
    if (status?.configured) void loadSettlements(1, { status: "", from: "", to: "", search: "" });
  }, [status?.configured, loadSettlements]);

  const testConnection = async () => {
    setTesting(true);
    setStatusError("");
    try {
      const response = await api.post("/admin/reconciliation/paystack/connection-test");
      const next = response.data.data as ConnectionStatus;
      setStatus(next);
      if (next.configured) await loadSettlements(1, draft);
    } catch (cause) {
      setStatusError(apiError(cause).message);
    } finally {
      setTesting(false);
    }
  };

  const openSettlement = async (settlement: Settlement) => {
    if (!settlement.id) return;
    setSelected(settlement);
    setTransactions(null);
    setPreview(null);
    setActionError("");
    setBusy(true);
    try {
      const [detail, transactionResponse] = await Promise.all([
        api.get(`/admin/reconciliation/paystack/settlements/${settlement.id}`),
        api.get(`/admin/reconciliation/paystack/settlements/${settlement.id}/transactions`),
      ]);
      setSelected(detail.data.data as Settlement);
      setTransactions({ source: transactionResponse.data.source, rows: (transactionResponse.data.data ?? []) as ProviderTransaction[] });
    } catch (cause) {
      setActionError(apiError(cause).message);
    } finally {
      setBusy(false);
    }
  };

  const generatePreview = async () => {
    if (!selected?.id) return;
    setBusy(true);
    setActionError("");
    try {
      const response = await api.get(`/admin/reconciliation/paystack/settlements/${selected.id}/preview`);
      setPreview(response.data.data as Preview);
    } catch (cause) {
      setActionError(apiError(cause).message);
    } finally {
      setBusy(false);
    }
  };

  if (!canView) {
    return (
      <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
        <h2 className="text-lg font-bold">Paystack settlements</h2>
        <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Your account does not have permission to view provider settlement data.</p>
      </section>
    );
  }

  return (
    <section className="space-y-3">
      <div className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="flex items-center gap-2 text-lg font-bold">
              Paystack settlements
              <span className="rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-[var(--color-text-secondary)]" style={controlStyle}>Read-only</span>
            </h2>
            <p className="mt-1 max-w-2xl text-sm text-[var(--color-text-secondary)]">
              Provider-reported settlement batches compared with local receipts. Retrieving or previewing data never changes a receipt, allocation, refund, credit note or ledger row.
            </p>
          </div>
          {status && (
            <span className={`rounded-full border px-3 py-1 text-xs font-bold uppercase tracking-wide ${stateTone(status.state)}`} style={controlStyle}>
              {status.state_label}
            </span>
          )}
        </div>

        {statusLoading && <p className="mt-3 text-sm text-[var(--color-text-secondary)]">Checking the provider connection…</p>}

        {statusError && (
          <div role="alert" className="mt-3 rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">
            {statusError}
            <button type="button" onClick={() => void loadStatus()} className="ml-2 min-h-8 rounded-lg border px-2 font-semibold" style={controlStyle}>Retry</button>
          </div>
        )}

        {status && (
          <div className="mt-3 space-y-2">
            <dl className="grid gap-2 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-[var(--color-text-secondary)]">Last successful retrieval</dt>
                <dd className="font-semibold">{status.last_success_at ? dateTime(status.last_success_at) : "—"}</dd>
              </div>
              <div>
                <dt className="text-[var(--color-text-secondary)]">Last failed retrieval</dt>
                <dd className="font-semibold">{status.last_failure_at ? dateTime(status.last_failure_at) : "—"}</dd>
              </div>
            </dl>

            {status.last_error && (
              <p className="rounded-lg bg-[var(--color-warning)]/10 p-2 text-sm">
                <strong>{titleCase(status.last_error.state)}.</strong> {status.last_error.message}
                {status.last_error.http_status ? <span className="text-[var(--color-text-secondary)]"> (HTTP {status.last_error.http_status})</span> : null}
              </p>
            )}

            {!status.configured && (
              <div className="rounded-lg bg-[var(--color-warning)]/10 p-3 text-sm">
                <p><strong>Not configured.</strong> Set <code>PAYSTACK_SECRET_KEY</code> and <code>PAYSTACK_SETTLEMENT_ENABLED=true</code> for the Ghana (GHS) merchant account. No test key is substituted automatically.</p>
              </div>
            )}

            <ul className="list-disc pl-5 text-xs text-[var(--color-text-secondary)]">
              {status.restrictions.map((item) => <li key={item}>{item}</li>)}
            </ul>

            {canTest && (
              <button
                type="button"
                disabled={testing}
                onClick={() => void testConnection()}
                className="inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 text-sm font-semibold disabled:opacity-50"
                style={controlStyle}
              >
                <ShieldCheck size={16} /> {testing ? "Testing connection…" : "Test provider connection"}
              </button>
            )}
          </div>
        )}
      </div>

      {status?.configured && (
        <>
          <div className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
              <label className="text-sm">Provider status
                <select className={controlClass} style={controlStyle} value={draft.status} onChange={(event) => setDraft({ ...draft, status: event.target.value })}>
                  <option value="">Any status</option>
                  <option value="success">Success</option>
                  <option value="processing">Processing</option>
                  <option value="pending">Pending</option>
                  <option value="failed">Failed</option>
                </select>
              </label>
              <label className="text-sm">From<input type="date" className={controlClass} style={controlStyle} value={draft.from} onChange={(event) => setDraft({ ...draft, from: event.target.value })} /></label>
              <label className="text-sm">To<input type="date" className={controlClass} style={controlStyle} value={draft.to} onChange={(event) => setDraft({ ...draft, to: event.target.value })} /></label>
              <label className="text-sm">Search this page
                <input className={controlClass} style={controlStyle} value={draft.search} onChange={(event) => setDraft({ ...draft, search: event.target.value })} placeholder="Settlement ID, date or status" />
              </label>
              <div className="flex items-end">
                <button
                  type="button"
                  onClick={() => void loadSettlements(1, draft)}
                  className="inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-lg border text-sm font-semibold"
                  style={controlStyle}
                >
                  <RefreshCw size={15} /> Apply filters
                </button>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-base font-bold">Settlements</h3>
              <p className="text-xs text-[var(--color-text-secondary)]">
                Provider-reported figures in GHS minor units (1 GHS = 100 pesewas). A provider status is shown exactly as reported.
              </p>
            </div>

            {listError && (
              <div role="alert" className="mt-3 rounded-xl bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">
                <strong>{listError.state ? titleCase(listError.state) : "Unavailable"}.</strong> {listError.message}
                <button type="button" onClick={() => void loadSettlements(page, draft)} className="ml-2 min-h-8 rounded-lg border px-2 font-semibold" style={controlStyle}>Retry</button>
              </div>
            )}

            {listLoading && <p className="mt-3 text-sm text-[var(--color-text-secondary)]">Loading settlements…</p>}

            {!listLoading && !listError && settlements.length === 0 && (
              <p className="mt-3 rounded-lg border border-dashed p-4 text-center text-sm text-[var(--color-text-secondary)]" style={controlStyle}>
                No settlements were returned for these filters.
              </p>
            )}

            {!listLoading && settlements.length > 0 && (
              <div className="mt-3 overflow-x-auto">
                <table className="w-full min-w-[880px] text-left text-sm">
                  <thead className="text-[var(--color-text-secondary)]"><tr>
                    <th className="p-2">Settlement</th><th className="p-2">Date</th><th className="p-2">Provider status</th>
                    <th className="p-2">Currency</th><th className="p-2 text-right">Gross (provider)</th>
                    <th className="p-2 text-right">Fees (provider)</th><th className="p-2 text-right">Net (provider)</th>
                    <th className="p-2 text-right">Txns</th><th className="p-2">Action</th>
                  </tr></thead>
                  <tbody>
                    {settlements.map((settlement) => (
                      <tr key={settlement.id} className="border-t align-top" style={controlStyle}>
                        <td className="p-2 font-semibold">{settlement.id}</td>
                        <td className="p-2">{settlement.settlement_date ? dateOnly(settlement.settlement_date) : "—"}</td>
                        <td className="p-2">
                          <span className="font-semibold">{titleCase(settlement.status)}</span>
                          <small className="block text-[var(--color-text-secondary)]">
                            {settlement.status_is_terminal ? "provider-reported, final" : "provider-reported, not completed"}
                          </small>
                        </td>
                        <td className="p-2">
                          {settlement.currency ?? "—"}
                          {!settlement.currency_supported && <small className="block text-[var(--color-warning)]">quarantined</small>}
                        </td>
                        <td className="p-2 text-right tabular-nums">{providerAmount(settlement.provider_amounts, "total_amount")}</td>
                        <td className="p-2 text-right tabular-nums">{providerAmount(settlement.provider_amounts, "total_fees")}</td>
                        <td className="p-2 text-right tabular-nums">{providerAmount(settlement.provider_amounts, "effective_amount")}</td>
                        <td className="p-2 text-right tabular-nums">{settlement.transactions_count ?? "—"}</td>
                        <td className="p-2">
                          <button type="button" onClick={() => void openSettlement(settlement)} className="min-h-9 rounded-lg border px-2 font-semibold" style={controlStyle}>
                            Inspect
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {meta && meta.page_count > 1 && (
              <div className="mt-3 flex items-center justify-between gap-2 text-sm">
                <button
                  type="button"
                  disabled={page <= 1 || listLoading}
                  onClick={() => void loadSettlements(page - 1, draft)}
                  className="min-h-9 rounded-lg border px-3 font-semibold disabled:opacity-40"
                  style={controlStyle}
                >Previous</button>
                <span className="text-[var(--color-text-secondary)]">Page {meta.page} of {meta.page_count}</span>
                <button
                  type="button"
                  disabled={page >= meta.page_count || listLoading}
                  onClick={() => void loadSettlements(page + 1, draft)}
                  className="min-h-9 rounded-lg border px-3 font-semibold disabled:opacity-40"
                  style={controlStyle}
                >Next</button>
              </div>
            )}
          </div>

          {selected && (
            <div className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h3 className="text-base font-bold">Settlement {selected.id}</h3>
                  <p className="text-sm text-[var(--color-text-secondary)]">
                    {selected.settlement_date ?? "No settlement date reported"} · {titleCase(selected.status)} · {selected.currency ?? "unknown currency"}
                  </p>
                </div>
                <button type="button" onClick={() => { setSelected(null); setTransactions(null); setPreview(null); setActionError(""); }} className="min-h-9 rounded-lg border px-3 text-sm font-semibold" style={controlStyle}>Close</button>
              </div>

              <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                <div><dt className="text-[var(--color-text-secondary)]">Gross (provider)</dt><dd className="font-semibold tabular-nums">{providerAmount(selected.provider_amounts, "total_amount")}</dd></div>
                <div><dt className="text-[var(--color-text-secondary)]">Fees (provider)</dt><dd className="font-semibold tabular-nums">{providerAmount(selected.provider_amounts, "total_fees")}</dd></div>
                <div><dt className="text-[var(--color-text-secondary)]">Net (provider)</dt><dd className="font-semibold tabular-nums">{providerAmount(selected.provider_amounts, "effective_amount")}</dd></div>
              </dl>

              {actionError && <p role="alert" className="mt-3 rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{actionError}</p>}

              <div className="mt-3 flex flex-wrap gap-2">
                <button type="button" disabled={busy} onClick={() => void generatePreview()} className="min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-semibold text-white disabled:opacity-50">
                  {busy ? "Working…" : preview ? "Regenerate read-only preview" : "Generate read-only preview"}
                </button>
              </div>

              {transactions && (
                <div className="mt-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <h4 className="text-sm font-bold">Provider transactions ({transactions.rows.length})</h4>
                    <span className="text-xs text-[var(--color-text-secondary)]">
                      Retrieved via {transactions.source === "export" ? "the provider CSV export" : "paginated provider retrieval"} · provider-reported
                    </span>
                  </div>
                  <div className="mt-2 overflow-x-auto">
                    <table className="w-full min-w-[760px] text-left text-sm">
                      <thead className="text-[var(--color-text-secondary)]"><tr>
                        <th className="p-2">Reference</th><th className="p-2">Provider status</th><th className="p-2">Currency</th>
                        <th className="p-2 text-right">Amount</th><th className="p-2 text-right">Fees</th><th className="p-2">Channel</th><th className="p-2">Paid</th>
                      </tr></thead>
                      <tbody>
                        {transactions.rows.map((transaction, index) => (
                          <tr key={`${transaction.id ?? transaction.reference ?? "row"}-${index}`} className="border-t" style={controlStyle}>
                            <td className="p-2 font-semibold">{transaction.reference ?? "—"}</td>
                            <td className="p-2">{titleCase(transaction.status)}</td>
                            <td className="p-2">{transaction.currency ?? "—"}</td>
                            <td className="p-2 text-right tabular-nums">{transaction.amount ? money(transaction.currency ?? "GHS", transaction.amount.decimal) : "—"}</td>
                            <td className="p-2 text-right tabular-nums">{transaction.fees ? money(transaction.currency ?? "GHS", transaction.fees.decimal) : "—"}</td>
                            <td className="p-2">{transaction.channel ? titleCase(transaction.channel) : "—"}</td>
                            <td className="p-2">{transaction.paid_at ? dateTime(transaction.paid_at) : "—"}</td>
                          </tr>
                        ))}
                        {transactions.rows.length === 0 && (
                          <tr><td colSpan={7} className="p-4 text-center text-[var(--color-text-secondary)]">The provider returned no transactions for this settlement.</td></tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {preview && (
                <div className="mt-5 space-y-3">
                  <div className="grid gap-2 sm:grid-cols-3">
                    <div className="rounded-xl border p-3" style={controlStyle}>
                      <p className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Provider data retrieved</p>
                      <p className="text-sm font-semibold">{preview.summary.provider_transactions} transaction(s)</p>
                      <p className="text-xs text-[var(--color-text-secondary)]">{dateTime(preview.generated_at)}</p>
                    </div>
                    <div className="rounded-xl border p-3" style={controlStyle}>
                      <p className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Preview generated</p>
                      <p className="text-sm font-semibold">{preview.summary.needs_attention} row(s) need attention</p>
                      <p className="text-xs text-[var(--color-text-secondary)]">Nothing was saved.</p>
                    </div>
                    <div className="rounded-xl border border-[var(--color-warning)]/40 p-3">
                      <p className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Financially reconciled</p>
                      <p className="text-sm font-semibold text-[var(--color-warning)]">No — read-only preview</p>
                      <p className="text-xs text-[var(--color-text-secondary)]">No run closed, no ledger entry written.</p>
                    </div>
                  </div>

                  <dl className="grid gap-2 text-sm sm:grid-cols-3">
                    <div><dt className="text-[var(--color-text-secondary)]">Provider total (GHS)</dt><dd className="font-semibold tabular-nums">{money(preview.currency ?? "GHS", preview.summary.provider_total.decimal)}</dd></div>
                    <div><dt className="text-[var(--color-text-secondary)]">Matched local total</dt><dd className="font-semibold tabular-nums">{money(preview.currency ?? "GHS", preview.summary.matched_local_total.decimal)}</dd></div>
                    <div><dt className="text-[var(--color-text-secondary)]">Difference</dt><dd className="font-semibold tabular-nums">{money(preview.currency ?? "GHS", preview.summary.difference.decimal)}</dd></div>
                  </dl>

                  <div className="flex flex-wrap gap-2">
                    {Object.entries(preview.summary.classifications).map(([key, count]) => (
                      <span
                        key={key}
                        className={`rounded-full border px-2 py-1 text-xs font-semibold ${count > 0 && ATTENTION_CLASSIFICATIONS.has(key) ? "border-[var(--color-warning)]/40 text-[var(--color-warning)]" : "text-[var(--color-text-secondary)]"}`}
                        style={controlStyle}
                      >
                        {titleCase(key)}: {count}
                      </span>
                    ))}
                  </div>

                  {preview.summary.quarantined > 0 && (
                    <p className="rounded-lg bg-[var(--color-warning)]/10 p-2 text-sm">
                      {preview.summary.quarantined} provider transaction(s) are not in GHS. They are quarantined and excluded from the totals; no conversion is applied.
                    </p>
                  )}

                  <p className="text-xs text-[var(--color-text-secondary)]">
                    Local scope: {preview.local_scope.status} {preview.local_scope.provider} receipts in {preview.local_scope.currency ?? "the settlement currency"} by {preview.local_scope.date_basis}.
                    {preview.local_scope.truncated ? " The local comparison was truncated; narrow the period to see every receipt." : ""}
                  </p>

                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[960px] text-left text-sm">
                      <thead className="text-[var(--color-text-secondary)]"><tr>
                        <th className="p-2">Classification</th><th className="p-2">Reference</th>
                        <th className="p-2 text-right">Provider</th><th className="p-2 text-right">Local</th>
                        <th className="p-2 text-right">Difference</th><th className="p-2">Explanation</th>
                      </tr></thead>
                      <tbody>
                        {preview.rows.map((row, index) => (
                          <tr key={`${row.classification}-${row.reference ?? "none"}-${index}`} className="border-t align-top" style={controlStyle}>
                            <td className={`p-2 font-semibold ${ATTENTION_CLASSIFICATIONS.has(row.classification) ? "text-[var(--color-warning)]" : ""}`}>
                              {row.classification_label}
                            </td>
                            <td className="p-2">
                              {row.reference ?? "—"}
                              {row.local && <small className="block text-[var(--color-text-secondary)]">local {row.local.transaction_reference ?? row.local.id}</small>}
                            </td>
                            <td className="p-2 text-right tabular-nums">
                              {row.provider?.amount ? money(row.provider.currency ?? "GHS", row.provider.amount.decimal) : "—"}
                              {row.provider && <small className="block text-[var(--color-text-secondary)]">{titleCase(row.provider.status)}</small>}
                            </td>
                            <td className="p-2 text-right tabular-nums">
                              {row.local ? money(row.local.currency, row.local.amount.decimal) : "—"}
                              {row.local && <small className="block text-[var(--color-text-secondary)]">{titleCase(row.local.status)}</small>}
                            </td>
                            <td className="p-2 text-right tabular-nums">{row.difference ? money(preview.currency ?? "GHS", row.difference.decimal) : "—"}</td>
                            <td className="p-2 text-[var(--color-text-secondary)]">{row.explanation}</td>
                          </tr>
                        ))}
                        {preview.rows.length === 0 && (
                          <tr><td colSpan={6} className="p-4 text-center text-[var(--color-text-secondary)]">The provider returned no transactions to compare.</td></tr>
                        )}
                      </tbody>
                    </table>
                  </div>

                  <ul className="list-disc pl-5 text-xs text-[var(--color-text-secondary)]">
                    {Object.entries(preview.restrictions).map(([decision, note]) => (
                      <li key={decision}><strong>{decision}:</strong> {note}</li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}
        </>
      )}
    </section>
  );
}
