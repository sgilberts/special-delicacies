import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { ShieldAlert } from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { hasAnyPermission } from "@/lib/permissionAccess";
import { change, dateTime, daysAgo, money, titleCase, today } from "@/components/financial/format";
import { REPORT_LINKS } from "@/components/financial/reportDefinitions";

type Trend = {
  date?: string;
  period?: string;
  cash_receipts: string;
  online_receipts: string;
  receipts_total: string;
  allocated: string;
  invoices_issued: string;
  refunds_confirmed: string;
  credit_notes_issued: string;
  unallocated_movement: string;
};

type Dashboard = {
  period: { from: string; to: string; timezone: string; comparison: { from: string; to: string } };
  currency: string;
  currencies: string[];
  overview: Record<string, any> & { previous: Record<string, string>; definitions: { key: string; label: string; basis: string }[] };
  trends: { daily: Trend[]; weekly: Trend[]; monthly: Trend[] };
  operational: Record<string, any[]>;
};

const controlClass = "mt-1 min-h-10 w-full rounded-lg border bg-[var(--color-input-background)] px-2 text-sm";
const controlStyle = { borderColor: "var(--color-border)" } as const;

function Delta({ current, previous }: { current: string; previous?: string }) {
  if (!previous) return null;
  const value = change(current, previous);
  if (value === null) return <span className="text-xs text-[var(--color-text-secondary)]">no previous baseline</span>;
  const up = value >= 0;
  return (
    <span className={`text-xs font-semibold ${up ? "text-[var(--color-success)]" : "text-[var(--color-danger)]"}`}>
      {up ? "▲" : "▼"} {Math.abs(value).toFixed(1)}% vs previous period
    </span>
  );
}

function StackedBars({ rows, labelKey }: { rows: Trend[]; labelKey: "date" | "period" }) {
  const max = Math.max(1, ...rows.map((row) => Number(row.cash_receipts) + Number(row.online_receipts)));
  return (
    <div>
      <div className="flex items-end gap-1 overflow-x-auto" style={{ height: 170 }}>
        {rows.map((row) => {
          const cash = Number(row.cash_receipts);
          const online = Number(row.online_receipts);
          return (
            <div key={String(row[labelKey])} className="flex min-w-[8px] flex-1 flex-col items-center justify-end" title={`${row[labelKey]}: cash ${row.cash_receipts}, online ${row.online_receipts}`}>
              <div className="flex w-full flex-col justify-end">
                <div style={{ height: Math.round((online / max) * 140), backgroundColor: "var(--color-accent)" }} />
                <div style={{ height: Math.round((cash / max) * 140), backgroundColor: "var(--color-success)" }} />
              </div>
            </div>
          );
        })}
      </div>
      <div className="mt-2 flex flex-wrap gap-3 text-xs text-[var(--color-text-secondary)]">
        <span className="inline-flex items-center gap-1"><span className="inline-block h-2 w-2" style={{ backgroundColor: "var(--color-success)" }} /> Cash</span>
        <span className="inline-flex items-center gap-1"><span className="inline-block h-2 w-2" style={{ backgroundColor: "var(--color-accent)" }} /> Online</span>
      </div>
    </div>
  );
}

function GroupedBars({ rows, labelKey }: { rows: Trend[]; labelKey: "date" | "period" }) {
  const series = [
    { key: "invoices_issued" as const, label: "Invoices issued", color: "var(--color-primary)" },
    { key: "refunds_confirmed" as const, label: "Refunds confirmed", color: "var(--color-danger)" },
    { key: "credit_notes_issued" as const, label: "Credit notes", color: "var(--color-warning)" },
  ];
  const max = Math.max(1, ...rows.flatMap((row) => series.map((item) => Number(row[item.key]))));
  return (
    <div>
      <div className="flex items-end gap-1 overflow-x-auto" style={{ height: 170 }}>
        {rows.map((row) => (
          <div key={String(row[labelKey])} className="flex min-w-[10px] flex-1 items-end justify-center gap-[1px]" title={`${row[labelKey]}: invoices ${row.invoices_issued}, refunds ${row.refunds_confirmed}, credit notes ${row.credit_notes_issued}`}>
            {series.map((item) => (
              <div key={item.key} className="w-1/4" style={{ height: Math.round((Number(row[item.key]) / max) * 140), backgroundColor: item.color }} />
            ))}
          </div>
        ))}
      </div>
      <div className="mt-2 flex flex-wrap gap-3 text-xs text-[var(--color-text-secondary)]">
        {series.map((item) => (
          <span key={item.key} className="inline-flex items-center gap-1"><span className="inline-block h-2 w-2" style={{ backgroundColor: item.color }} /> {item.label}</span>
        ))}
      </div>
    </div>
  );
}

export default function FinancialDashboardPage() {
  const { user } = useAuth();
  const permissions = user?.permissions ?? [];
  const canView = hasAnyPermission(permissions, ["financials.reports.dashboard.view"], user?.roles);

  const [from, setFrom] = useState(daysAgo(29));
  const [to, setTo] = useState(today());
  const [currency, setCurrency] = useState("GHS");
  const [granularity, setGranularity] = useState<"daily" | "weekly" | "monthly">("daily");
  const [data, setData] = useState<Dashboard | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    if (!canView) return;
    setLoading(true);
    setError("");
    try {
      const response = await api.get<{ data: Dashboard }>("/admin/reports/dashboard", { params: { from, to, currency } });
      setData(response.data.data);
    } catch (cause) {
      const status = (cause as { response?: { status?: number } }).response?.status;
      setError(status === 403 ? "Your account does not have permission to view the financial dashboard." : "The financial dashboard could not be loaded.");
      setData(null);
    } finally {
      setLoading(false);
    }
  }, [canView, from, to, currency]);

  useEffect(() => {
    void load();
  }, [load]);

  const definitions = useMemo(() => {
    const map: Record<string, string> = {};
    (data?.overview.definitions ?? []).forEach((item) => { map[item.key] = item.basis; });
    return map;
  }, [data]);

  if (!canView) {
    return (
      <section className="flex min-h-[40vh] flex-col items-center justify-center gap-3 text-center">
        <ShieldAlert size={40} className="text-[var(--color-danger)]" />
        <h1 className="text-xl font-bold">403 — Access denied</h1>
        <p className="text-sm text-[var(--color-text-secondary)]">Your account does not have permission to view the financial dashboard.</p>
      </section>
    );
  }

  const overview = data?.overview;
  const previous = overview?.previous ?? {};
  const cards: { key: string; label: string; value: string; compare?: boolean }[] = overview ? [
    { key: "receipts_total", label: "Successful receipts", value: overview.receipts_total, compare: true },
    { key: "cash_receipts", label: "Cash receipts", value: overview.cash_receipts, compare: true },
    { key: "online_receipts", label: "Online receipts", value: overview.online_receipts, compare: true },
    { key: "allocated_total", label: "Allocated receipts", value: overview.allocated_total, compare: true },
    { key: "unallocated_total", label: "Unallocated receipts", value: overview.unallocated_total },
    { key: "invoice_value_total", label: "Invoice value issued", value: overview.invoice_value_total, compare: true },
    { key: "receivables_outstanding", label: "Outstanding receivables", value: overview.receivables_outstanding, compare: true },
    { key: "customer_credit_balance", label: "Customer credit", value: overview.customer_credit_balance },
    { key: "organisation_credit_balance", label: "Organisation credit", value: overview.organisation_credit_balance },
    { key: "refunds_pending", label: "Refunds pending", value: overview.refunds_pending },
    { key: "refunds_completed", label: "Refunds completed", value: overview.refunds_completed, compare: true },
    { key: "credit_notes_issued", label: "Credit notes issued", value: overview.credit_notes_issued, compare: true },
  ] : [];

  const trendRows = data?.trends[granularity] ?? [];
  const labelKey = granularity === "daily" ? "date" : "period";

  return (
    <div className="space-y-4">
      <header>
        <p className="text-xs font-bold uppercase tracking-widest text-[var(--color-accent)]">Financial management</p>
        <h1 className="mt-1 text-2xl font-black">Financial Dashboard</h1>
        <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
          Receipts, allocations, credit, refunds and credit notes stay separate figures. Every amount is calculated by the backend from the financial ledger.
        </p>
      </header>

      <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label className="text-sm">From<input type="date" className={controlClass} style={controlStyle} value={from} onChange={(event) => setFrom(event.target.value)} /></label>
          <label className="text-sm">To<input type="date" className={controlClass} style={controlStyle} value={to} onChange={(event) => setTo(event.target.value)} /></label>
          <label className="text-sm">Currency
            <select className={controlClass} style={controlStyle} value={currency} onChange={(event) => setCurrency(event.target.value)}>
              {(data?.currencies ?? ["GHS"]).map((item) => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          <div className="flex items-end">
            <button type="button" onClick={() => void load()} className="min-h-10 w-full rounded-lg border text-sm font-semibold" style={controlStyle}>Refresh</button>
          </div>
        </div>
        {data && <p className="mt-2 text-xs text-[var(--color-text-secondary)]">Showing {data.period.from} to {data.period.to} ({data.period.timezone}); compared with {data.period.comparison.from} to {data.period.comparison.to}.</p>}
      </section>

      {error && (
        <div role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-4 text-sm text-[var(--color-danger)]">
          <p>{error}</p>
          <button type="button" onClick={() => void load()} className="mt-2 min-h-9 rounded-lg border px-3 font-semibold" style={controlStyle}>Retry</button>
        </div>
      )}

      {loading && <p className="text-sm text-[var(--color-text-secondary)]">Loading financial figures…</p>}

      {!loading && data && (
        <>
          <section aria-label="Financial overview" className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            {cards.map((card) => (
              <article key={card.key} className="rounded-xl border bg-[var(--color-surface)] p-3" style={controlStyle}>
                <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{card.label}</p>
                <p className="mt-1 text-xl font-black tabular-nums">{money(data.currency, card.value)}</p>
                {card.compare && <Delta current={card.value} previous={previous[card.key]} />}
                {definitions[card.key] && <p className="mt-1 text-[11px] leading-snug text-[var(--color-text-secondary)]">{definitions[card.key]}</p>}
              </article>
            ))}
          </section>

          <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-lg font-bold">Financial trends</h2>
              <div className="flex gap-1" role="group" aria-label="Trend granularity">
                {(["daily", "weekly", "monthly"] as const).map((item) => (
                  <button key={item} type="button" onClick={() => setGranularity(item)} aria-pressed={granularity === item}
                    className={`min-h-9 rounded-lg border px-3 text-sm font-semibold ${granularity === item ? "bg-[var(--color-surface-secondary)] text-[var(--color-accent)]" : ""}`}
                    style={controlStyle}>{titleCase(item)}</button>
                ))}
              </div>
            </div>
            {trendRows.length === 0 ? (
              <p className="mt-3 text-sm text-[var(--color-text-secondary)]">No activity in this period.</p>
            ) : (
              <div className="mt-3 grid gap-6 lg:grid-cols-2">
                <div>
                  <h3 className="text-sm font-semibold">Cash versus online collections</h3>
                  <StackedBars rows={trendRows} labelKey={labelKey} />
                </div>
                <div>
                  <h3 className="text-sm font-semibold">Invoices, refunds and credit notes</h3>
                  <GroupedBars rows={trendRows} labelKey={labelKey} />
                </div>
              </div>
            )}
          </section>

          <section className="grid gap-3 lg:grid-cols-2">
            <Panel title="Unallocated receipts needing attention" link="/admin/financials/reports/unallocated">
              {(data.operational.unallocated_receipts ?? []).map((row) => (
                <li key={row.id} className="flex items-start justify-between gap-2 border-t py-2 text-sm" style={controlStyle}>
                  <span>{row.transaction_reference}<small className="block text-[var(--color-text-secondary)]">{row.payer?.name} · {row.age_days} days</small></span>
                  <span className="font-semibold tabular-nums">{money(row.currency, row.available_amount)}</span>
                </li>
              ))}
            </Panel>
            <Panel title="Invoices with outstanding balances" link="/admin/financials/reports/receivables">
              {(data.operational.outstanding_invoices ?? []).map((row) => (
                <li key={row.id} className="flex items-start justify-between gap-2 border-t py-2 text-sm" style={controlStyle}>
                  <span>{row.invoice_reference}<small className="block text-[var(--color-text-secondary)]">{row.payer?.name} · {row.days_overdue > 0 ? `${row.days_overdue} days overdue` : "current"}</small></span>
                  <span className="font-semibold tabular-nums">{money(row.currency, row.outstanding_amount)}</span>
                </li>
              ))}
            </Panel>
            <Panel title="Recent receipts" link="/admin/financials/reports/receipts">
              {(data.operational.recent_receipts ?? []).map((row) => (
                <li key={row.id} className="flex items-start justify-between gap-2 border-t py-2 text-sm" style={controlStyle}>
                  <span>{row.transaction_reference}<small className="block text-[var(--color-text-secondary)]">{dateTime(row.received_at)} · {titleCase(row.payment_method)}</small></span>
                  <span className="font-semibold tabular-nums">{money(row.currency, row.amount)}</span>
                </li>
              ))}
            </Panel>
            <Panel title="Recent allocations" link="/admin/financials/reports/allocations">
              {(data.operational.recent_allocations ?? []).map((row) => (
                <li key={row.id} className="flex items-start justify-between gap-2 border-t py-2 text-sm" style={controlStyle}>
                  <span>{row.invoice_reference}<small className="block text-[var(--color-text-secondary)]">{row.receipt_reference} · {dateTime(row.allocated_at)}</small></span>
                  <span className="font-semibold tabular-nums">{money(row.currency, row.allocation_amount)}</span>
                </li>
              ))}
            </Panel>
            <Panel title="Recent refunds" link="/admin/financials/reports/refunds">
              {(data.operational.recent_refunds ?? []).map((row) => (
                <li key={row.id} className="flex items-start justify-between gap-2 border-t py-2 text-sm" style={controlStyle}>
                  <span>{row.refund_reference}<small className="block text-[var(--color-text-secondary)]">{titleCase(row.status)} · {dateTime(row.requested_at)}</small></span>
                  <span className="font-semibold tabular-nums">{money(row.currency, row.amount)}</span>
                </li>
              ))}
            </Panel>
            <Panel title="Recent credit notes" link="/admin/financials/reports/credit-notes">
              {(data.operational.recent_credit_notes ?? []).map((row) => (
                <li key={row.id} className="flex items-start justify-between gap-2 border-t py-2 text-sm" style={controlStyle}>
                  <span>{row.credit_note_number}<small className="block text-[var(--color-text-secondary)]">{row.original_invoice} · {titleCase(row.application_status)}</small></span>
                  <span className="font-semibold tabular-nums">{money(row.currency, row.amount)}</span>
                </li>
              ))}
            </Panel>
          </section>

          <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
            <h2 className="text-lg font-bold">Financial exceptions requiring review</h2>
            <p className="text-sm text-[var(--color-text-secondary)]">Read-only findings. Correcting them is an audited financial operation, never an edit here.</p>
            <ul className="mt-2">
              {(data.operational.exceptions ?? []).map((exception) => (
                <li key={exception.code} className="flex items-center justify-between border-t py-2 text-sm" style={controlStyle}>
                  <span>{exception.code} · {exception.label}</span>
                  <span className={`font-semibold tabular-nums ${exception.code === "TOTAL" ? "" : exception.count > 0 ? "text-[var(--color-danger)]" : "text-[var(--color-text-secondary)]"}`}>{exception.count}</span>
                </li>
              ))}
            </ul>
          </section>

          <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
            <h2 className="text-lg font-bold">Reports</h2>
            <div className="mt-2 flex flex-wrap gap-2">
              {REPORT_LINKS.map((link) => (
                <Link key={link.key} to={link.to} className="min-h-9 rounded-lg border px-3 py-2 text-sm font-semibold" style={controlStyle}>{link.label}</Link>
              ))}
              <Link to="/admin/financials/reconciliation" className="min-h-9 rounded-lg border px-3 py-2 text-sm font-semibold" style={controlStyle}>Reconciliation</Link>
            </div>
          </section>
        </>
      )}
    </div>
  );
}

function Panel({ title, link, children }: { title: string; link: string; children: React.ReactNode }) {
  const items = Array.isArray(children) ? children : [children];
  return (
    <article className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
      <div className="flex items-center justify-between gap-2">
        <h2 className="text-base font-bold">{title}</h2>
        <Link to={link} className="text-sm font-semibold text-[var(--color-accent)]">View report</Link>
      </div>
      <ul className="mt-1">
        {items.filter(Boolean).length === 0
          ? <li className="py-2 text-sm text-[var(--color-text-secondary)]">Nothing to show for this period.</li>
          : children}
      </ul>
    </article>
  );
}
