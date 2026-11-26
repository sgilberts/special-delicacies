import { useEffect, useState } from "react";
import api from "@/lib/api";
import customerApi from "@/lib/customerApi";

type Props = {
  accountType: "customer" | "staff";
  email?: string | null;
  phone?: string | null;
  emailVerifiedAt?: string | null;
  phoneVerifiedAt?: string | null;
  onVerified?: () => void;
};

export default function AccountSecurityPanel(props: Props) {
  const [channel, setChannel] = useState<"email" | "phone">("phone");
  const [challengeId, setChallengeId] = useState("");
  const [code, setCode] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [verified, setVerified] = useState({ email: Boolean(props.emailVerifiedAt), phone: Boolean(props.phoneVerifiedAt) });
  const isCustomer = props.accountType === "customer";
  const client = isCustomer ? customerApi : api;
  const base = isCustomer ? "/customer/verification" : "/auth/verification";

  useEffect(() => setVerified({ email: Boolean(props.emailVerifiedAt), phone: Boolean(props.phoneVerifiedAt) }), [props.emailVerifiedAt, props.phoneVerifiedAt]);

  async function requestCode() {
    setBusy(true); setError(""); setMessage("");
    try {
      const url = isCustomer ? `${base}/${channel}/request` : `${base}/${channel}/request`;
      const response = await client.post<{ data?: { challenge_id?: string }; message?: string }>(url);
      setChallengeId(response.data.data?.challenge_id ?? "");
      setMessage("A one-time code was requested. It expires in 10 minutes.");
    } catch (cause) {
      const data = (cause as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "We could not send a verification code. Check your contact details or try later.");
    } finally { setBusy(false); }
  }

  async function verifyCode() {
    setBusy(true); setError(""); setMessage("");
    try {
      await client.post(`${base}/${channel}/verify`, { challenge_id: challengeId, code });
      setVerified(previous => ({ ...previous, [channel]: true }));
      setChallengeId(""); setCode(""); setMessage(`${channel === "phone" ? "Phone number" : "Email address"} verified.`);
      props.onVerified?.();
    } catch (cause) {
      const data = (cause as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "That code is invalid or expired. Request a new one and try again.");
    } finally { setBusy(false); }
  }

  const contact = channel === "phone" ? props.phone : props.email;
  const isVerified = verified[channel];
  return <section className="rounded-2xl border bg-[var(--color-surface)] p-5" style={{ borderColor: "var(--color-border)" }}>
    <h2 className="text-lg font-bold">Contact verification</h2>
    <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Verification codes are single-use. Codes are never shown in the application.</p>
    <div className="mt-4 flex flex-wrap gap-2">
      {(["phone", "email"] as const).map(value => <button key={value} type="button" aria-pressed={channel === value} onClick={() => { setChannel(value); setChallengeId(""); setCode(""); setError(""); }} className={`min-h-10 rounded-lg border px-3 text-sm font-semibold ${channel === value ? "bg-[var(--color-accent)] text-white" : ""}`} style={{ borderColor: "var(--color-border)" }}>{value === "phone" ? "Phone" : "Email"}</button>)}
    </div>
    <p className="mt-3 text-sm">{channel === "phone" ? "Telephone" : "Email"}: <span className="font-semibold">{contact || "Not provided"}</span> · {isVerified ? <span className="text-[var(--color-success)]">Verified</span> : <span className="text-[var(--color-text-secondary)]">Not verified</span>}</p>
    {message && <p role="status" className="mt-3 text-sm text-[var(--color-success)]">{message}</p>}{error && <p role="alert" className="mt-3 text-sm text-[var(--color-danger)]">{error}</p>}
    {!isVerified && !challengeId && <button type="button" disabled={busy || !contact} onClick={() => void requestCode()} className="mt-3 min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-semibold text-white disabled:opacity-50">{busy ? "Requesting…" : "Send verification code"}</button>}
    {!isVerified && challengeId && <div className="mt-3 flex flex-wrap gap-2"><label className="sr-only" htmlFor="account-verification-code">Verification code</label><input id="account-verification-code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={event => setCode(event.target.value.replace(/\D/g, ""))} placeholder="6-digit code" className="min-h-10 min-w-36 rounded-lg border bg-[var(--color-input-background)] px-3" style={{ borderColor: "var(--color-border)" }} /><button type="button" disabled={busy || code.length !== 6} onClick={() => void verifyCode()} className="min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-semibold text-white disabled:opacity-50">{busy ? "Verifying…" : "Verify"}</button></div>}
  </section>;
}
