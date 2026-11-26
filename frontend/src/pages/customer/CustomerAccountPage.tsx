import { useEffect, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { Link } from "react-router-dom";
import customerApi from "@/lib/customerApi";
import AccountSecurityPanel from "@/components/account/AccountSecurityPanel";
import CustomerCreditPanel from "@/components/account/CustomerCreditPanel";

type Profile = { id: number; name: string; phone: string; email: string | null; is_active: boolean; email_verified_at: string | null; phone_verified_at: string | null };
type CustomerOrder = { order_number: string; order_type: string; status: string; payment_status: string; total: string; created_at: string; items: { item_name: string; quantity: number }[] };

export default function CustomerAccountPage() {
  const navigate = useNavigate();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [orders, setOrders] = useState<CustomerOrder[]>([]);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    Promise.all([customerApi.get<{ data: Profile }>("/customer/profile"), customerApi.get<{ data: CustomerOrder[] }>("/customer/orders")])
      .then(([p, o]) => { setProfile(p.data.data); setOrders(o.data.data); })
      .catch(() => setError("We could not load your account. Please sign in again."));
  }, []);

  async function update(event: FormEvent) {
    event.preventDefault(); if (!profile) return;
    setMessage(""); setError("");
    try {
      const response = await customerApi.patch<{ data: Profile }>("/customer/profile", { name: profile.name, phone: profile.phone, email: profile.email || null });
      setProfile(response.data.data); setMessage("Profile updated.");
    } catch { setError("Profile update failed. Check that your telephone number is not already used by another account."); }
  }

  async function logout() {
    try { await customerApi.post("/customer/logout"); } catch { /* The local session still needs clearing. */ }
    localStorage.removeItem("special_delicacies_customer_token");
    navigate("/account/login", { replace: true });
  }

  async function refreshProfile() {
    const response = await customerApi.get<{ data: Profile }>("/customer/profile");
    setProfile(response.data.data);
  }

  return <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6">
    <div className="mb-6 flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold">Your account</h1><p className="text-sm text-[var(--color-text-secondary)]">Manage your profile and view orders placed while signed in.{profile && <span className="ml-2">Customer ID: {profile.id}</span>}</p></div><div className="flex flex-wrap gap-2"><Link to="/account/documents" className="inline-flex min-h-11 items-center rounded-xl border px-4 text-sm font-semibold no-underline" style={{ borderColor: "var(--color-border)", color: "var(--color-text)" }}>Quotations & invoices</Link><Link to="/account/organisations" className="inline-flex min-h-11 items-center rounded-xl border px-4 text-sm font-semibold no-underline" style={{ borderColor: "var(--color-border)", color: "var(--color-text)" }}>Organisations</Link><button onClick={() => void logout()} className="min-h-11 rounded-xl border px-4 font-semibold" style={{ borderColor: "var(--color-border)" }}>Sign out</button></div></div>
    {message && <p role="status" className="mb-4 rounded-lg bg-[var(--color-success)]/10 p-3 text-sm">{message}</p>}{error && <p role="alert" className="mb-4 rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}
    {profile && <form onSubmit={update} className="mb-8 grid gap-4 rounded-2xl border bg-[var(--color-surface)] p-5 sm:grid-cols-3" style={{ borderColor: "var(--color-border)" }}>
      <label className="text-sm font-semibold">Name<input required value={profile.name} onChange={e => setProfile({ ...profile, name: e.target.value })} className="mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
      <label className="text-sm font-semibold">Telephone<input required type="tel" value={profile.phone} onChange={e => setProfile({ ...profile, phone: e.target.value })} className="mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
      <label className="text-sm font-semibold">Email (optional)<input type="email" value={profile.email ?? ""} onChange={e => setProfile({ ...profile, email: e.target.value || null })} className="mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
      <button className="min-h-11 rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white sm:col-span-3 sm:justify-self-start">Save profile</button>
    </form>}
    {profile && <div className="mb-8"><AccountSecurityPanel accountType="customer" email={profile.email} phone={profile.phone} emailVerifiedAt={profile.email_verified_at} phoneVerifiedAt={profile.phone_verified_at} onVerified={() => void refreshProfile()} /></div>}
    <CustomerCreditPanel />
    <h2 className="mb-3 text-xl font-bold">Your orders</h2>
    {orders.length === 0 ? <p className="rounded-xl border p-5 text-sm text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>No orders have been linked to this account. Guest orders are not linked automatically.</p> : <div className="space-y-3">{orders.map(order => <article key={order.order_number} className="rounded-xl border bg-[var(--color-surface)] p-4" style={{ borderColor: "var(--color-border)" }}><div className="flex flex-wrap justify-between gap-2"><div><h3 className="font-bold">{order.order_number}</h3><p className="text-sm text-[var(--color-text-secondary)]">{new Date(order.created_at).toLocaleString()}</p></div><p className="font-semibold">GH₵{Number(order.total).toFixed(2)}</p></div><p className="mt-2 text-sm">{order.status.replaceAll("_", " ")} · payment {order.payment_status}</p><p className="mt-1 text-sm text-[var(--color-text-secondary)]">{order.items.map(item => `${item.quantity} × ${item.item_name}`).join(", ")}</p></article>)}</div>}
  </div>;
}
