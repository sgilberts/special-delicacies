import { useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, Archive, Building2, Edit2, RefreshCw, RotateCcw, ShieldAlert, Users, WalletCards, X } from "lucide-react";
import api from "@/lib/api";
import type {
  OrganisationActivityEvent,
  OrganisationDetail,
  OrganisationFinancials,
  OrganisationRow,
} from "@/types/organisations";
import type { OrganisationCapabilities } from "@/lib/organisations";
import {
  formatOrganisationDate,
  formatOrganisationDateTime,
  formatOrganisationMoney,
  membershipRoleLabel,
  organisationActivityLabel,
  organisationLifecycleAction,
  organisationStatusLabel,
  organisationStatusTone,
  shouldLoadFinancials,
} from "@/lib/organisations";
import { sectionFailureState, showsEmptyState, type SectionLoadState } from "@/lib/sectionLoad";
import OrganisationMembersPanel from "./OrganisationMembersPanel";

interface OrganisationDetailModalProps {
  organisationId: number;
  refreshToken: number;
  capabilities: OrganisationCapabilities;
  onClose: () => void;
  onEdit: (organisationId: number) => void;
  onArchive: (row: Pick<OrganisationRow, "id" | "name">) => void;
  onRestore: (row: Pick<OrganisationRow, "id" | "name">) => void;
  onChanged: (message: string) => void;
}

type ProfileState = "loading" | "ready" | "error";

export default function OrganisationDetailModal({
  organisationId,
  refreshToken,
  capabilities,
  onClose,
  onEdit,
  onArchive,
  onRestore,
  onChanged,
}: OrganisationDetailModalProps) {
  const [profileState, setProfileState] = useState<ProfileState>("loading");
  const [organisation, setOrganisation] = useState<OrganisationDetail | null>(null);
  const [activity, setActivity] = useState<OrganisationActivityEvent[]>([]);
  const [financialState, setFinancialState] = useState<SectionLoadState>("loading");
  const [financials, setFinancials] = useState<OrganisationFinancials | null>(null);
  const [memberToken, setMemberToken] = useState(0);
  const membersRef = useRef<HTMLDivElement | null>(null);
  const firstLoad = useRef(true);

  const loadProfile = useCallback(async (silent = false) => {
    if (!silent) setProfileState("loading");
    try {
      const response = await api.get<{ data: { organisation: OrganisationDetail; activity: OrganisationActivityEvent[] } }>(
        `/admin/organisations/${organisationId}`,
      );
      setOrganisation(response.data.data.organisation);
      setActivity(response.data.data.activity ?? []);
      setProfileState("ready");
    } catch {
      if (!silent) setProfileState("error");
    }
  }, [organisationId]);

  const loadFinancials = useCallback(async () => {
    setFinancialState("loading");
    try {
      const response = await api.get<{ data: OrganisationFinancials }>(`/admin/organisations/${organisationId}/financials`);
      setFinancials(response.data.data);
      setFinancialState("ready");
    } catch (cause) {
      setFinancials(null);
      setFinancialState(sectionFailureState(cause));
    }
  }, [organisationId]);

  useEffect(() => {
    void loadProfile();
  }, [loadProfile]);

  // A real-time event for this organisation (or a change made here) refetches
  // the modal's data instead of leaving a stale snapshot on screen.
  useEffect(() => {
    if (firstLoad.current) {
      firstLoad.current = false;
      return;
    }
    void loadProfile(true);
    setMemberToken((token) => token + 1);
    if (shouldLoadFinancials(capabilities, true)) void loadFinancials();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refreshToken]);

  useEffect(() => {
    if (!shouldLoadFinancials(capabilities, true)) {
      setFinancialState("forbidden");
      setFinancials(null);
      return;
    }
    void loadFinancials();
  }, [capabilities, loadFinancials]);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [onClose]);

  const lifecycle = organisation ? organisationLifecycleAction(organisation.status, capabilities) : null;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-start justify-center p-4 overflow-y-auto">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="organisation-detail-title"
        className="w-full max-w-3xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden my-8"
      >
        <div className="flex items-start justify-between gap-3 p-5 border-b border-[var(--color-border)]">
          <div className="min-w-0">
            <h2 id="organisation-detail-title" className="text-lg font-bold text-[var(--color-text)] truncate">
              {organisation?.name ?? "Organisation"}
            </h2>
            <p className="text-xs text-[var(--color-text-secondary)] mt-1">
              {organisation ? `${organisation.reference} · Created ${formatOrganisationDate(organisation.created_at)}` : "Loading organisation…"}
            </p>
          </div>
          <div className="flex items-center gap-2">
            {organisation && (
              <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border ${organisationStatusTone(organisation.status)}`}>
                {organisationStatusLabel(organisation.status)}
              </span>
            )}
            <button type="button" onClick={onClose} aria-label="Close" className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)]">
              <X size={20} />
            </button>
          </div>
        </div>

        <div className="p-5 space-y-6 max-h-[70vh] overflow-y-auto">
          {profileState === "loading" && (
            <div className="py-10 text-center text-sm text-[var(--color-text-secondary)]" role="status">
              <RefreshCw size={22} className="animate-spin mx-auto mb-2 text-[var(--brand-copper)]" />
              Loading organisation details…
            </div>
          )}

          {profileState === "error" && (
            <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
              <p className="font-semibold">This organisation could not be loaded.</p>
              <button type="button" onClick={() => void loadProfile()} className="mt-2 underline font-semibold">Retry</button>
            </div>
          )}

          {organisation && (
            <>
              <section aria-labelledby="organisation-profile-heading" className="space-y-3">
                <h3 id="organisation-profile-heading" className="text-sm font-bold text-[var(--color-text)] flex items-center gap-2">
                  <Building2 size={16} className="text-[var(--brand-copper)]" /> Organisation profile
                </h3>
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Organisation name</dt><dd className="mt-0.5 text-[var(--color-text)]">{organisation.name}</dd></div>
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Reference</dt><dd className="mt-0.5 font-mono text-[var(--color-text)]">{organisation.reference}</dd></div>
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Primary contact</dt><dd className="mt-0.5 text-[var(--color-text)]">{organisation.owner?.name ?? "—"}</dd></div>
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Contact email</dt><dd className="mt-0.5 text-[var(--color-text)] break-all">{organisation.business_email ?? organisation.owner?.email ?? "—"}</dd></div>
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Contact phone</dt><dd className="mt-0.5 text-[var(--color-text)]">{organisation.business_phone ?? organisation.owner?.phone ?? "—"}</dd></div>
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Status</dt><dd className="mt-0.5 text-[var(--color-text)]">{organisationStatusLabel(organisation.status)}</dd></div>
                  <div className="sm:col-span-2"><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Address</dt><dd className="mt-0.5 text-[var(--color-text)]">{organisation.address ?? "—"}</dd></div>
                  {organisation.description && <div className="sm:col-span-2"><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Description</dt><dd className="mt-0.5 whitespace-pre-wrap text-[var(--color-text)]">{organisation.description}</dd></div>}
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Created</dt><dd className="mt-0.5 text-[var(--color-text)]">{formatOrganisationDateTime(organisation.created_at)}</dd></div>
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Last updated</dt><dd className="mt-0.5 text-[var(--color-text)]">{formatOrganisationDateTime(organisation.updated_at)}</dd></div>
                </dl>
              </section>

              <section aria-labelledby="organisation-membership-heading" className="space-y-3">
                <h3 id="organisation-membership-heading" className="text-sm font-bold text-[var(--color-text)] flex items-center gap-2">
                  <Users size={16} className="text-[var(--brand-copper)]" /> Membership overview
                </h3>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                  {[
                    ["Total members", organisation.membership.total],
                    ["Active", organisation.membership.active],
                    ["Pending", organisation.membership.pending],
                    ["Inactive", organisation.membership.suspended + organisation.membership.removed],
                  ].map(([label, value]) => (
                    <div key={String(label)} className="rounded-xl bg-[var(--color-background)] p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</p>
                      <p className="mt-1 text-lg font-bold text-[var(--color-text)]">{value}</p>
                    </div>
                  ))}
                </div>
                {organisation.membership.roles.length > 0 && (
                  <p className="text-xs text-[var(--color-text-secondary)]">
                    Active roles: {organisation.membership.roles.map((entry) => `${membershipRoleLabel(entry.role)} (${entry.count})`).join(" · ")}
                  </p>
                )}

                <div ref={membersRef}>
                  {capabilities.canManageMembers ? (
                    <OrganisationMembersPanel
                      organisationId={organisationId}
                      canManageMembers={capabilities.canManageMembers}
                      refreshToken={memberToken}
                      onChanged={(text) => {
                        onChanged(text);
                        void loadProfile(true);
                      }}
                    />
                  ) : (
                    <p className="rounded-lg border border-[var(--color-border)] p-3 text-xs text-[var(--color-text-secondary)]">
                      You can see membership counts, but viewing the member list requires the organisation member-management
                      permission.
                    </p>
                  )}
                </div>
              </section>

              <section aria-labelledby="organisation-financial-heading" className="space-y-3">
                <h3 id="organisation-financial-heading" className="text-sm font-bold text-[var(--color-text)] flex items-center gap-2">
                  <WalletCards size={16} className="text-[var(--brand-copper)]" /> Financial overview
                </h3>
                {financialState === "forbidden" && (
                  <p className="flex items-start gap-2 rounded-lg border border-[var(--color-border)] p-3 text-xs text-[var(--color-text-secondary)]">
                    <ShieldAlert size={14} className="mt-0.5 flex-shrink-0" />
                    Financial information is restricted. It requires the organisation financial-visibility permission and is never
                    included with the profile.
                  </p>
                )}
                {financialState === "loading" && <p role="status" className="text-xs text-[var(--color-text-secondary)]">Loading financial summary…</p>}
                {financialState === "error" && (
                  <div role="alert" className="flex items-center justify-between gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
                    <span className="flex items-center gap-2"><AlertCircle size={14} /> The financial summary could not be loaded.</span>
                    <button type="button" onClick={() => void loadFinancials()} className="underline font-semibold">Retry</button>
                  </div>
                )}
                {showsEmptyState(financialState) && financials && (
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    {[
                      ["Payments received", formatOrganisationMoney(financials.currency, financials.payments_received), `${financials.payments_count} payments`],
                      ["Deposits & advances", formatOrganisationMoney(financials.currency, financials.deposits_total), `${financials.deposits_count} deposits`],
                      ["Outstanding invoices", formatOrganisationMoney(financials.currency, financials.outstanding_invoices), `${financials.outstanding_invoice_count} invoices`],
                      ["Credit balance", formatOrganisationMoney(financials.currency, financials.credit_balance), "Customer credit"],
                      ["Refunds", formatOrganisationMoney(financials.currency, financials.refunds_total), `${financials.refunds_confirmed_count} confirmed · ${financials.refunds_pending_count} pending`],
                      ["Last payment", financials.last_payment_at ? formatOrganisationDateTime(financials.last_payment_at) : "—", "Most recent receipt"],
                    ].map(([label, value, hint]) => (
                      <div key={label} className="rounded-xl bg-[var(--color-background)] p-3">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</p>
                        <p className="mt-1 text-sm font-bold text-[var(--color-text)]">{value}</p>
                        <p className="text-[11px] text-[var(--color-text-secondary)]">{hint}</p>
                      </div>
                    ))}
                  </div>
                )}
              </section>

              <section aria-labelledby="organisation-activity-heading" className="space-y-3">
                <h3 id="organisation-activity-heading" className="text-sm font-bold text-[var(--color-text)]">Activity and audit history</h3>
                {activity.length === 0 ? (
                  <p className="rounded-lg border border-[var(--color-border)] p-3 text-xs text-[var(--color-text-secondary)]">
                    No activity has been recorded for this organisation yet.
                  </p>
                ) : (
                  <ol className="space-y-3">
                    {activity.map((event, index) => (
                      <li key={`${event.action}-${event.occurred_at}-${index}`} className="border-l-2 border-[var(--color-accent)] pl-3">
                        <p className="text-sm font-semibold text-[var(--color-text)]">{organisationActivityLabel(event.action)}</p>
                        <p className="text-xs text-[var(--color-text-secondary)]">
                          {event.actor ? `${event.actor} · ` : ""}{formatOrganisationDateTime(event.occurred_at)}
                          {event.description ? ` · ${event.description}` : ""}
                        </p>
                      </li>
                    ))}
                  </ol>
                )}
              </section>
            </>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-[var(--color-border)] p-4">
          {organisation && capabilities.canManageMembers && (
            <button
              type="button"
              onClick={() => membersRef.current?.scrollIntoView({ behavior: "smooth", block: "center" })}
              className="btn-ghost px-3 py-2 text-sm font-semibold inline-flex items-center gap-1"
            >
              <Users size={15} /> Manage members
            </button>
          )}
          {organisation && capabilities.canUpdate && (
            <button
              type="button"
              onClick={() => onEdit(organisation.id)}
              className="btn-ghost px-3 py-2 text-sm font-semibold inline-flex items-center gap-1"
            >
              <Edit2 size={15} /> Edit organisation
            </button>
          )}
          {organisation && lifecycle === "archive" && (
            <button
              type="button"
              onClick={() => onArchive({ id: organisation.id, name: organisation.name })}
              className="px-3 py-2 text-sm font-semibold rounded-lg border border-[var(--color-danger)] text-[var(--color-danger)] inline-flex items-center gap-1"
            >
              <Archive size={15} /> Archive
            </button>
          )}
          {organisation && lifecycle === "restore" && (
            <button
              type="button"
              onClick={() => onRestore({ id: organisation.id, name: organisation.name })}
              className="btn-accent px-3 py-2 text-sm font-semibold inline-flex items-center gap-1"
            >
              <RotateCcw size={15} /> Restore
            </button>
          )}
          <button type="button" onClick={onClose} className="btn-ghost px-3 py-2 text-sm font-semibold">Close</button>
        </div>
      </div>
    </div>
  );
}
