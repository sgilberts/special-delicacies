import { useEffect, useRef, useState } from "react";
import { AlertCircle, Save, UserPlus, X } from "lucide-react";
import api from "@/lib/api";
import type { CustomerDetail, CustomerFormValues } from "@/types/customers";
import {
  customerFieldErrors,
  customerFormFrom,
  customerFormPayload,
  emptyCustomerForm,
  validateCustomerForm,
} from "@/lib/customers";

export interface EditableCustomer {
  id: number;
  name: string;
  email: string | null;
  phone: string;
}

interface CustomerFormModalProps {
  mode: "create" | "edit";
  /** Required in edit mode: the record is loaded before the form opens. */
  customer?: EditableCustomer | null;
  onClose: () => void;
  onSaved: (customer: CustomerDetail, mode: "create" | "edit") => void;
}

export default function CustomerFormModal({ mode, customer, onClose, onSaved }: CustomerFormModalProps) {
  const [form, setForm] = useState<CustomerFormValues>(() =>
    mode === "edit" && customer ? customerFormFrom(customer) : emptyCustomerForm(),
  );
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<keyof CustomerFormValues, string>>>({});
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const nameRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    nameRef.current?.focus();
  }, []);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !submitting) onClose();
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [onClose, submitting]);

  const update = (field: keyof CustomerFormValues, value: string) => {
    setForm((current) => ({ ...current, [field]: value }));
    setFieldErrors((current) => ({ ...current, [field]: undefined }));
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (submitting) return;

    const validation = validateCustomerForm(form);
    if (Object.keys(validation).length > 0) {
      setFieldErrors(validation);
      setError("");
      return;
    }

    setSubmitting(true);
    setError("");
    setFieldErrors({});

    const payload = customerFormPayload(form);

    try {
      const response = mode === "edit" && customer
        ? await api.put<{ data: CustomerDetail }>(`/admin/customers/${customer.id}`, payload)
        : await api.post<{ data: CustomerDetail }>("/admin/customers", payload);
      onSaved(response.data.data, mode);
    } catch (cause) {
      const failure = cause as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } };
      if (failure.response?.status === 422) {
        setFieldErrors(customerFieldErrors(failure.response.data));
        setError("Check the highlighted fields and try again.");
      } else if (failure.response?.status === 403) {
        setError("You do not have permission to save this customer.");
      } else {
        setError(failure.response?.data?.message ?? "The customer could not be saved. Try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  const title = mode === "edit" ? "Edit customer" : "Add customer";

  return (
    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="customer-form-title"
        className="w-full max-w-lg rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xl overflow-hidden my-8"
      >
        <div className="flex items-center justify-between gap-3 p-5 border-b border-[var(--color-border)]">
          <h2 id="customer-form-title" className="text-lg font-bold text-[var(--color-text)] flex items-center gap-2">
            {mode === "edit" ? <Save size={20} className="text-[var(--brand-copper)]" /> : <UserPlus size={20} className="text-[var(--brand-copper)]" />}
            {title}
          </h2>
          <button
            type="button"
            onClick={onClose}
            disabled={submitting}
            aria-label="Close"
            className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)] disabled:opacity-40"
          >
            <X size={20} />
          </button>
        </div>

        <form onSubmit={submit} noValidate className="p-5 space-y-4">
          {error && (
            <div role="alert" className="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
              <AlertCircle size={16} className="mt-0.5 flex-shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div>
            <label htmlFor="customer-name" className="block text-xs font-semibold text-[var(--color-text)] mb-1">
              Full name *
            </label>
            <input
              id="customer-name"
              ref={nameRef}
              type="text"
              value={form.name}
              onChange={(event) => update("name", event.target.value)}
              placeholder="e.g. Ama Mensah"
              aria-invalid={fieldErrors.name ? true : undefined}
              aria-describedby={fieldErrors.name ? "customer-name-error" : undefined}
              className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
            />
            {fieldErrors.name && <p id="customer-name-error" className="text-xs text-rose-600 mt-1">{fieldErrors.name}</p>}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label htmlFor="customer-email" className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                Email address
              </label>
              <input
                id="customer-email"
                type="email"
                value={form.email}
                onChange={(event) => update("email", event.target.value)}
                placeholder="customer@example.com"
                aria-invalid={fieldErrors.email ? true : undefined}
                aria-describedby={fieldErrors.email ? "customer-email-error" : undefined}
                className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
              />
              {fieldErrors.email && <p id="customer-email-error" className="text-xs text-rose-600 mt-1">{fieldErrors.email}</p>}
            </div>

            <div>
              <label htmlFor="customer-phone" className="block text-xs font-semibold text-[var(--color-text)] mb-1">
                Telephone number *
              </label>
              <input
                id="customer-phone"
                type="tel"
                value={form.phone}
                onChange={(event) => update("phone", event.target.value)}
                placeholder="024 400 0000"
                aria-invalid={fieldErrors.phone ? true : undefined}
                aria-describedby={fieldErrors.phone ? "customer-phone-error" : undefined}
                className="w-full px-3.5 py-2 rounded-xl bg-[var(--color-background)] border border-[var(--color-border)] text-sm text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-copper)]"
              />
              {fieldErrors.phone && <p id="customer-phone-error" className="text-xs text-rose-600 mt-1">{fieldErrors.phone}</p>}
            </div>
          </div>

          {mode === "create" && (
            <p className="text-xs text-[var(--color-text-secondary)]">
              The customer signs in with the telephone number or email address above and sets their own password through
              the password-recovery flow. Administrators never choose a customer password.
            </p>
          )}

          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={onClose}
              disabled={submitting}
              className="btn-ghost px-4 py-2 text-sm font-semibold disabled:opacity-50"
            >
              Cancel
            </button>
            <button type="submit" disabled={submitting} className="btn-accent px-4 py-2 text-sm font-semibold disabled:opacity-50">
              {submitting ? "Saving…" : mode === "edit" ? "Save changes" : "Add customer"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
