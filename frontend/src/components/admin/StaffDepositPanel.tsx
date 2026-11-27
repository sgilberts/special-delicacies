import { useCallback, useEffect, useState, type FormEvent } from "react";
import api from "@/lib/api";

type PayerOption = { type: "customer" | "organisation"; id: number; name: string; detail: string };
type StaffDeposit = {
  id: number;
  status: "pending" | "verified" | "rejected";
  intent: string;
  external_reference: string;
  operation_reference: string;
  payment_method: string;
  evidence: string;
  amount: string | null;
  currency: string | null;
  payment_reference: string | null;
  payment_status: string | null;
  payer: { type: string; id: number; name: string } | null;
  recorded_by: { id: number; name: string };
  recorded_at: string;
  verified_by: { id: number; name: string } | null;
  verified_at: string | null;
  decision_reason: string | null;
  can_verify: boolean;
};

const statusTone: Record<string, string> = {
  pending: "text-[var(--color-warning)] bg-[var(--color-warning)]/10",
  verified: "text-[var(--color-success)] bg-[var(--color-success)]/10",
  rejected: "text-[var(--color-danger)] bg-[var(--color-danger)]/10",
};

const methodLabels: Record<string, string> = {
  cash: "Cash", bank_transfer: "Bank transfer", mobile_money: "Mobile money", cheque: "Cheque", card: "Card", other: "Other",
};

function errorMessage(cause: unknown): string {
  const response = (cause as { response?: { data?: { message?: string } } }).response;
  return response?.data?.message ?? "The deposit operation was rejected. Review the details and try again.";
}

export default function StaffDepositPanel({ canManage }: { canManage: boolean }) {
  const [records, setRecords] = useState<StaffDeposit[]>([]);
  const [statusFilter, setStatusFilter] = useState("pending");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const [payerQuery, setPayerQuery] = useState("");
  const [payerResults, setPayerResults] = useState<PayerOption[]>([]);
  const [payer, setPayer] = useState<PayerOption | null>(null);
  const [intent, setIntent] = useState("deposit");
  const [amount, setAmount] = useState("");
  const [currency, setCurrency] = useState("GHS");
  const [method, setMethod] = useState("bank_transfer");
  const [external, setExternal] = useState("");
  const [evidence, setEvidence] = useState("");
  const [reasons, setReasons] = useState<Record<number, string>>({});

  const load = useCallback(async () => {
    const params = new URLSearchParams({ per_page: "25" });
    if (statusFilter) params.set("status", statusFilter);
    if (search) params.set("search", search);
    const response = await api.get<{ data: StaffDeposit[] }>(`/admin/deposits?${params.toString()}`);
    setRecords(response.data.data);
  }, [statusFilter, search]);

  useEffect(() => {
    void load().catch(() => setError("Deposit records could not be loaded.")).finally(() => setLoaded(true));
  }, [load]);

  useEffect(() => {
    if (!canManage || payerQuery.trim().length < 2) {
      setPayerResults([]);
      return;
    }
    const timer = setTimeout(() => {
      api.get<{ data: PayerOption[] }>("/admin/deposits/payers", { params: { search: payerQuery.trim() } })
        .then(response => setPayerResults(response.data.data))
        .catch(() => setPayerResults([]));
    }, 300);
    return () => clearTimeout(timer);
  }, [payerQuery, canManage]);

  async function record(event: FormEvent) {
    event.preventDefault();
    if (!payer) {
      setError("Choose the customer or organisation payer for this deposit.");
      return;
    }
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await api.post("/admin/deposits", {
        payer_type: payer.type,
        customer_id: payer.type === "customer" ? payer.id : undefined,
        organisation_id: payer.type === "organisation" ? payer.id : undefined,
        intent,
        amount,
        currency,
        payment_method: method,
        external_reference: external,
        evidence,
      });
      setMessage(`Deposit recorded for ${payer.name}. It stays pending until a different authorised staff member verifies the receipt.`);
      setPayer(null);
      setPayerQuery("");
      setAmount("");
      setExternal("");
      setEvidence("");
      await load();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setBusy(false);
    }
  }

  async function decide(deposit: StaffDeposit, approve: boolean) {
    const reason = (reasons[deposit.id] ?? "").trim();
    if (reason.length < 3) {
      setError("Record the reason for this verification decision.");
      return;
    }
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await api.post(`/admin/deposits/${deposit.id}/${approve ? "verify" : "reject"}`, { reason });
      setMessage(`Deposit ${deposit.payment_reference ?? deposit.id} ${approve ? "verified and eligible for credit designation" : "rejected and no longer credit-eligible"}.`);
      setReasons(previous => {
        const next = { ...previous };
        delete next[deposit.id];
        return next;
      });
      await load();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setBusy(false);
    }
  }

  const controlClass = "mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 text-sm";
  const borderStyle = { borderColor: "var(--color-border)" };

  return <section className="space-y-5">
    <div className="rounded-2xl border bg-[var(--color-surface)] p-5" style={borderStyle}>
      <h2 className="text-lg font-bold">Staff-recorded deposits</h2>
      <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
        Record money received outside the online checkout. Recording is not proof of receipt: a different authorised staff member must verify the evidence before the receipt can become customer credit.
      </p>

      {message && <p role="status" className="mt-4 rounded-lg bg-[var(--color-success)]/10 p-3 text-sm">{message}</p>}
      {error && <p role="alert" className="mt-4 rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}

      {canManage && <form onSubmit={record} className="mt-4 grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <label className="text-sm font-semibold">Payer
            <input value={payerQuery} onChange={event => { setPayerQuery(event.target.value); setPayer(null); }}
              placeholder="Search customer name, phone, email or organisation" className={controlClass} style={borderStyle} />
          </label>
          {payerResults.length > 0 && <ul className="mt-1 max-h-48 overflow-y-auto rounded-lg border" style={borderStyle}>
            {payerResults.map(option => <li key={`${option.type}-${option.id}`}>
              <button type="button" onClick={() => { setPayer(option); setPayerQuery(`${option.name} (${option.type})`); setPayerResults([]); }}
                className="w-full px-3 py-2 text-left text-sm hover:bg-[var(--color-background)]">
                <span className="font-semibold">{option.name}</span> <span className="text-[var(--color-text-secondary)]">· {option.type}{option.detail ? ` · ${option.detail}` : ""}</span>
              </button>
            </li>)}
          </ul>}
          {payer && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Selected: {payer.name} ({payer.type})</p>}
        </div>
        <label className="text-sm font-semibold">Purpose
          <select value={intent} onChange={event => setIntent(event.target.value)} className={controlClass} style={borderStyle}>
            <option value="deposit">Deposit</option>
            <option value="advance">Advance payment</option>
          </select>
        </label>
        <label className="text-sm font-semibold">Payment source
          <select value={method} onChange={event => setMethod(event.target.value)} className={controlClass} style={borderStyle}>
            {Object.entries(methodLabels).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
          </select>
        </label>
        <label className="text-sm font-semibold">Amount
          <input required type="number" inputMode="decimal" min="0.01" step="0.01" value={amount}
            onChange={event => setAmount(event.target.value)} className={controlClass} style={borderStyle} />
        </label>
        <label className="text-sm font-semibold">Currency
          <input required value={currency} maxLength={3} onChange={event => setCurrency(event.target.value.toUpperCase())}
            className={controlClass} style={borderStyle} />
        </label>
        <label className="text-sm font-semibold sm:col-span-2">External receipt reference
          <input required value={external} maxLength={120} onChange={event => setExternal(event.target.value)}
            placeholder="Bank statement line, receipt number or transfer reference" className={controlClass} style={borderStyle} />
        </label>
        <label className="text-sm font-semibold sm:col-span-2">Supporting evidence
          <textarea required value={evidence} minLength={10} maxLength={2000} rows={3} onChange={event => setEvidence(event.target.value)}
            placeholder="Describe how the money was received and what evidence was checked" className={`${controlClass} py-2`} style={borderStyle} />
        </label>
        <button type="submit" disabled={busy || !payer || !amount || external.trim().length < 4 || evidence.trim().length < 10}
          className="min-h-11 rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white disabled:opacity-50 sm:col-span-2 sm:justify-self-start sm:px-8">
          {busy ? "Recording…" : "Record deposit for verification"}
        </button>
      </form>}
    </div>

    <div className="rounded-2xl border bg-[var(--color-surface)] p-5" style={borderStyle}>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h2 className="text-lg font-bold">Deposit verification queue</h2>
        <form onSubmit={event => { event.preventDefault(); setSearch(searchInput.trim()); }} className="flex flex-wrap items-end gap-2">
          <label className="text-sm font-semibold">Status
            <select value={statusFilter} onChange={event => setStatusFilter(event.target.value)} className={controlClass} style={borderStyle}>
              <option value="pending">Pending verification</option>
              <option value="verified">Verified</option>
              <option value="rejected">Rejected</option>
              <option value="">All statuses</option>
            </select>
          </label>
          <label className="text-sm font-semibold">Search
            <input value={searchInput} onChange={event => setSearchInput(event.target.value)} placeholder="Reference or payer" className={controlClass} style={borderStyle} />
          </label>
          <button type="submit" className="min-h-11 rounded-lg border px-4 text-sm font-semibold" style={borderStyle}>Apply</button>
        </form>
      </div>

      {!loaded && <p role="status" className="mt-3 text-sm text-[var(--color-text-secondary)]">Loading deposit records…</p>}
      {loaded && records.length === 0 && <p className="mt-3 text-sm text-[var(--color-text-secondary)]">No deposits match this view.</p>}

      <ul className="mt-4 space-y-3">
        {records.map(deposit => <li key={deposit.id} className="rounded-xl border p-4" style={borderStyle}>
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="font-semibold">{deposit.currency} {deposit.amount} · {deposit.intent === "advance" ? "Advance payment" : "Deposit"}</p>
              <p className="text-xs text-[var(--color-text-secondary)]">
                {deposit.payment_reference ?? deposit.operation_reference} · {deposit.payer?.name ?? "—"} ({deposit.payer?.type ?? "payer"})
              </p>
              <p className="text-xs text-[var(--color-text-secondary)]">
                {methodLabels[deposit.payment_method] ?? deposit.payment_method} · External ref {deposit.external_reference} · Recorded {new Date(deposit.recorded_at).toLocaleString()} by {deposit.recorded_by.name}
              </p>
              <p className="mt-1 text-sm">{deposit.evidence}</p>
              {deposit.verified_by && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                {deposit.status === "verified" ? "Verified" : "Rejected"} {deposit.verified_at ? new Date(deposit.verified_at).toLocaleString() : ""} by {deposit.verified_by.name}
                {deposit.decision_reason ? ` · ${deposit.decision_reason}` : ""}
              </p>}
            </div>
            <span className={`rounded-lg px-3 py-1 text-xs font-bold uppercase tracking-wide ${statusTone[deposit.status] ?? statusTone.pending}`}>
              {deposit.status}
            </span>
          </div>
          {canManage && deposit.can_verify && <div className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
            <label className="flex-1 text-sm font-semibold">Decision reason
              <input value={reasons[deposit.id] ?? ""} onChange={event => setReasons(previous => ({ ...previous, [deposit.id]: event.target.value }))}
                placeholder="What evidence did you check?" className={controlClass} style={borderStyle} />
            </label>
            <div className="flex gap-2">
              <button type="button" disabled={busy} onClick={() => void decide(deposit, true)}
                className="min-h-11 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-bold text-white disabled:opacity-50">Verify receipt</button>
              <button type="button" disabled={busy} onClick={() => void decide(deposit, false)}
                className="min-h-11 rounded-lg border px-4 text-sm font-semibold text-[var(--color-danger)] disabled:opacity-50" style={borderStyle}>Reject</button>
            </div>
          </div>}
        </li>)}
      </ul>
    </div>
  </section>;
}
