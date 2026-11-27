import { useState, type FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import api from "@/lib/api";
import customerApi from "@/lib/customerApi";
import PasswordInput from "@/components/ui/PasswordInput";

type Props = { accountType: "customer" | "staff" };
type Channel = "email" | "phone";

export default function PasswordRecoveryPage({ accountType }: Props) {
  const [params] = useSearchParams();
  const isStaff = accountType === "staff";
  const client = isStaff ? api : customerApi;
  const [channel, setChannel] = useState<Channel>(params.get("method") === "phone" ? "phone" : "email");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [challengeId, setChallengeId] = useState(params.get("challenge") ?? "");
  const [token, setToken] = useState(params.get("token") ?? "");
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [phoneRequestSent, setPhoneRequestSent] = useState(false);
  const isReset = Boolean(params.get("token") || challengeId || phoneRequestSent);

  async function requestRecovery(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(""); setMessage("");
    try {
      const url = channel === "email"
        ? (isStaff ? "/auth/password/forgot" : "/customers/password/forgot")
        : (isStaff ? "/auth/password/phone/forgot" : "/customers/password/phone/forgot");
      await client.post(url, channel === "email" ? { email } : { phone });
      if (channel === "phone") setPhoneRequestSent(true);
      setMessage(channel === "email"
        ? "If the account can use email recovery, a reset link will be sent. Check your inbox."
        : "If the account can use phone recovery and SMS delivery is configured, an SMS will be sent. Enter the reference and code from that message below.");
    } catch {
      setError("We could not process the request. Please wait a moment and try again.");
    } finally { setBusy(false); }
  }

  async function resetPassword(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(""); setMessage("");
    try {
      const url = channel === "phone"
        ? (isStaff ? "/auth/password/phone/reset" : "/customers/password/phone/reset")
        : (isStaff ? "/auth/password/reset" : "/customers/password/reset");
      await client.post(url, {
        challenge_id: challengeId,
        ...(channel === "phone" ? { code } : { token }),
        password,
        password_confirmation: confirmation,
      });
      setMessage("Password reset successfully. Sign in with your new password.");
      setPassword(""); setConfirmation(""); setToken(""); setCode("");
    } catch {
      setError("The reset details may be invalid or expired. Request a new reset and try again.");
    } finally { setBusy(false); }
  }

  const loginPath = isStaff ? "/admin/login" : "/account/login";
  return <main className="mx-auto grid min-h-[70vh] max-w-lg place-items-center px-4 py-10">
    <section className="w-full rounded-2xl border bg-[var(--color-surface)] p-6 shadow-[var(--shadow-md)] sm:p-8" style={{ borderColor: "var(--color-border)" }}>
      <h1 className="text-2xl font-bold">{isReset ? "Set a new password" : "Recover your password"}</h1>
      <p className="mt-2 text-sm text-[var(--color-text-secondary)]">Recovery links and codes are single-use and expire. We will not confirm whether an account exists.</p>
      {message && <p role="status" className="mt-4 rounded-lg bg-[var(--color-success)]/10 p-3 text-sm">{message}</p>}
      {error && <p role="alert" className="mt-4 rounded-lg bg-[var(--color-danger)]/10 p-3 text-sm text-[var(--color-danger)]">{error}</p>}

      {!isReset && <>
        <div className="mt-5 flex gap-2" role="group" aria-label="Choose recovery method">
          <button type="button" onClick={() => setChannel("email")} aria-pressed={channel === "email"} className={`min-h-11 flex-1 rounded-xl border px-3 text-sm font-semibold ${channel === "email" ? "bg-[var(--color-accent)] text-white" : ""}`} style={{ borderColor: "var(--color-border)" }}>Email link</button>
          <button type="button" onClick={() => setChannel("phone")} aria-pressed={channel === "phone"} className={`min-h-11 flex-1 rounded-xl border px-3 text-sm font-semibold ${channel === "phone" ? "bg-[var(--color-accent)] text-white" : ""}`} style={{ borderColor: "var(--color-border)" }}>Phone code</button>
        </div>
        <form onSubmit={requestRecovery} className="mt-5 space-y-4">
          {channel === "email"
            ? <label className="block text-sm font-semibold">Email address<input required type="email" autoComplete="email" value={email} onChange={event => setEmail(event.target.value)} className="mt-1 min-h-11 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
            : <label className="block text-sm font-semibold">Ghana telephone number<input required type="tel" autoComplete="tel" value={phone} onChange={event => setPhone(event.target.value)} className="mt-1 min-h-11 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>}
          <button disabled={busy} className="min-h-11 w-full rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white disabled:opacity-60">{busy ? "Sending…" : "Send recovery instructions"}</button>
        </form>
      </>}

      {isReset && <form onSubmit={resetPassword} className="mt-5 space-y-4">
        {channel === "phone" ? <>
          <label className="block text-sm font-semibold">Request reference<input required value={challengeId} onChange={event => setChallengeId(event.target.value)} className="mt-1 min-h-11 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
          <label className="block text-sm font-semibold">Six-digit code<input required inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={code} onChange={event => setCode(event.target.value)} className="mt-1 min-h-11 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
        </> : <>
          <label className="block text-sm font-semibold">Reset token<PasswordInput required aria-label="Reset token" autoComplete="off" value={token} onChange={event => setToken(event.target.value)} className="mt-1 min-h-11 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
          <input type="hidden" value={challengeId} readOnly />
        </>}
        <label className="block text-sm font-semibold">New password<PasswordInput required minLength={10} autoComplete="new-password" value={password} onChange={event => setPassword(event.target.value)} className="mt-1 min-h-11 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
        <label className="block text-sm font-semibold">Confirm new password<PasswordInput required minLength={10} autoComplete="new-password" value={confirmation} onChange={event => setConfirmation(event.target.value)} className="mt-1 min-h-11 w-full rounded-xl border bg-[var(--color-input-background)] px-3 font-normal" style={{ borderColor: "var(--color-border)" }} /></label>
        <button disabled={busy} className="min-h-11 w-full rounded-xl bg-[var(--color-accent)] px-4 font-bold text-white disabled:opacity-60">{busy ? "Updating…" : "Reset password"}</button>
      </form>}

      <p className="mt-5 text-sm"><Link className="font-semibold text-[var(--color-accent)]" to={loginPath}>Return to sign in</Link></p>
    </section>
  </main>;
}
