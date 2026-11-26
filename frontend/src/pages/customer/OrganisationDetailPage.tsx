import { useEffect, useState, type FormEvent } from "react";
import { Link, useParams } from "react-router-dom";
import customerApi from "@/lib/customerApi";

type Organisation = { id: number; reference: string; name: string; description: string | null; business_email: string | null; business_phone: string | null; address: string | null; status: "active" | "suspended"; role: "owner" | "administrator" | "manager" | "member" };
type Member = { id: number; customer: { id: number; name: string; email: string | null; phone: string }; role: string; status: string; permissions?: string[]; joined_at: string | null; approved_at: string | null };
type OrganisationInvitation = { id: number; customer: { id: number; name: string }; status: string; expires_at: string; accepted_at: string | null; cancelled_at: string | null };

export default function OrganisationDetailPage() {
  const { organisationId } = useParams();
  const [organisation, setOrganisation] = useState<Organisation | null>(null);
  const [members, setMembers] = useState<Member[]>([]);
  const [invitations, setInvitations] = useState<OrganisationInvitation[]>([]);
  const [customerId, setCustomerId] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  async function refresh() {
    if (!organisationId) return;
    try {
      const o = await customerApi.get<{ data: Organisation }>(`/customer/organisations/${organisationId}`);
      setOrganisation(o.data.data);
      if (o.data.data.status === "active") {
        const m = await customerApi.get<{ data: Member[] }>(`/customer/organisations/${organisationId}/members`);
        setMembers(m.data.data);
        if (o.data.data.role === "owner" || o.data.data.role === "administrator") {
          const invites = await customerApi.get<{ data: OrganisationInvitation[] }>(`/customer/organisations/${organisationId}/invitations`);
          setInvitations(invites.data.data);
        } else setInvitations([]);
      } else { setMembers([]); setInvitations([]); }
    } catch { setError("You cannot access this organisation, or it is no longer available."); }
  }
  useEffect(() => { void refresh(); }, [organisationId]);

  async function updateProfile(event: FormEvent) {
    event.preventDefault(); if (!organisationId || !organisation) return;
    setError(""); setNotice("");
    try {
      const response = await customerApi.patch<{ data: Organisation }>(`/customer/organisations/${organisationId}`, {
        name: organisation.name, description: organisation.description, business_email: organisation.business_email,
        business_phone: organisation.business_phone, address: organisation.address,
      });
      setOrganisation(response.data.data); setNotice("Organisation profile updated.");
    } catch { setError("You do not have permission to update this profile, or the submitted details are invalid."); }
  }

  async function changeStatus() {
    if (!organisationId || !organisation) return;
    setError(""); setNotice("");
    try {
      const status = organisation.status === "active" ? "suspended" : "active";
      const response = await customerApi.patch<{ data: Organisation }>(`/customer/organisations/${organisationId}/status`, { status });
      setOrganisation(response.data.data); setNotice(`Organisation ${status}.`);
    } catch { setError("Only the organisation owner can change its status."); }
  }

  async function changeMember(member: Member, updates: { role?: string; status?: string }) {
    if (!organisationId) return;
    setError(""); setNotice("");
    try {
      const operation = updates.role ? "role" : "status";
      await customerApi.patch(`/customer/organisations/${organisationId}/members/${member.id}/${operation}`, updates);
      setNotice("Membership updated."); await refresh();
    } catch { setError("This membership change is not allowed."); }
  }

  async function changeFinancialAccess(member: Member, enabled: boolean) {
    if (!organisationId) return;
    setError(""); setNotice("");
    try {
      await customerApi.patch(`/customer/organisations/${organisationId}/members/${member.id}/financial-access`, { enabled });
      setNotice(`Financial visibility ${enabled ? "granted" : "removed"} for ${member.customer.name}.`); await refresh();
    } catch { setError("Only an authorised organisation owner or administrator can change financial visibility for this member."); await refresh(); }
  }

  async function inviteCustomer(event: FormEvent) {
    event.preventDefault(); if (!organisationId) return;
    setError(""); setNotice("");
    try {
      await customerApi.post(`/customer/organisations/${organisationId}/invitations`, { customer_id: Number(customerId) });
      setCustomerId(""); setNotice("Invitation sent to the customer's in-app inbox."); await refresh();
    } catch { setError("Invitation could not be created. Confirm the customer ID and check for an existing membership or invitation."); }
  }

  async function cancelInvitation(invitationId: number) {
    if (!organisationId) return;
    setError(""); setNotice("");
    try { await customerApi.delete(`/customer/organisations/${organisationId}/invitations/${invitationId}`); setNotice("Invitation cancelled."); await refresh(); }
    catch { setError("Invitation is no longer pending."); await refresh(); }
  }

  if (error && !organisation) return <div className="mx-auto max-w-4xl px-4 py-8"><p role="alert" className="rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p><Link to="/account/organisations" className="mt-4 inline-block text-[var(--color-accent)]">Back to organisations</Link></div>;
  if (!organisation) return <div className="p-10 text-center text-[var(--color-text-secondary)]">Loading organisation…</div>;

  const canManage = organisation.role === "owner" || organisation.role === "administrator";
  const fields: (keyof Pick<Organisation, "name" | "business_email" | "business_phone" | "address" | "description">)[] = ["name", "business_email", "business_phone", "address", "description"];
  const labels: Record<string, string> = { name: "Name", business_email: "Business email", business_phone: "Business telephone", address: "Address", description: "Description" };

  return <div className="mx-auto max-w-5xl space-y-7 px-4 py-8 sm:px-6">
    <div className="flex flex-wrap items-center justify-between gap-3"><div><Link to="/account/organisations" className="text-sm font-semibold text-[var(--color-accent)]">← Organisations</Link><h1 className="mt-2 text-2xl font-bold">{organisation.name}</h1><p className="text-sm text-[var(--color-text-secondary)]">{organisation.reference} · {organisation.role} · {organisation.status}</p></div>{organisation.role === "owner" && <button onClick={() => void changeStatus()} className="min-h-11 rounded-xl border px-4 font-semibold" style={{ borderColor: "var(--color-border)" }}>{organisation.status === "active" ? "Suspend organisation" : "Reactivate organisation"}</button>}</div>
    {notice && <p role="status" className="rounded-lg bg-[var(--color-success)]/10 p-3 text-sm">{notice}</p>}{error && <p role="alert" className="rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}
    <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}><h2 className="mb-4 text-lg font-bold">Organisation profile</h2><form onSubmit={updateProfile} className="grid gap-4 sm:grid-cols-2">{fields.map(field => <label key={field} className={`block text-sm font-semibold ${field === "address" || field === "description" ? "sm:col-span-2" : ""}`}>{labels[field]}{field === "description" || field === "address" ? <textarea rows={field === "description" ? 3 : 2} disabled={!canManage || organisation.status !== "active"} value={organisation[field] ?? ""} onChange={event => setOrganisation({ ...organisation, [field]: event.target.value })} className="mt-1 w-full rounded-lg border bg-[var(--color-input-background)] p-3 font-normal disabled:opacity-70" style={{ borderColor: "var(--color-border)" }} /> : <input required={field === "name"} type={field === "business_email" ? "email" : field === "business_phone" ? "tel" : "text"} disabled={!canManage || organisation.status !== "active"} value={organisation[field] ?? ""} onChange={event => setOrganisation({ ...organisation, [field]: event.target.value || null })} className="mt-1 min-h-11 w-full rounded-lg border bg-[var(--color-input-background)] px-3 font-normal disabled:opacity-70" style={{ borderColor: "var(--color-border)" }} />}</label>)}{canManage && organisation.status === "active" && <button className="min-h-11 rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white sm:col-span-2 sm:justify-self-start">Save changes</button>}</form></section>
    <section><div className="mb-3 flex flex-wrap items-end justify-between gap-2"><div><h2 className="text-lg font-bold">Members</h2><p className="text-sm text-[var(--color-text-secondary)]">Membership roles and access grants apply only to this organisation.</p></div></div><div className="space-y-3">{members.map(member => <article key={member.id} className="grid gap-3 rounded-xl border bg-[var(--color-surface)] p-4 sm:grid-cols-[1fr_auto] sm:items-center" style={{ borderColor: "var(--color-border)" }}><div><p className="font-semibold">{member.customer.name}{member.role === "owner" ? " · Owner" : ""}</p><p className="text-sm text-[var(--color-text-secondary)]">{member.customer.email || member.customer.phone} · {member.status}</p></div>{canManage && member.status === "active" && <div className="flex flex-wrap items-center gap-2">{member.role !== "owner" && <><select aria-label={`Role for ${member.customer.name}`} value={member.role} disabled={organisation.role === "administrator" && member.role === "administrator"} onChange={event => void changeMember(member, { role: event.target.value })} className="min-h-10 rounded-lg border bg-[var(--color-input-background)] px-2 text-sm disabled:opacity-60" style={{ borderColor: "var(--color-border)" }}>{!(organisation.role === "administrator" && member.role === "administrator") && <option value="administrator">Administrator</option>}<option value="manager">Manager</option><option value="member">Member</option></select><select aria-label={`Status for ${member.customer.name}`} value={member.status} disabled={organisation.role === "administrator" && member.role === "administrator"} onChange={event => void changeMember(member, { status: event.target.value })} className="min-h-10 rounded-lg border bg-[var(--color-input-background)] px-2 text-sm disabled:opacity-60" style={{ borderColor: "var(--color-border)" }}><option value="active">Active</option><option value="suspended">Suspended</option><option value="removed">Removed</option></select></>}<label className="flex min-h-10 items-center gap-2 rounded-lg border px-3 text-sm" style={{ borderColor: "var(--color-border)" }}><input type="checkbox" checked={member.permissions?.includes("financials.view") ?? false} disabled={organisation.role === "administrator" && (member.role === "administrator" || member.role === "owner")} onChange={event => void changeFinancialAccess(member, event.target.checked)} />View invoice payment details</label></div>}</article>)}{members.length === 0 && <p className="rounded-xl border p-4 text-sm text-[var(--color-text-secondary)]" style={{ borderColor: "var(--color-border)" }}>No members yet.</p>}</div></section>
    {canManage && organisation.status === "active" && <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}><h2 className="text-lg font-bold">Invite an existing customer</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Enter their customer ID. The invitation appears only in that signed-in account and expires in seven days; no email or phone number is used as identity proof.</p><form onSubmit={inviteCustomer} className="mt-4 flex flex-col gap-2 sm:flex-row"><label className="sr-only" htmlFor="invite-customer-id">Customer ID</label><input id="invite-customer-id" required type="number" min="1" value={customerId} onChange={event => setCustomerId(event.target.value)} placeholder="Customer ID" className="min-h-11 min-w-0 flex-1 rounded-lg border bg-[var(--color-input-background)] px-3" style={{ borderColor: "var(--color-border)" }} /><button className="min-h-11 rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white">Create invitation</button></form><div className="mt-4 space-y-2">{invitations.map(invitation => <div key={invitation.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm" style={{ borderColor: "var(--color-border)" }}><span>{invitation.customer.name} · {invitation.status} · expires {new Date(invitation.expires_at).toLocaleDateString()}</span>{invitation.status === "pending" && <button onClick={() => void cancelInvitation(invitation.id)} className="min-h-9 rounded-lg border px-3 font-semibold" style={{ borderColor: "var(--color-border)" }}>Cancel</button>}</div>)}</div></section>}
  </div>;
}
