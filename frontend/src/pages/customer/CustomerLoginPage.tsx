import { useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import customerApi from "@/lib/customerApi";
import PasswordInput from "@/components/ui/PasswordInput";

type AuthResponse = { success: boolean; data?: { token: string }; message?: string; errors?: Record<string, string[]> };

export default function CustomerLoginPage() {
  const navigate = useNavigate();
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [remember, setRemember] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError("");
    try {
      const response = await customerApi.post<AuthResponse>("/customers/login", { phone, password, remember });
      if (!remember && !response.data.data?.token) throw new Error(response.data.message || "Sign-in failed.");
      if (response.data.data?.token) sessionStorage.setItem("special_delicacies_customer_token", response.data.data.token);
      else sessionStorage.removeItem("special_delicacies_customer_token");
      navigate("/account", { replace: true });
    } catch (cause) {
      const message = cause instanceof Error ? cause.message : "Unable to sign in.";
      setError(message);
    } finally { setBusy(false); }
  }

  return <div className="mx-auto grid min-h-[70vh] max-w-lg place-items-center px-4 py-10">
    <section className="w-full rounded-2xl border bg-[var(--color-surface)] p-6 shadow-[var(--shadow-md)] sm:p-8" style={{ borderColor: "var(--color-border)" }}>
      <h1 className="text-2xl font-bold">Customer sign in</h1>
      <p className="mt-2 text-sm text-[var(--color-text-secondary)]">Sign in to view your profile and orders.</p>
      {error && <p role="alert" className="mt-4 rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}
      <form onSubmit={submit} className="mt-6 space-y-4">
        <label className="block text-sm font-semibold">Telephone number<input required autoComplete="tel" value={phone} onChange={e => setPhone(e.target.value)} className="mt-1 min-h-12 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
        <label className="block text-sm font-semibold">Password<PasswordInput required autoComplete="current-password" value={password} onChange={e => setPassword(e.target.value)} className="mt-1 min-h-12 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
        <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm"><input type="checkbox" checked={remember} onChange={e => setRemember(e.target.checked)} className="h-4 w-4 accent-[var(--color-accent)]" />Remember me</label>
        <button disabled={busy} className="min-h-12 w-full rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white disabled:opacity-60">{busy ? "Signing in…" : "Sign in"}</button>
      </form>
      <p className="mt-3 text-sm"><Link className="font-semibold text-[var(--color-accent)]" to="/account/password/forgot">Forgot password?</Link></p>
      <p className="mt-5 text-sm">New here? <Link className="font-semibold text-[var(--color-accent)]" to="/account/register">Create an account</Link></p>
    </section>
  </div>;
}
