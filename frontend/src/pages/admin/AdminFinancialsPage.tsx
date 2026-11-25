import { useCallback, useEffect, useMemo, useState } from "react";
import { Download, RefreshCw, Search, WalletCards } from "lucide-react";
import api, { cachedGet, invalidateCachedGets } from "@/lib/api";
import echo from "@/lib/echo";
import type { ApiResponse, PaginatedResponse } from "@/types/api";
import { useAuth } from "@/app/providers/useAuth";

type Day = {
  date: string; order_count: number; paid_orders: number; unpaid_orders: number; cancelled_orders: number;
  collected: string; cash_collected: string; online_collected: string; outstanding: string;
  failed_amount: string; cancelled_amount: string; refunded_amount: string;
  pending_count: number; failed_count: number; cancelled_count: number; refunded_count: number;
  dine_in_collected: string; delivery_collected: string;
};
type DailyChannel = { date: string; payment_method: string; provider: string | null; status: string; transaction_count: number; collected: string; amount: string };
type Snapshot = {
  timezone: string; currency: string; currency_symbol: string; generated_at: string;
  coverage: { from: string | null; to: string | null }; daily: Day[]; daily_channels: DailyChannel[];
  providers: Array<{ provider: string; name: string }>;
};
type Transaction = {
  id: number; order_id: number; order_number: string; transaction_reference: string; customer_name: string;
  payment_method: string; provider: string | null; amount: string; currency: string; status: string;
  order_type: string; payment_timestamp: string | null; verified_at: string | null; confirmed_by: string | null;
  provider_reference?: string | null; order_status?: string;
};
type Period = "today" | "yesterday" | "this_week" | "last_week" | "this_month" | "last_month" | "this_year" | "last_year" | "custom";

const periods: Array<[Period, string]> = [
  ["today", "Today"], ["yesterday", "Yesterday"], ["this_week", "This week"], ["last_week", "Last week"],
  ["this_month", "This month"], ["last_month", "Last month"], ["this_year", "This year"], ["last_year", "Last year"], ["custom", "Custom range"],
];
const cents = (value: string | number | null | undefined) => {
  const match = String(value ?? "0").match(/^(-?)(\d+)(?:\.(\d{0,2}))?/);
  if (!match) return 0;
  const amount = Number(match[2]) * 100 + Number((match[3] ?? "").padEnd(2, "0"));
  return match[1] ? -amount : amount;
};
const money = (amount: number) => new Intl.NumberFormat("en-GH", { style: "currency", currency: "GHS", minimumFractionDigits: 2 }).format(amount / 100).replace("GHS", "GH₵");
const dateParts = (date: Date, timeZone: string) => {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(date);
  const take = (type: string) => parts.find((part) => part.type === type)?.value ?? "";
  return `${take("year")}-${take("month")}-${take("day")}`;
};
const shift = (date: string, days: number) => {
  const value = new Date(`${date}T00:00:00Z`);
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
};
const monthStart = (date: string) => `${date.slice(0, 7)}-01`;
const yearStart = (date: string) => `${date.slice(0, 4)}-01-01`;

function getRange(period: Period, timezone: string, customFrom: string, customTo: string) {
  const today = dateParts(new Date(), timezone);
  const weekday = new Date(`${today}T00:00:00Z`).getUTCDay();
  const monday = shift(today, -(weekday === 0 ? 6 : weekday - 1));
  if (period === "today") return [today, today] as const;
  if (period === "yesterday") return [shift(today, -1), shift(today, -1)] as const;
  if (period === "this_week") return [monday, today] as const;
  if (period === "last_week") return [shift(monday, -7), shift(monday, -1)] as const;
  if (period === "this_month") return [monthStart(today), today] as const;
  if (period === "last_month") { const end = shift(monthStart(today), -1); return [monthStart(end), end] as const; }
  if (period === "this_year") return [yearStart(today), today] as const;
  if (period === "last_year") { const year = String(Number(today.slice(0, 4)) - 1); return [`${year}-01-01`, `${year}-12-31`] as const; }
  if (period === "custom") return [customFrom, customTo] as const;
  return [today, today] as const;
}

export default function AdminFinancialsPage() {
  const { user } = useAuth();
  const [snapshot, setSnapshot] = useState<Snapshot | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState("");
  const [period, setPeriod] = useState<Period>("this_month");
  const [customFrom, setCustomFrom] = useState("");
  const [customTo, setCustomTo] = useState("");
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [methodFilter, setMethodFilter] = useState("all");
  const [providerFilter, setProviderFilter] = useState("all");
  const [orderTypeFilter, setOrderTypeFilter] = useState("all");
  const [selectedDetail, setSelectedDetail] = useState<Transaction | null>(null);
  const [transactionPage, setTransactionPage] = useState(1);
  const [hasMoreTransactions, setHasMoreTransactions] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);

  const load = useCallback(async (manual = false) => {
    if (manual) invalidateCachedGets();
    if (manual) setRefreshing(true); else setLoading(true);
    setError("");
    try {
      const [snapshotResponse, transactionResponse] = await Promise.all([
        cachedGet<ApiResponse<Snapshot>>("/admin/financials/snapshot", 5_000),
        user?.permissions.includes("financials.transactions.view") || user?.roles.includes("super-admin")
          ? cachedGet<PaginatedResponse<Transaction>>("/admin/financials/transactions?per_page=100&page=1", 5_000)
          : Promise.resolve(null),
      ]);
      setSnapshot(snapshotResponse.data.data);
      setTransactions(transactionResponse?.data.data ?? []);
      setTransactionPage(1);
      setHasMoreTransactions((transactionResponse?.data.meta.current_page ?? 1) < (transactionResponse?.data.meta.last_page ?? 1));
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || "Financial data could not be loaded.");
    } finally { setLoading(false); setRefreshing(false); }
  }, []);

  useEffect(() => { document.title = "Financial Management | Special Delicacies"; void load(); }, [load]);
  useEffect(() => {
    if (!snapshot) return;
    const seen = new Set<number>();
    const channel = echo.private("financials");
    const onPayment = (payload: { transaction?: Transaction & { received_at?: string } }) => {
      invalidateCachedGets();
      const raw = payload.transaction;
      const incoming = raw ? { ...raw, payment_timestamp: raw.payment_timestamp ?? raw.received_at ?? null, customer_name: raw.customer_name ?? "—" } : undefined;
      if (!incoming || seen.has(incoming.id)) return;
      seen.add(incoming.id);
      const received = incoming.payment_timestamp;
      if (!received || new Date(received) <= new Date(snapshot.generated_at)) return;
      if (incoming.status === "succeeded") {
        const date = dateParts(new Date(received), snapshot.timezone);
        setSnapshot((current) => {
          if (!current) return current;
          const daily = [...current.daily];
          const rowIndex = daily.findIndex((item) => item.date === date);
          const row = rowIndex >= 0 ? { ...daily[rowIndex] } : undefined;
          if (row) {
            const amount = cents(incoming.amount);
            row.collected = ((cents(row.collected) + amount) / 100).toFixed(2);
            if (incoming.payment_method === "cash") row.cash_collected = ((cents(row.cash_collected) + amount) / 100).toFixed(2);
            else row.online_collected = ((cents(row.online_collected) + amount) / 100).toFixed(2);
            if (incoming.order_type === "dine_in") row.dine_in_collected = ((cents(row.dine_in_collected) + amount) / 100).toFixed(2);
            else row.delivery_collected = ((cents(row.delivery_collected) + amount) / 100).toFixed(2);
            row.paid_orders += 1;
            row.unpaid_orders = Math.max(0, row.unpaid_orders - 1);
            row.pending_count = Math.max(0, row.pending_count - 1);
            row.outstanding = (Math.max(0, cents(row.outstanding) - amount) / 100).toFixed(2);
            daily[rowIndex] = row;
          } else {
            daily.push({ date, order_count: 1, paid_orders: 1, unpaid_orders: 0, cancelled_orders: 0,
              collected: incoming.amount, cash_collected: incoming.payment_method === "cash" ? incoming.amount : "0.00",
              online_collected: incoming.payment_method === "gateway" ? incoming.amount : "0.00", outstanding: "0.00",
              failed_amount: "0.00", cancelled_amount: "0.00", refunded_amount: "0.00", pending_count: 0, failed_count: 0,
              cancelled_count: 0, refunded_count: 0, dine_in_collected: incoming.order_type === "dine_in" ? incoming.amount : "0.00",
              delivery_collected: incoming.order_type === "delivery" ? incoming.amount : "0.00" });
          }
          const dailyChannels = [...current.daily_channels];
          const channelIndex = dailyChannels.findIndex((item) => item.date === date && item.payment_method === incoming.payment_method && item.provider === incoming.provider && item.status === "succeeded");
          if (channelIndex >= 0) {
            const channel = { ...dailyChannels[channelIndex], transaction_count: dailyChannels[channelIndex].transaction_count + 1, collected: ((cents(dailyChannels[channelIndex].collected) + cents(incoming.amount)) / 100).toFixed(2), amount: ((cents(dailyChannels[channelIndex].amount) + cents(incoming.amount)) / 100).toFixed(2) };
            dailyChannels[channelIndex] = channel;
          } else dailyChannels.push({ date, payment_method: incoming.payment_method, provider: incoming.provider, status: "succeeded", transaction_count: 1, collected: incoming.amount, amount: incoming.amount });
          return { ...current, daily: daily.sort((a, b) => a.date.localeCompare(b.date)), daily_channels: dailyChannels, generated_at: new Date().toISOString() };
        });
      }
      setTransactions((current) => [incoming, ...current.filter((item) => item.id !== incoming.id)]);
    };
    channel.listen(".payment.updated", onPayment);
    return () => { channel.stopListening(".payment.updated", onPayment); echo.leave("financials"); };
  }, [snapshot?.generated_at, snapshot?.timezone]);

  useEffect(() => {
    if (!snapshot) return;
    const connection = (echo.connector as any).pusher?.connection;
    if (!connection) return;
    const onStateChange = (state: { previous: string; current: string }) => {
      if (state.previous === "disconnected" && state.current === "connected") void load(true);
    };
    connection.bind("state_change", onStateChange);
    return () => connection.unbind("state_change", onStateChange);
  }, [snapshot?.generated_at, load]);

  const timezone = snapshot?.timezone ?? "Africa/Accra";
  const [from, to] = getRange(period, timezone, customFrom, customTo);
  const rangeDays = useMemo(() => snapshot?.daily.filter((row) => row.date >= from && row.date <= to) ?? [], [snapshot, from, to]);
  const channelDays = useMemo(() => snapshot?.daily_channels.filter((row) => row.date >= from && row.date <= to) ?? [], [snapshot, from, to]);
  const summary = useMemo(() => rangeDays.reduce((acc, row) => ({
    collected: acc.collected + cents(row.collected), cash: acc.cash + cents(row.cash_collected), online: acc.online + cents(row.online_collected),
    outstanding: acc.outstanding + cents(row.outstanding), failed: acc.failed + cents(row.failed_amount), cancelled: acc.cancelled + cents(row.cancelled_amount),
    refunded: acc.refunded + cents(row.refunded_amount), dineIn: acc.dineIn + cents(row.dine_in_collected), delivery: acc.delivery + cents(row.delivery_collected),
    orders: acc.orders + row.order_count, paid: acc.paid + row.paid_orders, unpaid: acc.unpaid + row.unpaid_orders, cancelledOrders: acc.cancelledOrders + row.cancelled_orders,
    pendingCount: acc.pendingCount + row.pending_count, failedCount: acc.failedCount + row.failed_count, cancelledCount: acc.cancelledCount + row.cancelled_count, refundedCount: acc.refundedCount + row.refunded_count,
  }), { collected: 0, cash: 0, online: 0, outstanding: 0, failed: 0, cancelled: 0, refunded: 0, dineIn: 0, delivery: 0,
    orders: 0, paid: 0, unpaid: 0, cancelledOrders: 0, pendingCount: 0, failedCount: 0, cancelledCount: 0, refundedCount: 0 }), [rangeDays]);
  const providerTotals = useMemo(() => channelDays.filter((row) => row.status === "succeeded").reduce<Record<string, number>>((totals, row) => {
    const key = row.payment_method === "cash" ? "Cash" : snapshot?.providers.find((item) => item.provider === row.provider)?.name ?? row.provider ?? "Online gateway";
    totals[key] = (totals[key] ?? 0) + cents(row.collected); return totals;
  }, {}), [channelDays, snapshot?.providers]);
  const visibleTransactions = useMemo(() => transactions.filter((transaction) => {
    const date = transaction.payment_timestamp ? dateParts(new Date(transaction.payment_timestamp), timezone) : "";
    const text = `${transaction.order_number} ${transaction.transaction_reference} ${transaction.customer_name} ${transaction.provider ?? "cash"}`.toLowerCase();
    return date >= from && date <= to && (statusFilter === "all" || transaction.status === statusFilter)
      && (methodFilter === "all" || transaction.payment_method === methodFilter)
      && (providerFilter === "all" || transaction.provider === providerFilter)
      && (orderTypeFilter === "all" || transaction.order_type === orderTypeFilter)
      && text.includes(search.toLowerCase());
  }), [transactions, from, to, statusFilter, methodFilter, providerFilter, orderTypeFilter, search, timezone]);
  const trendMax = Math.max(1, ...rangeDays.map((row) => cents(row.collected)));
  const coverageWarning = snapshot?.coverage.from && snapshot.coverage.to && (from < snapshot.coverage.from || to > snapshot.coverage.to);
  const downloadCsv = async () => {
    if (!from || !to || from > to) return;
    try {
      const params = new URLSearchParams({ from, to });
      if (statusFilter !== "all") params.set("status", statusFilter);
      if (methodFilter !== "all") params.set("method", methodFilter);
      if (providerFilter !== "all") params.set("provider", providerFilter);
      if (orderTypeFilter !== "all") params.set("order_type", orderTypeFilter);
      const response = await api.get(`/admin/financials/export.csv?${params.toString()}`, { responseType: "blob" });
      const url = URL.createObjectURL(response.data); const anchor = document.createElement("a");
      anchor.href = url; anchor.download = "financial-transactions.csv"; anchor.click(); URL.revokeObjectURL(url);
    } catch { setError("The financial export could not be created."); }
  };
  const openTransaction = async (id: number) => {
    try {
      const response = await api.get<ApiResponse<Transaction>>(`/admin/financials/transactions/${id}`);
      setSelectedDetail(response.data.data);
    } catch { setError("Transaction details could not be loaded."); }
  };
  const loadMoreTransactions = async () => {
    setLoadingMore(true);
    try {
      const nextPage = transactionPage + 1;
      const response = await api.get<PaginatedResponse<Transaction>>(`/admin/financials/transactions?per_page=100&page=${nextPage}`);
      setTransactions((current) => [...current, ...response.data.data.filter((item) => !current.some((existing) => existing.id === item.id))]);
      setTransactionPage(nextPage);
      setHasMoreTransactions(nextPage < response.data.meta.last_page);
    } catch { setError("Older transactions could not be loaded."); }
    finally { setLoadingMore(false); }
  };

  if (loading) return <div className="p-6 text-sm text-[var(--color-text-secondary)]">Loading financial overview…</div>;
  if (error && !snapshot) return <div className="m-6 rounded-2xl border border-red-300 bg-red-50 p-5 text-sm text-red-800 dark:bg-red-950/30 dark:text-red-200">{error}<button onClick={() => void load()} className="ml-3 underline">Try again</button></div>;

  const cards: Array<[string, number, string]> = [
    ["Collected revenue", summary.collected, `${summary.paid} paid orders`], ["Cash", summary.cash, "Confirmed collections"],
    ["Online payments", summary.online, "Verified gateway payments"], ["Outstanding", summary.outstanding, `${summary.pendingCount} pending payments`],
  ];
  return <div className="mx-auto max-w-[1500px] space-y-6 p-4 sm:p-6 lg:p-8">
    <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div><p className="text-xs font-bold uppercase tracking-[.18em] text-[var(--color-accent)]">Revenue intelligence</p><h1 className="mt-1 text-3xl font-black text-[var(--color-text)]">Financial Management</h1><p className="mt-2 text-sm text-[var(--color-text-secondary)]">Payment activity in GH₵ · {timezone}</p></div>
      <div className="flex flex-wrap gap-2"><button onClick={() => void load(true)} disabled={refreshing} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-4 text-sm font-bold text-[var(--color-text)]"><RefreshCw size={15} className={refreshing ? "animate-spin" : ""} />Refresh snapshot</button>{(user?.permissions.includes("financials.reports.export") || user?.roles.includes("super-admin")) && <button onClick={() => void downloadCsv()} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-bold text-white"><Download size={15} />Export CSV</button>}</div>
    </header>
    {error && <p role="alert" className="rounded-xl border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950/30 dark:text-red-200">{error}</p>}
    <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 shadow-sm sm:p-5">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div className="flex flex-wrap gap-2">{periods.map(([key, label]) => <button key={key} onClick={() => setPeriod(key)} className={`rounded-xl px-3 py-2 text-sm font-semibold ${period === key ? "bg-[var(--color-accent)] text-white" : "bg-[var(--color-background)] text-[var(--color-text-secondary)]"}`}>{label}</button>)}</div>
      {period === "custom" && <div className="flex flex-wrap items-center gap-2"><label className="text-xs font-semibold text-[var(--color-text-secondary)]">From <input type="date" value={customFrom} onChange={(event) => setCustomFrom(event.target.value)} className="ml-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-background)] p-2 text-sm text-[var(--color-text)]" /></label><label className="text-xs font-semibold text-[var(--color-text-secondary)]">To <input type="date" value={customTo} min={customFrom || undefined} onChange={(event) => setCustomTo(event.target.value)} className="ml-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-background)] p-2 text-sm text-[var(--color-text)]" /></label></div>}</div>
    </section>
    {coverageWarning && <p className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">The selected range extends beyond the available aggregate history ({snapshot?.coverage.from ?? "no transactions"} to {snapshot?.coverage.to ?? "no transactions"}).</p>}
    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{cards.map(([label, amount, caption]) => <article key={label} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-sm"><div className="flex items-center justify-between"><p className="text-sm font-semibold text-[var(--color-text-secondary)]">{label}</p><WalletCards size={18} className="text-[var(--color-accent)]" /></div><p className="mt-3 text-2xl font-black tracking-tight text-[var(--color-text)]">{money(amount)}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{caption}</p></article>)}</section>
    <section className="grid gap-4 xl:grid-cols-[1.5fr_1fr]">
      <article className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"><div className="flex items-start justify-between"><div><h2 className="font-bold text-[var(--color-text)]">Revenue trend</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Collected payments by day · {from} — {to}</p></div><span className="rounded-lg bg-[var(--color-background)] px-2 py-1 text-xs font-bold text-[var(--color-text-secondary)]">{rangeDays.length} days</span></div>
        {rangeDays.length ? <div className="mt-5 overflow-x-auto"><div className="flex h-48 min-w-[520px] items-end gap-1 border-b border-l border-[var(--color-border)] px-2">{rangeDays.map((row) => <div key={row.date} title={`${row.date}: ${money(cents(row.collected))}`} className="group flex h-full flex-1 items-end"><div className="w-full rounded-t-md bg-[var(--color-accent)]/80 transition-colors group-hover:bg-[var(--color-accent)]" style={{ height: `${Math.max(2, cents(row.collected) / trendMax * 92)}%` }} /></div>)}</div><div className="mt-2 flex justify-between text-[10px] text-[var(--color-text-secondary)]"><span>{rangeDays[0]?.date}</span><span>{rangeDays.at(-1)?.date}</span></div></div> : <Empty message="No financial activity in this date range." />}
      </article>
      <article className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"><h2 className="font-bold text-[var(--color-text)]">Payment channels</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Verified collected revenue</p><div className="mt-4 space-y-4">{Object.entries(providerTotals).length ? Object.entries(providerTotals).sort((a, b) => b[1] - a[1]).map(([name, total]) => <div key={name}><div className="mb-1 flex justify-between gap-3 text-sm"><span className="font-semibold text-[var(--color-text)]">{name}</span><span className="font-bold text-[var(--color-text)]">{money(total)}</span></div><div className="h-2 overflow-hidden rounded-full bg-[var(--color-background)]"><div className="h-full rounded-full bg-[var(--color-accent)]" style={{ width: `${summary.collected ? Math.max(2, total / summary.collected * 100) : 0}%` }} /></div></div>) : <Empty message="No confirmed payments in this period." />}</div></article>
    </section>
    <section className="grid gap-4 lg:grid-cols-3">
      <MiniPanel title="Order summary" rows={[["All orders", summary.orders], ["Paid", summary.paid], ["Unpaid", summary.unpaid], ["Cancelled", summary.cancelledOrders], ["Average per paid order", summary.paid ? money(Math.round(summary.collected / summary.paid)) : money(0)]]} />
      <MiniPanel title="Order type revenue" rows={[["Dine-in", money(summary.dineIn)], ["Delivery", money(summary.delivery)], ["Cash vs online", `${money(summary.cash)} / ${money(summary.online)}`]]} />
      <MiniPanel title="Payment status" rows={[["Pending", `${summary.pendingCount} · ${money(summary.outstanding)}`], ["Failed", `${summary.failedCount} · ${money(summary.failed)}`], ["Cancelled", `${summary.cancelledCount} · ${money(summary.cancelled)}`], ["Refunded", `${summary.refundedCount} · ${money(summary.refunded)}`]]} />
    </section>
    {(user?.permissions.includes("financials.transactions.view") || user?.roles.includes("super-admin")) && <section className="overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-sm">
      <div className="flex flex-col gap-3 border-b border-[var(--color-border)] p-5"><div><h2 className="font-bold text-[var(--color-text)]">Transaction register</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Latest 100 transactions from the loaded snapshot</p></div><div className="flex flex-wrap gap-2"><label className="relative"><Search size={15} className="absolute left-3 top-3 text-[var(--color-text-secondary)]" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search transaction" className="min-h-10 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] pl-9 pr-3 text-sm text-[var(--color-text)]" /></label><select aria-label="Filter payment status" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className="min-h-10 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]"><option value="all">All statuses</option>{["succeeded", "pending", "failed", "cancelled", "refunded"].map((status) => <option key={status}>{status}</option>)}</select><select aria-label="Filter payment method" value={methodFilter} onChange={(event) => setMethodFilter(event.target.value)} className="min-h-10 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]"><option value="all">All methods</option><option value="cash">Cash</option><option value="gateway">Online</option></select><select aria-label="Filter provider" value={providerFilter} onChange={(event) => setProviderFilter(event.target.value)} className="min-h-10 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]"><option value="all">All providers</option>{Array.from(new Set(transactions.map((item) => item.provider).filter(Boolean))).map((provider) => <option key={provider} value={provider ?? ""}>{snapshot?.providers.find((item) => item.provider === provider)?.name ?? provider}</option>)}</select><select aria-label="Filter order type" value={orderTypeFilter} onChange={(event) => setOrderTypeFilter(event.target.value)} className="min-h-10 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]"><option value="all">All order types</option><option value="dine_in">Dine-in</option><option value="delivery">Delivery</option></select></div></div>
      <div className="hidden overflow-x-auto md:block"><table className="w-full min-w-[850px] text-left text-sm"><thead className="bg-[var(--color-background)] text-xs uppercase tracking-wide text-[var(--color-text-secondary)]"><tr>{["Order", "Reference", "Customer", "Channel", "Amount", "Status", "Received"].map((heading) => <th key={heading} className="px-4 py-3 font-bold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-[var(--color-border)]">{visibleTransactions.map((transaction) => <tr key={transaction.id} className="text-[var(--color-text)]"><td className="px-4 py-3 font-bold"><button onClick={() => void openTransaction(transaction.id)} className="text-left underline-offset-2 hover:underline">{transaction.order_number}</button><span className="mt-1 block text-xs font-normal text-[var(--color-text-secondary)]">{transaction.order_type.replace("_", " ")}</span></td><td className="px-4 py-3 font-mono text-xs">{transaction.transaction_reference}</td><td className="px-4 py-3">{transaction.customer_name}</td><td className="px-4 py-3">{transaction.payment_method === "cash" ? "Cash" : snapshot?.providers.find((provider) => provider.provider === transaction.provider)?.name ?? transaction.provider ?? "Gateway"}</td><td className="px-4 py-3 font-bold">{money(cents(transaction.amount))}</td><td className="px-4 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-bold ${transaction.status === "succeeded" ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200" : "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200"}`}>{transaction.status}</span></td><td className="px-4 py-3 text-xs">{transaction.payment_timestamp ? new Intl.DateTimeFormat("en-GH", { dateStyle: "medium", timeStyle: "short", timeZone: timezone }).format(new Date(transaction.payment_timestamp)) : "—"}</td></tr>)}</tbody></table>
      {!visibleTransactions.length && <Empty message="No transactions match this period and search." />}</div>
      <div className="divide-y divide-[var(--color-border)] md:hidden">{visibleTransactions.map((transaction) => <article key={transaction.id} className="space-y-2 p-4 text-sm text-[var(--color-text)]"><div className="flex items-start justify-between gap-3"><div><button onClick={() => void openTransaction(transaction.id)} className="font-bold underline-offset-2 hover:underline">{transaction.order_number}</button><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{transaction.customer_name} · {transaction.order_type.replace("_", " ")}</p></div><p className="font-black">{money(cents(transaction.amount))}</p></div><p className="break-all font-mono text-[11px] text-[var(--color-text-secondary)]">{transaction.transaction_reference}</p><div className="flex items-center justify-between gap-2 text-xs"><span>{transaction.payment_method === "cash" ? "Cash" : snapshot?.providers.find((provider) => provider.provider === transaction.provider)?.name ?? transaction.provider ?? "Gateway"}</span><span className="font-semibold">{transaction.status}</span></div></article>)}{!visibleTransactions.length && <Empty message="No transactions match this period and search." />}</div>
      {hasMoreTransactions && <div className="border-t border-[var(--color-border)] p-4 text-center"><button onClick={() => void loadMoreTransactions()} disabled={loadingMore} className="rounded-xl border border-[var(--color-border)] px-4 py-2 text-sm font-bold text-[var(--color-text)] disabled:opacity-60">{loadingMore ? "Loading…" : "Load older transactions"}</button></div>}
    </section>}
    <p className="text-right text-xs text-[var(--color-text-secondary)]">Snapshot loaded {snapshot?.generated_at ? new Intl.DateTimeFormat("en-GH", { dateStyle: "medium", timeStyle: "short", timeZone: timezone }).format(new Date(snapshot.generated_at)) : ""} · Changing the date range uses the loaded aggregates.</p>
    {selectedDetail && <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-2 sm:items-center sm:p-6" role="presentation" onClick={(event) => { if (event.target === event.currentTarget) setSelectedDetail(null); }}><section role="dialog" aria-modal="true" aria-labelledby="transaction-detail-title" className="max-h-[90vh] w-full max-w-xl overflow-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-2xl"><div className="flex items-start justify-between"><div><p className="text-xs font-bold uppercase tracking-wide text-[var(--color-accent)]">Payment record</p><h2 id="transaction-detail-title" className="mt-1 text-xl font-black text-[var(--color-text)]">{selectedDetail.order_number}</h2></div><button onClick={() => setSelectedDetail(null)} className="rounded-lg px-3 py-2 text-sm font-bold text-[var(--color-text-secondary)]">Close</button></div><div className="mt-5 grid grid-cols-1 gap-x-5 sm:grid-cols-2">{[["Transaction reference", selectedDetail.transaction_reference], ["Provider reference", selectedDetail.provider_reference ?? "—"], ["Customer", selectedDetail.customer_name], ["Payment method", selectedDetail.payment_method], ["Provider", selectedDetail.provider ?? "Cash"], ["Amount", `${selectedDetail.currency} ${selectedDetail.amount}`], ["Payment status", selectedDetail.status], ["Order status", selectedDetail.order_status ?? "—"], ["Order type", selectedDetail.order_type], ["Verified at", selectedDetail.verified_at ?? "Not verified"], ["Received at", selectedDetail.payment_timestamp ?? "Not received"], ["Cash confirmed by", selectedDetail.confirmed_by ?? "—"]].map(([label, value]) => <div key={label} className="border-b border-[var(--color-border)] py-3"><p className="text-xs text-[var(--color-text-secondary)]">{label}</p><p className="mt-1 break-all text-sm font-semibold text-[var(--color-text)]">{value}</p></div>)}</div></section></div>}
  </div>;
}

function Empty({ message }: { message: string }) { return <div className="py-12 text-center text-sm text-[var(--color-text-secondary)]">{message}</div>; }
function MiniPanel({ title, rows }: { title: string; rows: Array<[string, string | number]> }) {
  return <article className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"><h2 className="font-bold text-[var(--color-text)]">{title}</h2><div className="mt-4 divide-y divide-[var(--color-border)]">{rows.map(([label, value]) => <div key={label} className="flex items-center justify-between gap-3 py-3 text-sm"><span className="text-[var(--color-text-secondary)]">{label}</span><span className="text-right font-bold text-[var(--color-text)]">{value}</span></div>)}</div></article>;
}
