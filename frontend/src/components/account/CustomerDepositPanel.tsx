import { useCallback, useEffect, useState, type FormEvent } from "react";
import customerApi from "@/lib/customerApi";

type Payer = { type: "customer" | "organisation"; id: number; name: string };
type GatewayOption = { id: number; provider: string; name: string };
type DepositOptions = { payers: Payer[]; gateways: GatewayOption[]; intents: string[]; currency: string };
type Deposit = {
  reference: string;
  amount: string;
  currency: string;
  intent: string | null;
  status: string;
  origin: string;
  payment_method: string;
  provider: string | null;
  payer: Payer | null;
  created_at: string;
  verified_at: string | null;
  received_at: string | null;
  checkout_url: string | null;
  external_reference: string | null;
  credited: boolean;
};

const statusTone: Record<string, string> = {
  pending: "text-[var(--color-warning)] bg-[var(--color-warning)]/10",
  succeeded: "text-[var(--color-success)] bg-[var(--color-success)]/10",
  failed: "text-[var(--color-danger)] bg-[var(--color-danger)]/10",
  cancelled: "text-[var(--color-text-secondary)] bg-[var(--color-background)]",
};

const statusLabel = (status: string) => status.charAt(0).toUpperCase() + status.slice(1);
const intentLabel = (intent: string | null) => (intent === "advance" ? "Advance payment" : "Deposit");

function errorMessage(cause: unknown): string {
  const response = (cause as { response?: { data?: { message?: string } } }).response;
  return response?.data?.message ?? "The deposit could not be started. Please try again.";
}

export default function CustomerDepositPanel() {
  const [options, setOptions] = useState<DepositOptions | null>(null);
  const [deposits, setDeposits] = useState<Deposit[]>([]);
  const [payer, setPayer] = useState("");
  const [intent, setIntent] = useState("deposit");
  const [amount, setAmount] = useState("");
  const [gatewayId, setGatewayId] = useState("");
  const [requestKey, setRequestKey] = useState(() => crypto.randomUUID());
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [checking, setChecking] = useState<string | null>(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async (): Promise<Deposit[]> => {
    const [optionsResponse, historyResponse] = await Promise.all([
      customerApi.get<{ data: DepositOptions }>("/customer/deposits/options"),
      customerApi.get<{ data: Deposit[] }>("/customer/deposits"),
    ]);
    const loaded = optionsResponse.data.data;
    setOptions(loaded);
    setDeposits(historyResponse.data.data);
    setPayer(current => current || (loaded.payers[0] ? `${loaded.payers[0].type}:${loaded.payers[0].id}` : ""));
    setGatewayId(current => current || (loaded.gateways[0] ? String(loaded.gateways[0].id) : ""));
    return historyResponse.data.data;
  }, []);

  useEffect(() => {
    const reference = new URLSearchParams(window.location.search).get("reference");
    load()
      .then(async () => {
        if (!reference) return;
        try {
          const response = await customerApi.get<{ data: Deposit }>(`/customer/deposits/${encodeURIComponent(reference)}`);
          setDeposits(previous => [response.data.data, ...previous.filter(item => item.reference !== response.data.data.reference)]);
          setMessage(`Deposit ${response.data.data.reference} is ${statusLabel(response.data.data.status).toLowerCase()}.`);
        } catch {
          setError("We could not confirm that deposit. Check your deposit history below.");
        }
        // Drop the gateway return parameter so a refresh does not replay it.
        window.history.replaceState({}, "", window.location.pathname);
      })
      .catch(() => setError("Deposit information is temporarily unavailable."))
      .finally(() => setLoading(false));
  }, [load]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setMessage("");
    setError("");
    try {
      const [payerType, payerId] = payer.split(":");
      const response = await customerApi.post<{ data: Deposit }>("/customer/deposits", {
        payer_type: payerType,
        organisation_id: payerType === "organisation" ? Number(payerId) : undefined,
        intent,
        amount,
        payment_gateway_id: Number(gatewayId),
        idempotency_key: requestKey,
      });
      const deposit = response.data.data;
      if (deposit.checkout_url) {
        window.location.assign(deposit.checkout_url);
        return;
      }
      setRequestKey(crypto.randomUUID());
      setMessage(`Deposit ${deposit.reference} is ${statusLabel(deposit.status).toLowerCase()}.`);
      setAmount("");
      await load();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setBusy(false);
    }
  }

  async function refreshStatus(reference: string) {
    setChecking(reference);
    setMessage("");
    setError("");
    try {
      const response = await customerApi.get<{ data: Deposit }>(`/customer/deposits/${encodeURIComponent(reference)}`);
      setDeposits(previous => previous.map(item => (item.reference === reference ? response.data.data : item)));
      setMessage(`Deposit ${reference} is ${statusLabel(response.data.data.status).toLowerCase()}.`);
    } catch {
      setError("The deposit status could not be refreshed. Please try again.");
    } finally {
      setChecking(null);
    }
  }

  const controlClass = "mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 text-sm";
  const borderStyle = { borderColor: "var(--color-border)" };
  const noGateways = options !== null && options.gateways.length === 0;

  return <section className="space-y-6">
    <div className="rounded-2xl border bg-[var(--color-surface)] p-5" style={borderStyle}>
      <h2 className="text-xl font-bold">Make a deposit</h2>
      <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
        Pay a deposit or advance towards a future order or invoice. Your receipt is only confirmed after the payment provider verifies it.
      </p>

      {loading && <p role="status" className="mt-4 text-sm text-[var(--color-text-secondary)]">Loading deposit options…</p>}
      {message && <p role="status" className="mt-4 rounded-lg bg-[var(--color-success)]/10 p-3 text-sm">{message}</p>}
      {error && <p role="alert" className="mt-4 rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}

      {options && !noGateways && <form onSubmit={submit} className="mt-4 grid gap-4 sm:grid-cols-2">
        <label className="text-sm font-semibold">Paying as
          <select value={payer} onChange={event => setPayer(event.target.value)} className={controlClass} style={borderStyle}>
            {options.payers.map(item => <option key={`${item.type}-${item.id}`} value={`${item.type}:${item.id}`}>{item.name} ({item.type})</option>)}
          </select>
        </label>
        <label className="text-sm font-semibold">Purpose
          <select value={intent} onChange={event => setIntent(event.target.value)} className={controlClass} style={borderStyle}>
            <option value="deposit">Deposit</option>
            <option value="advance">Advance payment</option>
          </select>
        </label>
        <label className="text-sm font-semibold">Amount ({options.currency})
          <input required type="number" inputMode="decimal" min="0.01" step="0.01" value={amount}
            onChange={event => setAmount(event.target.value)} className={controlClass} style={borderStyle} />
        </label>
        <label className="text-sm font-semibold">Payment method
          <select value={gatewayId} onChange={event => setGatewayId(event.target.value)} className={controlClass} style={borderStyle}>
            {options.gateways.map(gateway => <option key={gateway.id} value={gateway.id}>{gateway.name}</option>)}
          </select>
        </label>
        <button type="submit" disabled={busy || !amount || Number(amount) <= 0}
          className="min-h-11 rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white disabled:opacity-50 sm:col-span-2 sm:justify-self-start sm:px-8">
          {busy ? "Starting payment…" : "Continue to secure payment"}
        </button>
      </form>}

      {noGateways && <p role="status" className="mt-4 rounded-lg bg-[var(--color-background)] p-3 text-sm text-[var(--color-text-secondary)]">
        Online deposits are not available right now. Please contact the restaurant to arrange a deposit.
      </p>}
    </div>

    <div className="rounded-2xl border bg-[var(--color-surface)] p-5" style={borderStyle}>
      <h2 className="text-xl font-bold">Deposit history</h2>
      {loading && <p role="status" className="mt-3 text-sm text-[var(--color-text-secondary)]">Loading your deposits…</p>}
      {!loading && deposits.length === 0 && <p className="mt-3 text-sm text-[var(--color-text-secondary)]">You have no deposits yet.</p>}
      <ul className="mt-3 space-y-3">
        {deposits.map(deposit => <li key={deposit.reference} className="rounded-xl border p-4" style={borderStyle}>
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="font-semibold">{deposit.currency} {deposit.amount} · {intentLabel(deposit.intent)}</p>
              <p className="text-xs text-[var(--color-text-secondary)]">{deposit.reference} · {new Date(deposit.created_at).toLocaleString()}</p>
              <p className="text-xs text-[var(--color-text-secondary)]">{deposit.payer?.name ?? "—"}{deposit.external_reference ? ` · Ref ${deposit.external_reference}` : ""}</p>
            </div>
            <span className={`rounded-lg px-3 py-1 text-xs font-bold uppercase tracking-wide ${statusTone[deposit.status] ?? statusTone.cancelled}`}>
              {statusLabel(deposit.status)}
            </span>
          </div>
          {deposit.status === "pending" && <div className="mt-3 flex flex-wrap items-center gap-3">
            <button type="button" onClick={() => void refreshStatus(deposit.reference)} disabled={checking === deposit.reference}
              className="min-h-9 rounded-lg border px-3 text-sm font-semibold disabled:opacity-50" style={borderStyle}>
              {checking === deposit.reference ? "Checking…" : "Check status"}
            </button>
            {deposit.checkout_url && <a href={deposit.checkout_url} className="min-h-9 rounded-lg border px-3 text-sm font-semibold no-underline" style={{ ...borderStyle, color: "var(--color-text)" }}>
              Resume payment
            </a>}
          </div>}
          {deposit.status === "succeeded" && <p className="mt-2 text-sm text-[var(--color-success)]">
            {deposit.credited ? "Verified and added to your customer credit." : "Verified receipt. The restaurant can now add it to your customer credit."}
          </p>}
        </li>)}
      </ul>
    </div>
  </section>;
}
