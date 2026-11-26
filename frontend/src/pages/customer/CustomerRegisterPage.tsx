import { useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import customerApi from "@/lib/customerApi";
import PasswordInput from "@/components/ui/PasswordInput";

type AuthResponse = { success: boolean; data?: { token: string }; message?: string };

export default function CustomerRegisterPage() {
  const navigate = useNavigate();
  const [form, setForm] = useState({ name: "", phone: "", email: "", password: "", password_confirmation: "" });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError("");
    try {
      const response = await customerApi.post<AuthResponse>("/customers/register", form);
      if (!response.data.data?.token) throw new Error(response.data.message || "Registration failed.");
      sessionStorage.setItem("special_delicacies_customer_token", response.data.data.token);
      navigate("/account", { replace: true });
    } catch (cause) {
      const fallback = "Please check your details and try again.";
      const message = cause instanceof Error && "response" in cause
        ? ((cause as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data?.message ?? fallback)
        : cause instanceof Error ? cause.message : fallback;
      setError(message);
    } finally { setBusy(false); }
  }

  const field = (name: keyof typeof form, label: string, type = "text", required = true) => <label className="block text-sm font-semibold">{label}{type === "password" ? <PasswordInput required={required} autoComplete="new-password" value={form[name]} onChange={e => setForm({ ...form, [name]: e.target.value })} className="mt-1 min-h-12 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /> : <input required={required} type={type} autoComplete={name} value={form[name]} onChange={e => setForm({ ...form, [name]: e.target.value })} className="mt-1 min-h-12 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} />}</label>;

  return <div className="mx-auto grid min-h-[70vh] max-w-lg place-items-center px-4 py-10">
    <section className="w-full rounded-2xl border bg-[var(--color-surface)] p-6 shadow-[var(--shadow-md)] sm:p-8" style={{ borderColor: "var(--color-border)" }}>
      <h1 className="text-2xl font-bold">Create your account</h1>
      <p className="mt-2 text-sm text-[var(--color-text-secondary)]">Guest checkout remains available if you prefer not to register.</p>
      {error && <p role="alert" className="mt-4 rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}
      <form onSubmit={submit} className="mt-6 space-y-4">
        {field("name", "Full name")}{field("phone", "Telephone number", "tel")}{field("email", "Email address (optional)", "email", false)}{field("password", "Password", "password")}{field("password_confirmation", "Confirm password", "password")}
        <button disabled={busy} className="min-h-12 w-full rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white disabled:opacity-60">{busy ? "Creating account…" : "Create account"}</button>
      </form>
      <p className="mt-5 text-sm">Already registered? <Link className="font-semibold text-[var(--color-accent)]" to="/account/login">Sign in</Link></p>
    </section>
  </div>;
}
