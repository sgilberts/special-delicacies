import type { ReactNode } from "react";

export type FilterSpec = {
  param: string;
  label: string;
  options: { value: string; label: string }[];
};

export type ReportControls = {
  from: string;
  to: string;
  search: string;
  currency: string;
  values: Record<string, string>;
  setFrom: (value: string) => void;
  setTo: (value: string) => void;
  setSearch: (value: string) => void;
  setCurrency: (value: string) => void;
  setFilter: (param: string, value: string) => void;
};

export type SummaryChip = { label: string; value: string; hint?: string };

const controlClass = "mt-1 min-h-10 w-full rounded-lg border bg-[var(--color-input-background)] px-2 text-sm";
const controlStyle = { borderColor: "var(--color-border)" } as const;

export default function ReportShell({
  title,
  description,
  dateBasis,
  currencies,
  filterSpecs,
  controls,
  summary,
  exportEnabled,
  onExport,
  exporting,
  children,
}: {
  title: string;
  description: string;
  dateBasis: string;
  currencies: string[];
  filterSpecs: FilterSpec[];
  controls: ReportControls;
  summary: SummaryChip[];
  exportEnabled: boolean;
  onExport: () => void;
  exporting: boolean;
  children: ReactNode;
}) {
  return (
    <div className="space-y-4">
      <header>
        <p className="text-xs font-bold uppercase tracking-widest text-[var(--color-accent)]">Financial reporting</p>
        <h1 className="mt-1 text-2xl font-black">{title}</h1>
        <p className="mt-1 text-sm text-[var(--color-text-secondary)]">{description}</p>
        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Date basis: {dateBasis}. Figures are per currency and come from the financial ledger, not from order status.</p>
      </header>

      <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label className="text-sm">From
            <input type="date" className={controlClass} style={controlStyle} value={controls.from} onChange={(event) => controls.setFrom(event.target.value)} />
          </label>
          <label className="text-sm">To
            <input type="date" className={controlClass} style={controlStyle} value={controls.to} onChange={(event) => controls.setTo(event.target.value)} />
          </label>
          <label className="text-sm">Currency
            <select className={controlClass} style={controlStyle} value={controls.currency} onChange={(event) => controls.setCurrency(event.target.value)}>
              {currencies.map((currency) => <option key={currency} value={currency}>{currency}</option>)}
            </select>
          </label>
          <label className="text-sm">Search
            <input className={controlClass} style={controlStyle} value={controls.search} placeholder="Reference, payer, invoice…" onChange={(event) => controls.setSearch(event.target.value)} />
          </label>
          {filterSpecs.map((spec) => (
            <label key={spec.param} className="text-sm">{spec.label}
              <select className={controlClass} style={controlStyle} value={controls.values[spec.param] ?? ""} onChange={(event) => controls.setFilter(spec.param, event.target.value)}>
                <option value="">All</option>
                {spec.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </select>
            </label>
          ))}
        </div>
      </section>

      {summary.length > 0 && (
        <section aria-label="Report totals" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          {summary.map((chip) => (
            <div key={chip.label} className="rounded-xl border bg-[var(--color-surface)] p-3" style={controlStyle} title={chip.hint}>
              <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{chip.label}</p>
              <p className="mt-1 text-lg font-black tabular-nums">{chip.value}</p>
            </div>
          ))}
        </section>
      )}

      <section className="rounded-2xl border bg-[var(--color-surface)] p-4" style={controlStyle}>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-bold">Records</h2>
          {exportEnabled && (
            <button type="button" onClick={onExport} disabled={exporting} className="min-h-9 rounded-lg border px-3 text-sm font-semibold disabled:opacity-50" style={controlStyle}>
              {exporting ? "Preparing CSV…" : "Export CSV"}
            </button>
          )}
        </div>
        {children}
      </section>
    </div>
  );
}

export function ReportPagination({
  page,
  lastPage,
  total,
  onPage,
}: {
  page: number;
  lastPage: number;
  total: number;
  onPage: (page: number) => void;
}) {
  return (
    <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
      <p className="text-[var(--color-text-secondary)]">{total.toLocaleString()} record{total === 1 ? "" : "s"} · page {page} of {Math.max(1, lastPage)}</p>
      <div className="flex gap-2">
        <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className="min-h-9 rounded-lg border px-3 font-semibold disabled:opacity-40" style={controlStyle}>Previous</button>
        <button type="button" disabled={page >= lastPage} onClick={() => onPage(page + 1)} className="min-h-9 rounded-lg border px-3 font-semibold disabled:opacity-40" style={controlStyle}>Next</button>
      </div>
    </div>
  );
}
