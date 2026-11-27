import { useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, RefreshCw, ShieldCheck, UserMinus, UserX } from "lucide-react";
import api from "@/lib/api";
import { useConfirm } from "@/components/ui/ConfirmDialog";
import type { OrganisationListMeta, OrganisationMember } from "@/types/organisations";
import {
  ORGANISATION_MEMBER_PAGE_SIZE,
  formatOrganisationDate,
  membershipRoleLabel,
  membershipStatusLabel,
  membershipStatusTone,
} from "@/lib/organisations";
import { pageWindow } from "@/lib/customers";

interface OrganisationMembersPanelProps {
  organisationId: number;
  canManageMembers: boolean;
  refreshToken: number;
  onChanged: (message: string) => void;
}

interface MembersResponse {
  data: OrganisationMember[];
  meta: OrganisationListMeta;
}

export default function OrganisationMembersPanel({ organisationId, canManageMembers, refreshToken, onChanged }: OrganisationMembersPanelProps) {
  const confirm = useConfirm();
  const [members, setMembers] = useState<OrganisationMember[]>([]);
  const [meta, setMeta] = useState<OrganisationListMeta>({ current_page: 1, last_page: 1, per_page: ORGANISATION_MEMBER_PAGE_SIZE, total: 0 });
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [busyId, setBusyId] = useState<number | null>(null);
  const requestId = useRef(0);

  const load = useCallback(async () => {
    const id = ++requestId.current;
    setLoading(true);
    setError("");
    try {
      const response = await api.get<MembersResponse>(`/admin/organisations/${organisationId}/members`, {
        params: {
          page,
          per_page: ORGANISATION_MEMBER_PAGE_SIZE,
          search: search.trim() || undefined,
          status: status || undefined,
        },
      });
      if (id !== requestId.current) return;
      setMembers(response.data.data);
      setMeta(response.data.meta);
    } catch (cause) {
      if (id !== requestId.current) return;
      const failure = cause as { response?: { status?: number; data?: { message?: string } } };
      setMembers([]);
      setError(
        failure.response?.status === 403
          ? "You do not have permission to view this organisation's members."
          : failure.response?.data?.message ?? "Members could not be loaded.",
      );
    } finally {
      if (id === requestId.current) setLoading(false);
    }
  }, [organisationId, page, search, status]);

  useEffect(() => {
    void load();
  }, [load]);

  // A real-time membership event (or a change made in this modal) refetches the
  // authoritative page; the list is never patched by hand.
  useEffect(() => {
    if (refreshToken === 0) return;
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refreshToken]);

  const changeStatus = async (member: OrganisationMember, next: "active" | "suspended" | "removed") => {
    const label = next === "active" ? "reactivate" : next === "suspended" ? "suspend" : "remove";
    const confirmed = await confirm({
      title: `${label.charAt(0).toUpperCase()}${label.slice(1)} member`,
      message: `${member.customer?.name ?? "This member"} will be marked ${membershipStatusLabel(next).toLowerCase()}. Financial and audit history is preserved.`,
      confirmLabel: next === "active" ? "Reactivate" : label.charAt(0).toUpperCase() + label.slice(1),
      tone: next === "active" ? "primary" : "danger",
    });
    if (!confirmed) return;

    setBusyId(member.id);
    setError("");
    try {
      await api.patch(`/admin/organisations/${organisationId}/members/${member.id}/status`, { status: next });
      onChanged(`Membership ${membershipStatusLabel(next).toLowerCase()}.`);
      await load();
    } catch (cause) {
      const failure = cause as { response?: { data?: { message?: string } } };
      setError(failure.response?.data?.message ?? "The membership could not be updated.");
    } finally {
      setBusyId(null);
    }
  };

  const pages = pageWindow(meta.current_page, meta.last_page);

  return (
    <section aria-labelledby="organisation-members-heading" className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h3 id="organisation-members-heading" className="text-sm font-bold text-[var(--color-text)]">Members</h3>
          <p className="text-xs text-[var(--color-text-secondary)]">
            {meta.total} {meta.total === 1 ? "membership" : "memberships"} recorded
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <input
            type="search"
            value={search}
            onChange={(event) => { setSearch(event.target.value); setPage(1); }}
            placeholder="Search members…"
            aria-label="Search organisation members"
            className="px-3 py-1.5 rounded-lg bg-[var(--color-background)] border border-[var(--color-border)] text-xs text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          />
          <select
            value={status}
            onChange={(event) => { setStatus(event.target.value); setPage(1); }}
            aria-label="Filter members by status"
            className="px-3 py-1.5 rounded-lg bg-[var(--color-background)] border border-[var(--color-border)] text-xs font-semibold text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
          >
            <option value="">All members</option>
            <option value="active">Active</option>
            <option value="pending">Pending</option>
            <option value="suspended">Suspended</option>
            <option value="removed">Removed</option>
          </select>
        </div>
      </div>

      {error && (
        <div role="alert" className="flex items-start justify-between gap-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
          <span className="flex items-start gap-2"><AlertCircle size={14} className="mt-0.5 flex-shrink-0" />{error}</span>
          <button type="button" onClick={() => void load()} className="underline font-semibold whitespace-nowrap">Retry</button>
        </div>
      )}

      {loading ? (
        <p role="status" className="flex items-center gap-2 rounded-lg border border-[var(--color-border)] p-3 text-xs text-[var(--color-text-secondary)]">
          <RefreshCw size={14} className="animate-spin" /> Loading members…
        </p>
      ) : members.length === 0 ? (
        <p className="rounded-lg border border-[var(--color-border)] p-3 text-xs text-[var(--color-text-secondary)]">
          No memberships match this view.
        </p>
      ) : (
        <ul className="divide-y divide-[var(--color-border)] rounded-xl border border-[var(--color-border)]">
          {members.map((member) => (
            <li key={member.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
              <div className="min-w-0">
                <p className="text-sm font-semibold text-[var(--color-text)]">
                  {member.customer?.name ?? "Removed customer"}
                  {member.financial_access && (
                    <span className="ml-2 inline-flex items-center gap-1 rounded-full bg-[var(--color-accent)]/10 px-2 py-0.5 text-[10px] font-bold text-[var(--color-accent)]">
                      <ShieldCheck size={11} /> Financial access
                    </span>
                  )}
                </p>
                <p className="text-xs text-[var(--color-text-secondary)] break-all">
                  {member.customer?.email ?? member.customer?.phone ?? "No contact details"}
                </p>
                <p className="text-xs text-[var(--color-text-secondary)]">
                  {membershipRoleLabel(member.role)} · Joined {formatOrganisationDate(member.joined_at ?? member.created_at)}
                </p>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border ${membershipStatusTone(member.status)}`}>
                  {membershipStatusLabel(member.status)}
                </span>
                {canManageMembers && member.role !== "owner" && (
                  <>
                    {member.status === "active" && (
                      <button
                        type="button"
                        disabled={busyId === member.id}
                        onClick={() => void changeStatus(member, "suspended")}
                        className="inline-flex items-center gap-1 rounded-lg border border-[var(--color-border)] px-2 py-1 text-[11px] font-semibold text-[var(--color-text)] disabled:opacity-40"
                      >
                        <UserX size={12} /> Suspend
                      </button>
                    )}
                    {(member.status === "suspended" || member.status === "pending") && (
                      <button
                        type="button"
                        disabled={busyId === member.id}
                        onClick={() => void changeStatus(member, "active")}
                        className="inline-flex items-center gap-1 rounded-lg border border-[var(--color-border)] px-2 py-1 text-[11px] font-semibold text-[var(--color-text)] disabled:opacity-40"
                      >
                        <ShieldCheck size={12} /> Activate
                      </button>
                    )}
                    {member.status !== "removed" && (
                      <button
                        type="button"
                        disabled={busyId === member.id}
                        onClick={() => void changeStatus(member, "removed")}
                        className="inline-flex items-center gap-1 rounded-lg border border-[var(--color-danger)] px-2 py-1 text-[11px] font-semibold text-[var(--color-danger)] disabled:opacity-40"
                      >
                        <UserMinus size={12} /> Remove
                      </button>
                    )}
                  </>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {meta.last_page > 1 && (
        <div className="flex items-center justify-between gap-2 text-xs text-[var(--color-text-secondary)]">
          <p>Page {meta.current_page} of {meta.last_page}</p>
          <div className="flex items-center gap-1">
            <button
              type="button"
              disabled={meta.current_page <= 1}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
              className="px-2.5 py-1 rounded-lg border border-[var(--color-border)] font-semibold text-[var(--color-text)] disabled:opacity-40"
            >
              Previous
            </button>
            {pages.map((value) => (
              <button
                key={value}
                type="button"
                onClick={() => setPage(value)}
                aria-current={value === meta.current_page ? "page" : undefined}
                className={`px-2.5 py-1 rounded-lg border font-semibold ${value === meta.current_page ? "border-[var(--brand-copper)] text-[var(--brand-copper)]" : "border-[var(--color-border)] text-[var(--color-text)]"}`}
              >
                {value}
              </button>
            ))}
            <button
              type="button"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => setPage((current) => Math.min(meta.last_page, current + 1))}
              className="px-2.5 py-1 rounded-lg border border-[var(--color-border)] font-semibold text-[var(--color-text)] disabled:opacity-40"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </section>
  );
}
