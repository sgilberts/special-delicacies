import { useRef, useState, type FormEvent } from "react";
import { Camera, Check, KeyRound, Save, UserRound } from "lucide-react";
import { useAuth } from "@/app/providers/useAuth";
import api from "@/lib/api";
import PasswordInput from "@/components/ui/PasswordInput";
import { assetUrl } from "@/lib/assetUrl";
import { useToast } from "@/components/ui/useToast";
import AccountSecurityPanel from "@/components/account/AccountSecurityPanel";

export default function AdminProfilePage() {
  const { user, refreshUser } = useAuth();
  const { success, error } = useToast();
  const photoRef = useRef<HTMLInputElement>(null);
  const [name, setName] = useState(user?.name || "");
  const [email, setEmail] = useState(user?.email || "");
  const [phone, setPhone] = useState(user?.phone || "");
  const [photo, setPhoto] = useState<File | null>(null);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState("");
  const [saving, setSaving] = useState(false);

  const saveProfile = async (event: FormEvent) => {
    event.preventDefault();
    if (newPassword && newPassword !== newPasswordConfirmation) {
      error("New password and confirmation do not match.");
      return;
    }
    const payload = new FormData();
    payload.append("name", name.trim());
    payload.append("email", email.trim());
    payload.append("phone", phone.trim());
    if (photo) payload.append("profile_photo", photo);
    if (newPassword) {
      payload.append("current_password", currentPassword);
      payload.append("new_password", newPassword);
      payload.append("new_password_confirmation", newPasswordConfirmation);
    }
    setSaving(true);
    try {
      await api.post("/auth/profile", payload, { headers: { "Content-Type": "multipart/form-data" } });
      await refreshUser();
      setCurrentPassword(""); setNewPassword(""); setNewPasswordConfirmation(""); setPhoto(null);
      if (photoRef.current) photoRef.current.value = "";
      success("Your profile has been updated.");
    } catch (err: any) {
      error(err.response?.data?.message || "Could not update your profile.");
    } finally { setSaving(false); }
  };

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header><p className="text-xs font-bold uppercase tracking-[.18em] text-[var(--color-accent)]">Account settings</p><h1 className="mt-1 text-2xl font-black text-[var(--color-text)]">My Profile</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Manage your staff details, photo, and password.</p></header>
      <form onSubmit={saveProfile} className="space-y-6">
        <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 sm:p-7">
          <div className="mb-6 flex items-center gap-3"><UserRound className="text-[var(--color-accent)]" /><div><h2 className="font-bold">Personal details</h2><p className="text-xs text-[var(--color-text-secondary)]">Your name and contact information</p></div></div>
          <div className="mb-6 flex items-center gap-4">
            <div className="grid h-20 w-20 place-items-center overflow-hidden rounded-full border border-[var(--color-border)] bg-[var(--color-surface-secondary)] text-[var(--color-accent)]">
              {photo ? <img src={URL.createObjectURL(photo)} className="h-full w-full object-cover" alt="Selected profile" /> : user?.profile_photo_url ? <img src={assetUrl(user.profile_photo_url) ?? undefined} className="h-full w-full object-cover" alt="Profile" /> : <UserRound size={30} />}
            </div>
            <div><input ref={photoRef} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(event) => setPhoto(event.target.files?.[0] || null)} /><button type="button" onClick={() => photoRef.current?.click()} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-sm font-semibold hover:bg-[var(--color-surface-secondary)]"><Camera size={16} /> Choose photo</button><p className="mt-1 text-xs text-[var(--color-text-secondary)]">JPG, PNG or WebP, up to 5 MB</p></div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-xs font-bold text-[var(--color-text-secondary)]">Full name<input required maxLength={120} value={name} onChange={(e) => setName(e.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" /></label>
            <label className="text-xs font-bold text-[var(--color-text-secondary)]">Email address<input required type="email" value={email} onChange={(e) => setEmail(e.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" /></label>
            <label className="text-xs font-bold text-[var(--color-text-secondary)] sm:col-span-2">Phone number<input value={phone} onChange={(e) => setPhone(e.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" /></label>
          </div>
        </section>
        <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 sm:p-7">
          <div className="mb-5 flex items-center gap-3"><KeyRound className="text-[var(--color-accent)]" /><div><h2 className="font-bold">Change password</h2><p className="text-xs text-[var(--color-text-secondary)]">Leave blank to keep your current password.</p></div></div>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-xs font-bold text-[var(--color-text-secondary)] sm:col-span-2">Current password<PasswordInput autoComplete="current-password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" /></label>
            <label className="text-xs font-bold text-[var(--color-text-secondary)]">New password<PasswordInput autoComplete="new-password" minLength={8} value={newPassword} onChange={(e) => setNewPassword(e.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" /></label>
            <label className="text-xs font-bold text-[var(--color-text-secondary)]">Confirm new password<PasswordInput autoComplete="new-password" minLength={8} value={newPasswordConfirmation} onChange={(e) => setNewPasswordConfirmation(e.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm text-[var(--color-text)]" /></label>
          </div>
        </section>
        <div className="flex justify-end"><button disabled={saving} className="btn-accent inline-flex min-h-11 items-center gap-2 rounded-xl px-5 font-bold disabled:opacity-60">{saving ? <Check className="animate-pulse" size={17} /> : <Save size={17} />}{saving ? "Saving..." : "Save profile"}</button></div>
      </form>
      {user && <AccountSecurityPanel accountType="staff" email={user.email} phone={user.phone} emailVerifiedAt={user.email_verified_at} phoneVerifiedAt={user.phone_verified_at} onVerified={() => void refreshUser()} />}
    </div>
  );
}
