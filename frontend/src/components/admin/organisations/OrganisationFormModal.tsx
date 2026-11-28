import { useEffect, useRef, useState } from "react";
import { AlertCircle, Building2, Check, Save, Search, X } from "lucide-react";
import api from "@/lib/api";
import type { OrganisationContact, OrganisationDetail, OrganisationFormValues } from "@/types/organisations";
import {
  emptyOrganisationForm,
  organisationFieldErrors,
  organisationFormFrom,
  organisationFormPayload,
  validateOrganisationForm,
} from "@/lib/organisations";

interface OrganisationFormModalProps {
  mode: "create" | "edit";
  organisationId: number | null;
  onClose: () => void;
  onSaved: (message: string) => void;
}

export default function OrganisationFormModal({ mode, organisationId, onClose, onSaved }: OrganisationFormModalProps) {
  const [form, setForm] = useState<OrganisationFormValues>(emptyOrganisationForm);
  const [loading, setLoading] = useState(mode === "edit");
  const [loadError, setLoadError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<keyof OrganisationFormValues, string>>>({});
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const [contactSearch, setContactSearch] = useState("");
  const [contacts, setContacts] = useState<OrganisationContact[]>([]);
  const [selectedContact, setSelectedContact] = useState<OrganisationContact | null>(null);
  const [contactLoading, setContactLoading] = useState(false);

  const nameRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    if (mode === "edit" && organisationId !== null) {
      let active = true;
      setLoading(true);
      api.get<{ data: { organisation: OrganisationDetail } }>(`/admin/organisations/${organisationId}`)
        .then((response) => {
          if (!active) return;
          const detail = response.data.data.organisation;
          setForm(organisationFormFrom(detail));
          setSelectedContact(detail.owner);
        })
        .catch(() => { if (active) setLoadError("This organisation could not be loaded for editing."); })
        .finally(() => { if (active) setLoading(false); });
      return () => { active = false; };
    }

    return undefined;
  }, [mode, organisationId]);

  useEffect(() => {
    if (!loading) nameRef.current?.focus();
  }, [loading]);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !submitting) onClose();
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [onClose, submitting]);

  // The primary-contact picker only exists for a new organisation; an existing
  // organisation's owner is preserved by the update endpoint.
  useEffect(() => {
    if (mode !== "create") return;
    let active = true;
    setContactLoading(true);
    const timer = setTimeout(() => {
      api.get<{ data: OrganisationContact[] }>("/admin/organisations/contacts", { params: { search: contactSearch.trim() || undefined } })
        .then((response) => { if (active) setContacts(response.data.data); })
        .catch(() => { if (active) setContacts([]); })
        .finally(() => { if (active) setContactLoading(false); });
    }, 300);
    return () => { active = false; clearTimeout(timer); };
  }, [contactSearch, mode]);

  const update = (field: keyof OrganisationFormValues, value: string) => {
    setForm((current) => ({ ...current, [field]: value }));
    setFieldErrors((current) => ({ ...current, [field]: undefined }));
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (submitting || loading) return;

    const validation = validateOrganisationForm(form, mode);
    if (Object.keys(validation).length > 0) {
      setFieldErrors(validation);
      setError("");
      return;
    }

    setSubmitting(true);
    setError("");
    setFieldErrors({});

    try {
      const payload = organisationFormPayload(form, mode);
      if (mode === "edit" && organisationId !== null) {
        await api.put(`/admin/organisations/${organisationId}`, payload);
        onSaved("Organisation updated successfully.");
      } else {
        await api.post("/admin/organisations", payload);
        onSaved("Organisation created successfully.");
      }
    } catch (cause) {
      const failure = cause as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } };
      if (failure.response?.status === 422) {
        setFieldErrors(organisationFieldErrors(failure.response.data));
        setError("Check the highlighted fields and try again.");
      } else if (failure.response?.status === 403) {
        setError("You do not have permission to save this organisation.");
      } else {
        setError(failure.response?.data?.message ?? "The organisation could not be saved. Try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  const title = mode === "edit" ? "Edit organisation" : "Add organisation";

  return (
    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="organisation-form-title"
        className="w-full max-w-xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden my-8"
      >
        <div className="flex items-center justify-between gap-3 p-5 border-b border-[var(--color-border)]">
          <h2 id="organisation-form-title" className="text-lg font-bold text-[var(--color-text)] flex items-center gap-2">
            {mode === "edit" ? <Save size={20} className="text-[var(--brand-copper)]" /> : <Building2 size={20} className="text-[var(--brand-copper)]" />}
            {title}
          </h2>
          <button type="button" onClick={onClose} disabled={submitting} aria-label="Close" className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)] disabled:opacity-40">
            <X size={20} />
          </button>
        </div>

        <form onSubmit={submit} noValidate className="p-5 space-y-4">
          {loading && <p role="status" className="text-sm text-[var(--color-text-secondary)]">Loading organisation…</p>}

          {loadError && (
            <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
              {loadError}
            </div>
          )}

          {error && (
            <div role="alert" className="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
              <AlertCircle size={16} className="mt-0.5 flex-shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div>
            <label htmlFor="organisation-name" className="block text-xs font-semibold text-[var(--color-text)] mb-1">
              Organisation name *
            </label>
            <input
              id="organisation-name"
              ref={nameRef}
              type="text"
              value={form.name}
              onChange={(event) => update("name", event.target.value)}
              placeholder="e.g. Adom Catering Ltd"
              aria-invalid={fieldErrors.name ? true : undefined}
              aria-describedby={fieldErrors.name ? "organisation-name-error" : undefined}
              className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            />
            {fieldErrors.name && <p id="organisation-name-error" className="text-xs text-rose-600 mt-1">{fieldErrors.name}</p>}
          </div>

          {mode === "create" ? (
            <div>
              <label htmlFor="organisation-contact" className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                Primary contact *
              </label>
              {selectedContact ? (
                <div className="flex items-center justify-between gap-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3.5 py-2">
                  <span className="text-sm text-[var(--color-text)]">
                    <span className="font-semibold">{selectedContact.name}</span>
                    <span className="block text-xs text-[var(--color-text-secondary)]">{selectedContact.email ?? selectedContact.phone}</span>
                  </span>
                  <button
                    type="button"
                    onClick={() => {
                      setSelectedContact(null);
                      update("owner_customer_id", "");
                    }}
                    className="text-xs font-semibold text-[var(--color-accent)]"
                  >
                    Change
                  </button>
                </div>
              ) : (
                <div className="space-y-2">
                  <div className="relative">
                    <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]" />
                    <input
                      id="organisation-contact"
                      type="search"
                      value={contactSearch}
                      onChange={(event) => setContactSearch(event.target.value)}
                      placeholder="Search active customers by name, email or phone…"
                      aria-invalid={fieldErrors.owner_customer_id ? true : undefined}
                      aria-describedby={fieldErrors.owner_customer_id ? "organisation-contact-error" : undefined}
                      className="w-full pl-9 pr-3 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
                    />
                  </div>
                  <div className="max-h-40 overflow-y-auto rounded-xl border border-[var(--color-border)] divide-y divide-[var(--color-border)]" role="listbox" aria-label="Primary contact candidates">
                    {contactLoading && <p className="p-3 text-xs text-[var(--color-text-secondary)]">Searching customers…</p>}
                    {!contactLoading && contacts.length === 0 && <p className="p-3 text-xs text-[var(--color-text-secondary)]">No active customers match that search.</p>}
                    {contacts.map((candidate) => (
                      <button
                        key={candidate.id}
                        type="button"
                        role="option"
                        aria-selected={false}
                        onClick={() => {
                          setSelectedContact(candidate);
                          update("owner_customer_id", String(candidate.id));
                        }}
                        className="flex w-full items-center justify-between gap-3 p-3 text-left text-sm text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]"
                      >
                        <span>
                          <span className="font-semibold">{candidate.name}</span>
                          <span className="block text-xs text-[var(--color-text-secondary)]">{candidate.email ?? candidate.phone}</span>
                        </span>
                        <Check size={16} className="text-[var(--color-accent)]" />
                      </button>
                    ))}
                  </div>
                </div>
              )}
              {fieldErrors.owner_customer_id && <p id="organisation-contact-error" className="text-xs text-rose-600 mt-1">{fieldErrors.owner_customer_id}</p>}
            </div>
          ) : (
            <p className="text-xs text-[var(--color-text-secondary)]">
              Primary contact: <span className="font-semibold text-[var(--color-text)]">{selectedContact?.name ?? "—"}</span>. Ownership and membership
              relationships are preserved; manage them from the organisation's detail view.
            </p>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label htmlFor="organisation-email" className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                Contact email
              </label>
              <input
                id="organisation-email"
                type="email"
                value={form.business_email}
                onChange={(event) => update("business_email", event.target.value)}
                placeholder="accounts@example.com"
                aria-invalid={fieldErrors.business_email ? true : undefined}
                aria-describedby={fieldErrors.business_email ? "organisation-email-error" : undefined}
                className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
              />
              {fieldErrors.business_email && <p id="organisation-email-error" className="text-xs text-rose-600 mt-1">{fieldErrors.business_email}</p>}
            </div>

            <div>
              <label htmlFor="organisation-phone" className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                Contact phone
              </label>
              <input
                id="organisation-phone"
                type="tel"
                value={form.business_phone}
                onChange={(event) => update("business_phone", event.target.value)}
                placeholder="030 200 0000"
                aria-invalid={fieldErrors.business_phone ? true : undefined}
                aria-describedby={fieldErrors.business_phone ? "organisation-phone-error" : undefined}
                className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
              />
              {fieldErrors.business_phone && <p id="organisation-phone-error" className="text-xs text-rose-600 mt-1">{fieldErrors.business_phone}</p>}
            </div>
          </div>

          <div>
            <label htmlFor="organisation-address" className="block text-xs font-semibold text-[var(--color-text)] mb-1">
              Address
            </label>
            <textarea
              id="organisation-address"
              value={form.address}
              onChange={(event) => update("address", event.target.value)}
              rows={2}
              placeholder="Street, area, city"
              className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            />
          </div>

          <div>
            <label htmlFor="organisation-description" className="block text-xs font-semibold text-[var(--color-text)] mb-1">
              Description
            </label>
            <textarea
              id="organisation-description"
              value={form.description}
              onChange={(event) => update("description", event.target.value)}
              rows={3}
              placeholder="What this organisation buys or how it works with the restaurant"
              className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            />
          </div>

          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} disabled={submitting} className="btn-ghost px-4 py-2 text-sm font-semibold disabled:opacity-50">
              Cancel
            </button>
            <button type="submit" disabled={submitting || loading} className="btn-accent px-4 py-2 text-sm font-semibold disabled:opacity-50">
              {submitting ? "Saving…" : mode === "edit" ? "Save changes" : "Add organisation"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
