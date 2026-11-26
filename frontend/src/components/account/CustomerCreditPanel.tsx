import { useEffect, useState } from "react";
import customerApi from "@/lib/customerApi";

type Entry = { type: string; amount: string; currency: string; reason: string; source_reference: string | null; invoice_number: string | null; actor: string; created_at: string };
type Account = { payer: { type: "customer" | "organisation"; id: number; name: string }; balances: { currency: string; available_balance: string }[]; history: Entry[] };

export default function CustomerCreditPanel() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [error, setError] = useState("");
  useEffect(() => { customerApi.get<{ data: { accounts: Account[] } }>("/customer/credit").then(response => setAccounts(response.data.data.accounts)).catch(() => setError("Credit information is temporarily unavailable.")); }, []);
  return <section className="mb-8 rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
    <h2 className="text-xl font-bold">Customer credit</h2>
    <p className="mb-4 mt-1 text-sm text-[var(--color-text-secondary)]">Read-only balances and history for your account and organisations that have granted you financial access.</p>
    {error && <p role="alert" className="text-sm text-[var(--color-danger)]">{error}</p>}
    {!error && accounts.length === 0 && <p className="text-sm text-[var(--color-text-secondary)]">No credit history is available.</p>}
    <div className="space-y-5">{accounts.map(account => <div key={`${account.payer.type}-${account.payer.id}`} className="rounded-xl border p-4" style={{ borderColor: "var(--color-border)" }}>
      <div className="flex flex-wrap items-start justify-between gap-3"><h3 className="font-semibold">{account.payer.name} <span className="text-xs font-normal text-[var(--color-text-secondary)]">({account.payer.type})</span></h3><div className="flex flex-wrap gap-2">{account.balances.map(balance => <span key={balance.currency} className="rounded-lg bg-[var(--color-background)] px-3 py-1 text-sm font-semibold">{balance.currency} {balance.available_balance}</span>)}</div></div>
      {account.history.length > 0 && <ul className="mt-3 divide-y" style={{ borderColor: "var(--color-border)" }}>{account.history.map((entry, index) => <li key={`${entry.type}-${entry.created_at}-${index}`} className="flex flex-wrap justify-between gap-2 py-2 text-sm"><span>{entry.type === "designation" ? "Credit designated" : "Credit applied"} · {entry.reason}<small className="block text-[var(--color-text-secondary)]">{entry.source_reference ? `Receipt ${entry.source_reference}` : entry.invoice_number ? `Invoice ${entry.invoice_number}` : ""} · {entry.actor}</small></span><span className="font-semibold">{entry.currency} {entry.amount}</span></li>)}</ul>}
    </div>)}</div>
  </section>;
}
