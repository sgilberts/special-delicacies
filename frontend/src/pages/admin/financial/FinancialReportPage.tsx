import { useCallback, useEffect, useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import { ShieldAlert } from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { hasAnyPermission } from "@/lib/permissionAccess";
import ReportShell, { ReportPagination } from "@/components/financial/ReportShell";
import ReportTable from "@/components/financial/ReportTable";
import { REPORTS } from "@/components/financial/reportDefinitions";
import { daysAgo, dateTime, money, titleCase, today } from "@/components/financial/format";
import { downloadCsv, useReportQuery } from "@/components/financial/useReportQuery";

export default function FinancialReportPage() {
  const { report } = useParams<{ report: string }>();
  const definition = report ? REPORTS[report] : undefined;
  const { user } = useAuth();
  const permissions = user?.permissions ?? [];

  const [from, setFrom] = useState(daysAgo(29));
  const [to, setTo] = useState(today());
  const [currency, setCurrency] = useState("GHS");
  const [currencies, setCurrencies] = useState<string[]>(["GHS"]);
  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [values, setValues] = useState<Record<string, string>>({});
  const [sort, setSort] = useState(definition?.sortable[0]?.key ?? "");
  const [direction, setDirection] = useState<"asc" | "desc">("desc");
  const [page, setPage] = useState(1);
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState("");
  const [statement, setStatement] = useState<Record<string, any> | null>(null);
  const [statementLoading, setStatementLoading] = useState(false);

  useEffect(() => {
    const timer = window.setTimeout(() => setAppliedSearch(search.trim()), 300);
    return () => window.clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    setPage(1);
  }, [from, to, currency, appliedSearch, values, sort, direction]);

  useEffect(() => {
    setValues({});
    setSort(definition?.sortable[0]?.key ?? "");
    setStatement(null);
  }, [definition?.key]);

  const params = useMemo(() => {
    const query: Record<string, string | number | boolean | undefined> = { from, to, currency };
    if (appliedSearch) query.search = appliedSearch;
    if (sort) {
      query.sort = sort;
      query.direction = direction;
    }
    Object.entries(values).forEach(([key, value]) => {
      if (value !== "") query[key] = value;
    });
    return query;
  }, [from, to, currency, appliedSearch, sort, direction, values]);

  const { rows, meta, loading, error, reload } = useReportQuery(definition?.endpoint ?? "", params, page);

  useEffect(() => {
    const list = (meta as Record<string, any> | null)?.currencies;
    if (Array.isArray(list) && list.length > 0) setCurrencies(list as string[]);
  }, [meta]);

  const canView = definition ? hasAnyPermission(permissions, [definition.permission], user?.roles) : false;
  const canExport = hasAnyPermission(permissions, ["financials.reports.export"], user?.roles);

  const onSort = useCallback((key: string) => {
    setSort((current) => {
      if (current === key) {
        setDirection((value) => (value === "asc" ? "desc" : "asc"));
        return current;
      }
      setDirection("desc");
      return key;
    });
  }, []);

  const onExport = async () => {
    if (!definition) return;
    setExporting(true);
    setExportError("");
    try {
      await downloadCsv(definition.exportEndpoint, params);
    } catch {
      setExportError("The export could not be generated. Check your permissions and retry.");
    } finally {
      setExporting(false);
    }
  };

  const openStatement = async (payerId: number) => {
    if (!definition?.detail) return;
    setStatementLoading(true);
    try {
      const response = await api.get(definition.detail.endpoint(payerId), { params: { from, to, currency } });
      setStatement(response.data?.data ?? null);
    } catch {
      setStatement(null);
    } finally {
      setStatementLoading(false);
    }
  };

  if (!definition) {
    return <p className="rounded-xl bg-[var(--color-danger)]/10 p-4 text-sm text-[var(--color-danger)]">Unknown financial report.</p>;
  }

  if (!canView) {
    return (
      <section className="flex min-h-[40vh] flex-col items-center justify-center gap-3 text-center">
        <ShieldAlert size={40} className="text-[var(--color-danger)]" />
        <h1 className="text-xl font-bold">403 — Access denied</h1>
        <p className="text-sm text-[var(--color-text-secondary)]">Your account does not have permission to open this financial report.</p>
      </section>
    );
  }

  const columns = definition.detail
    ? [
        ...definition.columns,
        {
          key: "statement",
          label: "",
          render: (row: Record<string, any>) => (
            <button
              type="button"
              onClick={() => void openStatement(Number(row.payer?.id))}
              className="min-h-9 rounded-lg border px-2 text-xs font-semibold"
              style={{ borderColor: "var(--color-border)" }}
            >
              {definition.detail?.label}
            </button>
          ),
        },
      ]
    : definition.columns;

  return (
    <ReportShell
      title={definition.title}
      description={definition.description}
      dateBasis={definition.dateBasis}
      currencies={currencies.includes(currency) ? currencies : [currency, ...currencies]}
      filterSpecs={definition.filters}
      controls={{
        from, to, search, currency, values,
        setFrom, setTo, setSearch, setCurrency,
        setFilter: (param, value) => setValues((current) => ({ ...current, [param]: value })),
      }}
      summary={definition.summary(meta?.summary as Record<string, any> | undefined, currency)}
      exportEnabled={canExport}
      onExport={() => void onExport()}
      exporting={exporting}
    >
      {exportError && <p role="alert" className="mb-3 rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{exportError}</p>}
      <ReportTable
        columns={columns}
        rows={rows}
        loading={loading}
        error={error}
        onRetry={() => void reload()}
        sort={sort}
        direction={direction}
        onSort={definition.sortable.length > 0 ? onSort : undefined}
        emptyMessage={definition.emptyMessage}
      />
      <ReportPagination
        page={meta?.current_page ?? 1}
        lastPage={meta?.last_page ?? 1}
        total={meta?.total ?? 0}
        onPage={setPage}
      />

      {statementLoading && <p className="mt-4 text-sm text-[var(--color-text-secondary)]">Loading statement…</p>}
      {statement && (
        <section className="mt-4 rounded-xl border p-4" style={{ borderColor: "var(--color-border)" }}>
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <h2 className="text-lg font-bold">Credit statement · {statement.currency}</h2>
              <p className="text-sm text-[var(--color-text-secondary)]">
                Opening {money(statement.currency, statement.opening_balance)} · movement {money(statement.currency, statement.movement)} · closing {money(statement.currency, statement.closing_balance)}
              </p>
            </div>
            <button type="button" onClick={() => setStatement(null)} className="min-h-9 rounded-lg border px-3 text-sm font-semibold" style={{ borderColor: "var(--color-border)" }}>Close</button>
          </div>
          <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[760px] text-left text-sm">
              <thead className="text-[var(--color-text-secondary)]"><tr>
                <th className="p-2">When</th><th className="p-2">Type</th><th className="p-2">Reference</th>
                <th className="p-2">Related</th><th className="p-2 text-right">Amount</th><th className="p-2">Actor</th><th className="p-2">Reason</th>
              </tr></thead>
              <tbody>
                {(statement.entries ?? []).map((entry: Record<string, any>) => (
                  <tr key={entry.id} className="border-t" style={{ borderColor: "var(--color-border)" }}>
                    <td className="p-2">{dateTime(entry.created_at)}</td>
                    <td className="p-2">{titleCase(entry.entry_type)}</td>
                    <td className="p-2">{entry.operation_reference}</td>
                    <td className="p-2">{entry.related_receipt ?? entry.related_invoice ?? "—"}</td>
                    <td className="p-2 text-right tabular-nums">{money(entry.currency, entry.amount)}</td>
                    <td className="p-2">{entry.actor ?? "—"}</td>
                    <td className="p-2">{entry.reason ?? "—"}</td>
                  </tr>
                ))}
                {(statement.entries ?? []).length === 0 && (
                  <tr><td colSpan={7} className="p-4 text-center text-[var(--color-text-secondary)]">No ledger entries in this period.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </ReportShell>
  );
}
