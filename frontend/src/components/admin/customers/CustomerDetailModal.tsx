import { useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, Archive, Edit2, RefreshCw, RotateCcw, ShieldAlert, WalletCards, X } from "lucide-react";
import api from "@/lib/api";
import type { CustomerActivityEvent, CustomerDetail, CustomerFinancials } from "@/types/customers";
import type { CustomerCapabilities } from "@/lib/customers";
import {
  activityLabel,
  customerLifecycleAction,
  customerStatusLabel,
  customerStatusTone,
  customerTypeLabel,
  formatCustomerDate,
  formatCustomerDateTime,
  formatCustomerMoney,
  shouldLoadFinancials,
} from "@/lib/customers";
import { sectionFailureState, showsEmptyState, type SectionLoadState } from "@/lib/sectionLoad";

interface CustomerDetailModalProps {
  customerId: number;
  /** Bumped by the page when a real-time event touches this customer. */
  refreshToken?: number;
  capabilities: CustomerCapabilities;
  onClose: () => void;
  onEdit: (customer: CustomerDetail) => void;
  onArchive: (customer: CustomerDetail) => void;
  onRestore: (customer: CustomerDetail) => void;
}

type ProfileState = "loading" | "ready" | "error";

export default function CustomerDetailModal({
  customerId,
  refreshToken,
  capabilities,
  onClose,
  onEdit,
  onArchive,
  onRestore,
}: CustomerDetailModalProps) {
  const [profileState, setProfileState] = useState<ProfileState>("loading");
  const [customer, setCustomer] = useState<CustomerDetail | null>(null);
  const [activity, setActivity] = useState<CustomerActivityEvent[]>([]);
  const [financialState, setFinancialState] = useState<SectionLoadState>("loading");
  const [financials, setFinancials] = useState<CustomerFinancials | null>(null);

  const loadProfile = useCallback(async (silent = false) => {
    if (!silent) setProfileState("loading");
    try {
      const response = await api.get<{ data: { customer: CustomerDetail; activity: CustomerActivityEvent[] } }>(
        `/admin/customers/${customerId}`,
      );
      setCustomer(response.data.data.customer);
      setActivity(response.data.data.activity ?? []);
      setProfileState("ready");
    } catch {
      // A failed background refresh keeps the last good snapshot on screen.
      if (!silent) setProfileState("error");
    }
  }, [customerId]);

  const loadFinancials = useCallback(async () => {
    setFinancialState("loading");
    try {
      const response = await api.get<{ data: CustomerFinancials }>(`/admin/customers/${customerId}/financials`);
      setFinancials(response.data.data);
      setFinancialState("ready");
    } catch (cause) {
      setFinancials(null);
      setFinancialState(sectionFailureState(cause));
    }
  }, [customerId]);

  useEffect(() => {
    void loadProfile();
  }, [loadProfile]);

  useEffect(() => {
    // Restricted financial information is never requested just because the
    // profile modal is open.
    if (!shouldLoadFinancials(capabilities, true)) {
      setFinancialState("forbidden");
      setFinancials(null);
      return;
    }
    void loadFinancials();
  }, [capabilities, loadFinancials]);

  // A real-time event (or a change made elsewhere) refetches this modal, so a
  // reopened or open customer never shows a stale snapshot.
  const firstRefresh = useRef(true);
  useEffect(() => {
    if (refreshToken === undefined) return;
    if (firstRefresh.current) {
      firstRefresh.current = false;
      return;
    }
    void loadProfile(true);
    if (shouldLoadFinancials(capabilities, true)) void loadFinancials();
  }, [refreshToken, capabilities, loadProfile, loadFinancials]);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [onClose]);

  const lifecycle = customer ? customerLifecycleAction(customer.status, capabilities) : null;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-start justify-center p-4 overflow-y-auto">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="customer-detail-title"
        className="w-full max-w-3xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden my-8"
      >
        <div className="flex items-start justify-between gap-3 p-5 border-b border-[var(--color-border)]">
          <div className="min-w-0">
            <h2 id="customer-detail-title" className="text-lg font-bold text-[var(--color-text)] truncate">
              {customer?.name ?? "Customer"}
            </h2>
            <p className="text-xs text-[var(--color-text-secondary)] mt-1">
              {customer ? `${customer.reference} · Joined ${formatCustomerDate(customer.joined_at)}` : "Loading customer…"}
            </p>
          </div>
          <div className="flex items-center gap-2">
            {customer && (
              <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border ${customerStatusTone(customer.status)}`}>
                {customerStatusLabel(customer.status)}
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
              Loading customer details…
            </div>
          )}

          {profileState === "error" && (
            <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
              <p className="font-semibold">This customer could not be loaded.</p>
              <button type="button" onClick={() => void loadProfile()} className="mt-2 underline font-semibold">
                Retry
              </button>
            </div>
          )}

          {profileState === "ready" && customer && (
            <>
              {/* A. Profile */}
              <section aria-labelledby="customer-profile-heading">
                <h3 id="customer-profile-heading" className="text-sm font-bold text-[var(--color-text)] mb-3">
                  Customer profile
                </h3>
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Full name</dt>
                    <dd className="text-[var(--color-text)] font-medium">{customer.name}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Customer reference</dt>
                    <dd className="text-[var(--color-text)] font-mono text-xs">{customer.reference}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Customer type</dt>
                    <dd className="text-[var(--color-text)]">{customerTypeLabel(customer.customer_type)}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Account status</dt>
                    <dd className="text-[var(--color-text)]">{customerStatusLabel(customer.status)}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Registration date</dt>
                    <dd className="text-[var(--color-text)]">{formatCustomerDate(customer.joined_at)}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Last activity</dt>
                    <dd className="text-[var(--color-text)]">{formatCustomerDateTime(customer.last_activity_at)}</dd>
                  </div>
                </dl>
              </section>

              {/* B. Contact and identity */}
              <section aria-labelledby="customer-contact-heading">
                <h3 id="customer-contact-heading" className="text-sm font-bold text-[var(--color-text)] mb-3">
                  Contact and identity
                </h3>
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Email address</dt>
                    <dd className="text-[var(--color-text)] break-all">{customer.email ?? "Not provided"}</dd>
                    <dd className="text-xs mt-1">
                      {customer.email_verified ? (
                        <span className="text-[var(--color-success)] font-semibold">Verified</span>
                      ) : (
                        <span className="text-[var(--color-text-secondary)]">Not verified</span>
                      )}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Telephone number</dt>
                    <dd className="text-[var(--color-text)]">{customer.phone}</dd>
                    <dd className="text-xs mt-1">
                      {customer.phone_verified ? (
                        <span className="text-[var(--color-success)] font-semibold">Verified</span>
                      ) : (
                        <span className="text-[var(--color-text-secondary)]">Not verified</span>
                      )}
                    </dd>
                  </div>
                </dl>

                <div className="mt-3">
                  <p className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Organisation memberships</p>
                  {customer.memberships.length === 0 ? (
                    <p className="text-sm text-[var(--color-text-secondary)] mt-1">No organisation memberships recorded.</p>
                  ) : (
                    <ul className="mt-1 space-y-1 text-sm text-[var(--color-text)]">
                      {customer.memberships.map((membership, index) => (
                        <li key={`${membership.organisation ?? "organisation"}-${index}`} className="flex flex-wrap items-center gap-2">
                          <span className="font-medium">{membership.organisation ?? "Unknown organisation"}</span>
                          <span className="text-xs text-[var(--color-text-secondary)] capitalize">
                            {membership.role} · {membership.status}
                          </span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              </section>

              {/* C. Financial overview */}
              <section aria-labelledby="customer-financials-heading">
                <h3 id="customer-financials-heading" className="text-sm font-bold text-[var(--color-text)] mb-3 flex items-center gap-2">
                  <WalletCards size={16} className="text-[var(--brand-copper)]" />
                  Financial overview
                </h3>

                {financialState === "forbidden" && !capabilities.canViewFinancials && (
                  <p className="text-sm text-[var(--color-text-secondary)] flex items-start gap-2">
                    <ShieldAlert size={16} className="mt-0.5 flex-shrink-0" />
                    Financial information requires the “View Customer Financials” permission. Contact a super administrator
                    if you need it.
                  </p>
                )}

                {financialState === "forbidden" && capabilities.canViewFinancials && (
                  <p role="alert" className="text-sm text-[var(--color-danger)]">
                    You do not have permission to view this customer&apos;s financial information.
                  </p>
                )}

                {financialState === "loading" && (
                  <p role="status" className="text-sm text-[var(--color-text-secondary)]">Loading financial summary…</p>
                )}

                {financialState === "error" && (
                  <p role="alert" className="text-sm text-[var(--color-danger)]">
                    The financial summary could not be loaded.{" "}
                    <button type="button" onClick={() => void loadFinancials()} className="underline font-semibold">
                      Retry
                    </button>
                  </p>
                )}

                {showsEmptyState(financialState) && financials && (
                  <>
                    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                      <div>
                        <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Total payments received</dt>
                        <dd className="text-[var(--color-text)] font-semibold">
                          {formatCustomerMoney(financials.currency, financials.payments_received)}
                        </dd>
                        <dd className="text-xs text-[var(--color-text-secondary)]">{financials.payments_count} settled receipt(s)</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Total deposits</dt>
                        <dd className="text-[var(--color-text)] font-semibold">
                          {formatCustomerMoney(financials.currency, financials.deposits_total)}
                        </dd>
                        <dd className="text-xs text-[var(--color-text-secondary)]">{financials.deposits_count} deposit receipt(s)</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Outstanding invoice balance</dt>
                        <dd className="text-[var(--color-text)] font-semibold">
                          {formatCustomerMoney(financials.currency, financials.outstanding_invoices)}
                        </dd>
                        <dd className="text-xs text-[var(--color-text-secondary)]">{financials.outstanding_invoice_count} open invoice(s)</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Available customer credit</dt>
                        <dd className="text-[var(--color-text)] font-semibold">
                          {formatCustomerMoney(financials.currency, financials.credit_balance)}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Refunds confirmed</dt>
                        <dd className="text-[var(--color-text)] font-semibold">
                          {formatCustomerMoney(financials.currency, financials.refunds_total)}
                        </dd>
                        <dd className="text-xs text-[var(--color-text-secondary)]">
                          {financials.refunds_confirmed_count} confirmed · {financials.refunds_pending_count} in progress
                        </dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Last payment</dt>
                        <dd className="text-[var(--color-text)]">{formatCustomerDateTime(financials.last_payment_at)}</dd>
                      </div>
                    </dl>
                    <p className="text-xs text-[var(--color-text-secondary)] mt-3">
                      Reported by the financial services that own these records; figures are not recalculated here.
                    </p>
                  </>
                )}
              </section>

              {/* D. Activity and history */}
              <section aria-labelledby="customer-activity-heading">
                <h3 id="customer-activity-heading" className="text-sm font-bold text-[var(--color-text)] mb-3">
                  Activity and history
                </h3>
                {activity.length === 0 ? (
                  <p className="text-sm text-[var(--color-text-secondary)]">No recorded activity for this customer yet.</p>
                ) : (
                  <ul className="space-y-2">
                    {activity.map((event, index) => (
                      <li
                        key={`${event.action}-${event.occurred_at}-${index}`}
                        className="rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] p-3 text-sm"
                      >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <span className="font-medium text-[var(--color-text)]">{activityLabel(event.action)}</span>
                          <span className="text-xs text-[var(--color-text-secondary)]">{formatCustomerDateTime(event.occurred_at)}</span>
                        </div>
                        <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                          {event.actor ? `By ${event.actor}` : "By the customer"}
                          {event.fields.length > 0 ? ` · ${event.fields.join(", ")}` : ""}
                        </p>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            </>
          )}
        </div>

        <div className="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-2 p-5 border-t border-[var(--color-border)]">
          <button type="button" onClick={onClose} className="btn-ghost px-4 py-2 text-sm font-semibold">
            Close
          </button>
          <div className="flex flex-wrap items-center gap-2">
            {profileState === "ready" && customer && capabilities.canUpdate && (
              <button
                type="button"
                onClick={() => onEdit(customer)}
                className="btn-ghost px-4 py-2 text-sm font-semibold inline-flex items-center gap-1.5"
              >
                <Edit2 size={15} /> Edit
              </button>
            )}
            {profileState === "ready" && customer && lifecycle === "archive" && (
              <button
                type="button"
                onClick={() => onArchive(customer)}
                className="px-4 py-2 text-sm font-semibold rounded-xl border border-[var(--color-danger)] text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 inline-flex items-center gap-1.5"
              >
                <Archive size={15} /> Archive
              </button>
            )}
            {profileState === "ready" && customer && lifecycle === "restore" && (
              <button
                type="button"
                onClick={() => onRestore(customer)}
                className="btn-accent px-4 py-2 text-sm font-semibold inline-flex items-center gap-1.5"
              >
                <RotateCcw size={15} /> Restore
              </button>
            )}
            {profileState === "error" && (
              <span className="text-xs text-[var(--color-text-secondary)] inline-flex items-center gap-1">
                <AlertCircle size={14} /> Actions unavailable until the record loads.
              </span>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
