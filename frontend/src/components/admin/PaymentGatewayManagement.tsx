import { useCallback, useEffect, useState } from "react";
import { Check, CreditCard, LoaderCircle, Pencil, Plus, ShieldCheck, Trash2, X } from "lucide-react";
import api from "@/lib/api";
import type { ApiResponse } from "@/types/api";
import PasswordInput from "@/components/ui/PasswordInput";
import { useConfirm } from "@/components/ui/ConfirmDialog";

type ProviderId = "paystack" | "hubtel" | "korapay";
interface Gateway {
  id: number;
  provider: ProviderId;
  display_name: string;
  public_key: string | null;
  secret_key_configured: boolean;
  webhook_secret_configured: boolean;
  configuration: { collection_account_number?: string };
  is_enabled: boolean;
  is_default: boolean;
}
interface ProviderOption {
  provider: ProviderId;
  display_name: string;
  fields: string[];
}
interface GatewayForm {
  provider: ProviderId;
  display_name: string;
  public_key: string;
  secret_key: string;
  secret_key_configured: boolean;
  webhook_secret: string;
  webhook_secret_configured: boolean;
  collection_account_number: string;
  is_enabled: boolean;
  is_default: boolean;
}
interface GatewayIndex {
  gateways: Gateway[];
  supported_providers: ProviderOption[];
}

const emptyForm: GatewayForm = {
  provider: "paystack", display_name: "Paystack", public_key: "", secret_key: "",
  webhook_secret: "", webhook_secret_configured: false,
  collection_account_number: "", secret_key_configured: false, is_enabled: false, is_default: false,
};

export default function PaymentGatewayManagement() {
  const [data, setData] = useState<GatewayIndex>({ gateways: [], supported_providers: [] });
  const [form, setForm] = useState<GatewayForm>(emptyForm);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const confirm = useConfirm();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await api.get<ApiResponse<GatewayIndex>>("/admin/payment-gateways");
      if (response.data.success && response.data.data) setData(response.data.data);
      setError(null);
    } catch (err: any) {
      setError(err.response?.data?.message || "Payment gateways could not be loaded. Apply pending migrations and try again.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const beginCreate = () => {
    const provider = data.supported_providers[0];
    setEditingId(null);
    setForm({ ...emptyForm, provider: provider?.provider ?? "paystack", display_name: provider?.display_name ?? "Paystack" });
    setError(null);
    setOpen(true);
  };

  const beginEdit = (gateway: Gateway) => {
    setEditingId(gateway.id);
    setForm({
      provider: gateway.provider,
      display_name: gateway.display_name,
      public_key: gateway.public_key ?? "",
      secret_key: "",
      secret_key_configured: gateway.secret_key_configured,
      webhook_secret: "",
      webhook_secret_configured: gateway.webhook_secret_configured,
      collection_account_number: gateway.configuration?.collection_account_number ?? "",
      is_enabled: gateway.is_enabled,
      is_default: gateway.is_default,
    });
    setError(null);
    setOpen(true);
  };

  const chooseProvider = (provider: ProviderId) => {
    const option = data.supported_providers.find((item) => item.provider === provider);
    setForm({ ...emptyForm, provider, display_name: option?.display_name ?? "Payment Gateway" });
  };

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError(null);
    setNotice(null);
    const payload = {
      provider: form.provider,
      display_name: form.display_name,
      public_key: form.public_key || null,
      ...(form.secret_key ? { secret_key: form.secret_key } : {}),
      ...(form.webhook_secret ? { webhook_secret: form.webhook_secret } : {}),
      configuration: form.provider === "hubtel" ? { collection_account_number: form.collection_account_number } : {},
      is_enabled: form.is_enabled,
      is_default: form.is_default,
    };
    try {
      if (editingId) await api.put(`/admin/payment-gateways/${editingId}`, payload);
      else await api.post("/admin/payment-gateways", payload);
      setOpen(false);
      setNotice(editingId ? "Gateway settings updated." : "Payment gateway added.");
      await load();
    } catch (err: any) {
      setError(err.response?.data?.message || "Gateway settings could not be saved.");
    } finally {
      setSaving(false);
    }
  };

  const setDefault = async (gateway: Gateway) => {
    setBusyId(gateway.id);
    setError(null);
    try {
      await api.put(`/admin/payment-gateways/${gateway.id}/default`);
      setNotice(`${gateway.display_name} is now the default for new online orders.`);
      await load();
    } catch (err: any) {
      setError(err.response?.data?.message || "Could not set the default gateway.");
    } finally {
      setBusyId(null);
    }
  };

  const remove = async (gateway: Gateway) => {
    if (!await confirm({ title: "Delete payment gateway?", message: `Remove “${gateway.display_name}”? Pending payments tied to this gateway must be verified or cancelled first.`, confirmLabel: "Delete gateway", tone: "danger" })) return;
    setBusyId(gateway.id);
    setError(null);
    try {
      await api.delete(`/admin/payment-gateways/${gateway.id}`);
      setNotice("Payment gateway removed.");
      await load();
    } catch (err: any) {
      setError(err.response?.data?.message || "Could not delete the payment gateway.");
    } finally {
      setBusyId(null);
    }
  };

  const providerLabel = (provider: ProviderId) => data.supported_providers.find((item) => item.provider === provider)?.display_name ?? provider;

  return (
    <section className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-sm">
      <div className="flex flex-col gap-4 border-b border-[var(--color-border)] p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div className="flex items-start gap-3">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-[var(--color-surface-secondary)] text-[var(--color-accent)]"><CreditCard size={21} /></span>
          <div>
            <h2 className="text-base font-bold text-[var(--color-text)]">Online Payment Gateways</h2>
            <p className="mt-1 max-w-2xl text-xs leading-relaxed text-[var(--color-text-secondary)]">Choose which configured provider handles online orders. Only the enabled default gateway is offered at public checkout; cash on delivery remains available.</p>
          </div>
        </div>
        <button type="button" onClick={beginCreate} className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-4 text-sm font-semibold text-[var(--color-background)] shadow-sm transition hover:bg-[var(--color-primary-hover)]"><Plus size={17} /> Add Gateway</button>
      </div>

      {(error || notice) && <div role={error ? "alert" : "status"} className={`mx-5 mt-4 rounded-xl border px-4 py-3 text-sm sm:mx-6 ${error ? "border-[var(--color-danger)]/30 bg-[var(--color-danger)]/10 text-[var(--color-danger)]" : "border-[var(--color-success)]/30 bg-[var(--color-success)]/10 text-[var(--color-success)]"}`}>{error || notice}</div>}

      {loading ? <div className="grid min-h-36 place-items-center text-[var(--color-text-secondary)]"><LoaderCircle className="animate-spin" /></div> : data.gateways.length === 0 ? (
        <div className="p-8 text-center sm:p-12"><div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[var(--color-surface-secondary)] text-[var(--color-accent)]"><CreditCard size={24} /></div><h3 className="mt-4 font-bold text-[var(--color-text)]">No gateways configured</h3><p className="mx-auto mt-1 max-w-md text-sm text-[var(--color-text-secondary)]">Add a provider, enter its merchant credentials, then enable it and set it as the default to show online payment at checkout.</p><button type="button" onClick={beginCreate} className="mt-5 inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-4 text-sm font-semibold text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]"><Plus size={16} /> Configure a gateway</button></div>
      ) : (
        <div className="grid gap-3 p-4 sm:p-5 lg:grid-cols-2">
          {data.gateways.map((gateway) => (
            <article key={gateway.id} className={`rounded-2xl border p-4 sm:p-5 ${gateway.is_default ? "border-[var(--color-accent)]/60 bg-[var(--color-accent)]/5 ring-1 ring-[var(--color-accent)]/20" : "border-[var(--color-border)] bg-[var(--color-background)]"}`}>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2"><h3 className="truncate font-bold text-[var(--color-text)]">{gateway.display_name}</h3>{gateway.is_default && <span className="inline-flex items-center gap-1 rounded-full bg-[var(--color-accent)]/10 px-2 py-1 text-[10px] font-bold text-[var(--color-accent)]"><Check size={12} /> DEFAULT</span>}</div>
                  <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{providerLabel(gateway.provider)}</p>
                </div>
                <span className={`shrink-0 rounded-full px-2.5 py-1 text-[10px] font-bold ${gateway.is_enabled ? "bg-[var(--color-success)]/10 text-[var(--color-success)]" : "bg-[var(--color-surface-secondary)] text-[var(--color-text-secondary)]"}`}>{gateway.is_enabled ? "ENABLED" : "DISABLED"}</span>
              </div>
              <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-[var(--color-border)] pt-3">
                {!gateway.is_default && <button type="button" onClick={() => void setDefault(gateway)} disabled={busyId === gateway.id || !gateway.is_enabled} title={!gateway.is_enabled ? "Enable and configure this gateway before setting it as default" : undefined} className="mr-auto inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-[var(--color-primary)] px-3 text-xs font-semibold text-[var(--color-background)] hover:bg-[var(--color-primary-hover)] disabled:cursor-not-allowed disabled:opacity-50">{busyId === gateway.id ? <LoaderCircle size={14} className="animate-spin" /> : <Check size={14} />} Set default</button>}
                {gateway.is_default && <span className="mr-auto text-xs font-semibold text-[var(--color-success)]">Used for new online orders</span>}
                <button type="button" onClick={() => beginEdit(gateway)} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs font-semibold text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]"><Pencil size={14} /> Edit</button>
                <button type="button" onClick={() => void remove(gateway)} disabled={busyId === gateway.id} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-[var(--color-danger)]/30 px-3 text-xs font-semibold text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 disabled:opacity-50"><Trash2 size={14} /> Delete</button>
              </div>
            </article>
          ))}
        </div>
      )}

      {open && <div className="fixed inset-0 z-[100] grid place-items-center overflow-y-auto bg-black/55 p-3 backdrop-blur-sm" onMouseDown={(event) => { if (event.target === event.currentTarget && !saving) setOpen(false); }}>
        <form onSubmit={save} className="my-auto w-full max-w-2xl overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xl">
          <div className="flex items-start justify-between gap-4 border-b border-[var(--color-border)] px-5 py-4 sm:px-6"><div><h3 className="font-bold text-[var(--color-text)]">{editingId ? "Edit gateway" : "Add payment gateway"}</h3><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Credentials are submitted only to the server and secret keys are never shown again.</p></div><button type="button" disabled={saving} onClick={() => setOpen(false)} aria-label="Close" className="grid h-9 w-9 place-items-center rounded-xl text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]"><X size={18} /></button></div>
          <div className="max-h-[70vh] space-y-4 overflow-y-auto p-5 sm:p-6">
            {!editingId && <label className="block text-xs font-semibold text-[var(--color-text-secondary)]">Provider<select value={form.provider} onChange={(event) => chooseProvider(event.target.value as ProviderId)} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]">{data.supported_providers.map((provider) => <option key={provider.provider} value={provider.provider}>{provider.display_name}</option>)}</select></label>}
            <label className="block text-xs font-semibold text-[var(--color-text-secondary)]">Gateway name<input required maxLength={100} value={form.display_name} onChange={(event) => setForm({ ...form, display_name: event.target.value })} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" placeholder={providerLabel(form.provider)} /></label>
            {(form.provider === "paystack" || form.provider === "hubtel") && <label className="block text-xs font-semibold text-[var(--color-text-secondary)]">{form.provider === "hubtel" ? "Hubtel API ID" : "Paystack Public Key"}<input value={form.public_key} onChange={(event) => setForm({ ...form, public_key: event.target.value })} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" placeholder={form.provider === "paystack" ? "pk_test_… or pk_live_…" : "API ID"} autoComplete="off" /></label>}
            <label className="block text-xs font-semibold text-[var(--color-text-secondary)]">{form.provider === "hubtel" ? "Hubtel API Key" : form.provider === "korapay" ? "Kora Secret Key" : "Paystack Secret Key"}{form.secret_key_configured && <span className="ml-2 font-normal text-[var(--color-success)]">Saved securely · blank preserves current key</span>}<PasswordInput value={form.secret_key} onChange={(event) => setForm({ ...form, secret_key: event.target.value })} autoComplete="new-password" className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" placeholder="Enter merchant secret" /></label>
            {form.provider === "paystack" && <label className="block text-xs font-semibold text-[var(--color-text-secondary)]">Paystack Webhook Signing Secret{form.webhook_secret_configured && <span className="ml-2 font-normal text-[var(--color-success)]">Saved securely · blank preserves current secret</span>}<PasswordInput value={form.webhook_secret} onChange={(event) => setForm({ ...form, webhook_secret: event.target.value })} autoComplete="new-password" className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" placeholder="Optional whsec_…" /><small className="mt-1 block font-normal">Used for Paystack-Signature. Legacy X-Paystack-Signature remains supported.</small></label>}
            {form.provider === "hubtel" && <label className="block text-xs font-semibold text-[var(--color-text-secondary)]">Collection Account Number<input required value={form.collection_account_number} onChange={(event) => setForm({ ...form, collection_account_number: event.target.value })} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" placeholder="Merchant collection account" /></label>}
            {error && <p role="alert" className="rounded-xl border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/10 px-3 py-2.5 text-sm text-[var(--color-danger)]">{error}</p>}
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="flex min-h-16 items-center justify-between gap-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-4 py-3"><span><strong className="block text-sm text-[var(--color-text)]">Enable gateway</strong><small className="text-xs text-[var(--color-text-secondary)]">Allow new online checkouts</small></span><input type="checkbox" checked={form.is_enabled} onChange={(event) => setForm({ ...form, is_enabled: event.target.checked, is_default: event.target.checked ? form.is_default : false })} className="h-5 w-5 accent-[var(--color-primary)]" /></label>
              <label className="flex min-h-16 items-center justify-between gap-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-4 py-3"><span><strong className="block text-sm text-[var(--color-text)]">Make default</strong><small className="text-xs text-[var(--color-text-secondary)]">Use for online payments</small></span><input type="checkbox" checked={form.is_default} disabled={!form.is_enabled} onChange={(event) => setForm({ ...form, is_default: event.target.checked })} className="h-5 w-5 accent-[var(--color-primary)]" /></label>
            </div>
            <div className="flex items-start gap-2 rounded-xl bg-[var(--color-surface-secondary)] p-3 text-xs leading-relaxed text-[var(--color-text-secondary)]"><ShieldCheck size={16} className="mt-0.5 shrink-0 text-[var(--color-accent)]" /><span>Checkout and payment verification run on the backend. Use merchant test credentials in development and configure the provider webhook before live payments.</span></div>
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-[var(--color-border)] px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" disabled={saving} onClick={() => setOpen(false)} className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm font-semibold text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]">Cancel</button><button type="submit" disabled={saving} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-5 text-sm font-semibold text-[var(--color-background)] hover:bg-[var(--color-primary-hover)] disabled:opacity-50">{saving ? <LoaderCircle size={16} className="animate-spin" /> : <Check size={16} />}{saving ? "Saving gateway…" : editingId ? "Save changes" : "Add gateway"}</button></div>
        </form>
      </div>}
    </section>
  );
}
