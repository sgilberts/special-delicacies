import { useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import customerApi from "@/lib/customerApi";

type OrganisationSummary = { id: number; reference: string; name: string; status: string; role: string; membership_status: string };
type ReceivedInvitation = { id: number; organisation: { id: number; reference: string; name: string }; invited_by: { name: string }; expires_at: string };

export default function OrganisationsPage() {
  const [organisations, setOrganisations] = useState<OrganisationSummary[]>([]);
  const [invitations, setInvitations] = useState<ReceivedInvitation[]>([]);
  const [form, setForm] = useState({ name: "", description: "", business_email: "", business_phone: "", address: "" });
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(true);

  async function refresh() {
    setLoading(true);
    try {
      const [orgs, invites] = await Promise.all([
        customerApi.get<{ data: OrganisationSummary[] }>("/customer/organisations"),
        customerApi.get<{ data: ReceivedInvitation[] }>("/customer/invitations"),
      ]);
      setOrganisations(orgs.data.data); setInvitations(invites.data.data);
    } catch { setError("Could not load your organisations and invitations."); }
    finally { setLoading(false); }
  }
  useEffect(() => { void refresh(); }, []);

  async function create(event: FormEvent) {
    event.preventDefault(); setError(""); setNotice("");
    try {
      await customerApi.post("/customer/organisations", { ...form, business_email: form.business_email || null });
      setForm({ name: "", description: "", business_email: "", business_phone: "", address: "" });
      setNotice("Organisation created. You are its owner."); refresh();
    } catch { setError("Organisation creation failed. Please check the details and try again."); }
  }

  async function acceptInvitation(invitationId: number) {
    setError(""); setNotice("");
    try {
      await customerApi.post(`/customer/invitations/${invitationId}/accept`);
      setNotice("Invitation accepted."); await refresh();
    } catch { setError("This invitation is expired or no longer available."); await refresh(); }
  }

  const input = (key: keyof typeof form, label: string, type = "text", required = false) => <label className="block text-sm font-semibold">{label}<input required={required} type={type} value={form[key]} onChange={event => setForm({ ...form, [key]: event.target.value })} className="mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>;

  return <div className="mx-auto max-w-5xl space-y-8 px-4 py-8 sm:px-6">
    <header><h1 className="text-2xl font-bold">Your organisations</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Choose an organisation to manage its profile and memberships.</p></header>
    {notice && <p role="status" className="rounded-lg bg-[var(--color-success)]/10 p-3 text-sm">{notice}</p>}{error && <p role="alert" className="rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}
    <section><h2 className="mb-3 text-lg font-bold">Memberships</h2>{loading ? <p className="text-sm text-[var(--color-text-secondary)]">Loading…</p> : organisations.length === 0 ? <p className="rounded-xl border p-4 text-sm text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>You do not belong to an organisation yet.</p> : <div className="grid gap-3 sm:grid-cols-2">{organisations.map(org => <Link key={org.id} to={`/account/organisations/${org.id}`} className="rounded-xl border bg-[var(--color-surface)] p-4 no-underline hover:border-[var(--color-accent)]" style={{ borderColor: "var(--color-border)", color: "var(--color-text)" }}><span className="block font-bold">{org.name}</span><span className="mt-1 block text-xs text-[var(--color-text-secondary)]">{org.reference} · {org.role} · {org.status}</span></Link>)}</div>}</section>
    <section><h2 className="mb-3 text-lg font-bold">Invitations for you</h2>{invitations.length === 0 ? <p className="rounded-xl border p-4 text-sm text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>No pending invitations.</p> : <div className="space-y-3">{invitations.map(invitation => <article key={invitation.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-[var(--color-surface)] p-4" style={{ borderColor: "var(--color-border)" }}><div><p className="font-semibold">{invitation.organisation.name}</p><p className="text-sm text-[var(--color-text-secondary)]">Invited by {invitation.invited_by.name} · expires {new Date(invitation.expires_at).toLocaleDateString()}</p></div><button onClick={() => void acceptInvitation(invitation.id)} className="min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-bold text-white">Accept</button></article>)}</div>}</section>
    <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}><h2 className="text-lg font-bold">Create an organisation</h2><p className="mb-4 mt-1 text-sm text-[var(--color-text-secondary)]">You will become its initial owner.</p>
      <form onSubmit={create} className="grid gap-4 sm:grid-cols-2">{input("name", "Organisation name", "text", true)}{input("business_email", "Business email", "email")}{input("business_phone", "Business telephone", "tel")}<label className="block text-sm font-semibold sm:col-span-2">Description<textarea rows={3} value={form.description} onChange={event => setForm({ ...form, description: event.target.value })} className="mt-1 w-full rounded-lg border bg-[var(--color-input-background)] p-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label><label className="block text-sm font-semibold sm:col-span-2">Address<textarea rows={2} value={form.address} onChange={event => setForm({ ...form, address: event.target.value })} className="mt-1 w-full rounded-lg border bg-[var(--color-input-background)] p-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label><button className="min-h-11 rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white sm:col-span-2 sm:justify-self-start">Create organisation</button></form>
    </section>
  </div>;
}
