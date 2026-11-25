import { useEffect, useState } from "react";
import api from "@/lib/api";
import type { ApiResponse } from "@/types/api";

interface ChatSettings {
  widget_enabled: boolean;
  accepting_conversations: boolean;
  eligible_role_ids: number[];
  automatic_assignment: boolean;
  max_active_per_agent: number;
  unavailable_message: string;
  fallback_behavior: "queue";
  roles: Array<{ id: number; name: string }>;
}

export default function AdminChatSettingsPage() {
  const [settings, setSettings] = useState<ChatSettings | null>(null);
  const [error, setError] = useState("");
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    document.title = "Chat Availability | Special Delicacies";
    const timer = window.setTimeout(() => {
      void api.get<ApiResponse<ChatSettings>>("/admin/chat/settings").then((result) => setSettings(result.data.data)).catch(() => setError("You do not have permission to manage chat settings."));
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  const update = <K extends keyof ChatSettings>(key: K, value: ChatSettings[K]) => setSettings((current) => current ? { ...current, [key]: value } : current);
  const save = async () => {
    if (!settings) return;
    setBusy(true); setError(""); setSaved(false);
    try {
      const { roles: _roles, ...payload } = settings;
      await api.put("/admin/chat/settings", payload);
      setSaved(true);
    } catch (cause) {
      setError((cause as { response?: { data?: { message?: string } } })?.response?.data?.message || "Chat settings could not be saved.");
    } finally { setBusy(false); }
  };

  if (!settings && !error) return <main className="p-6 text-sm text-[var(--color-text-secondary)]">Loading chat settings…</main>;
  if (!settings) return <main className="p-6 text-sm text-[var(--color-danger)]" role="alert">{error}</main>;
  const toggle = (label: string, value: boolean, setter: (next: boolean) => void, detail: string) => <label className="flex items-start gap-3 rounded-xl border border-[var(--color-border)] p-4"><input type="checkbox" checked={value} onChange={(event) => setter(event.target.checked)} className="mt-1 accent-[var(--color-accent)]" /><span><span className="block text-sm font-bold">{label}</span><span className="mt-1 block text-xs text-[var(--color-text-secondary)]">{detail}</span></span></label>;

  return <main className="mx-auto max-w-3xl p-4 text-[var(--color-text)] sm:p-6">
    <header className="mb-6"><p className="text-xs font-bold uppercase tracking-[.16em] text-[var(--color-accent)]">Customer Support</p><h1 className="text-2xl font-black">Chat availability</h1><p className="mt-2 text-sm text-[var(--color-text-secondary)]">Changes apply immediately. Existing conversations remain available when new chats are paused.</p></header>
    {error && <p role="alert" className="mb-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-secondary)] p-3 text-sm text-[var(--color-danger)]">{error}</p>}{saved && <p role="status" className="mb-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-secondary)] p-3 text-sm text-[var(--color-success)]">Settings saved.</p>}
    <div className="space-y-3">
      {toggle("Show the public chat widget", settings.widget_enabled, (value) => update("widget_enabled", value), "Turning this off hides the widget for visitors. Staff can still access existing conversations.")}
      {toggle("Accept new conversations", settings.accepting_conversations, (value) => update("accepting_conversations", value), "When paused, the start-chat endpoint rejects new conversations with the message below.")}
      {toggle("Assign conversations automatically", settings.automatic_assignment, (value) => update("automatic_assignment", value), "Unassigned conversations remain in the waiting inbox until a manager assigns them or automatic assignment is enabled.")}
      <fieldset className="rounded-xl border border-[var(--color-border)] p-4"><legend className="px-1 text-sm font-bold">Eligible staff roles</legend><p className="mb-3 text-xs text-[var(--color-text-secondary)]">Only roles that already have the chat receive permission appear here. Leave all unchecked to allow every permitted role.</p><div className="grid gap-2 sm:grid-cols-2">{settings.roles.map((role) => <label key={role.id} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.eligible_role_ids.includes(role.id)} onChange={(event) => update("eligible_role_ids", event.target.checked ? [...settings.eligible_role_ids, role.id] : settings.eligible_role_ids.filter((id) => id !== role.id))} />{role.name}</label>)}</div></fieldset>
      <label className="block rounded-xl border border-[var(--color-border)] p-4"><span className="text-sm font-bold">Maximum active conversations per agent</span><input type="number" min={1} max={100} value={settings.max_active_per_agent} onChange={(event) => update("max_active_per_agent", Math.min(100, Math.max(1, Number(event.target.value) || 1)))} className="mt-2 block min-h-11 w-32 rounded-lg border border-[var(--color-border)] bg-[var(--color-input-background)] px-3" /></label>
      <label className="block rounded-xl border border-[var(--color-border)] p-4"><span className="text-sm font-bold">Unavailable message</span><textarea maxLength={500} value={settings.unavailable_message} onChange={(event) => update("unavailable_message", event.target.value)} rows={3} className="mt-2 block min-h-24 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-background)] p-3 text-sm" /></label>
      <p className="rounded-xl bg-[var(--color-background)] p-4 text-xs text-[var(--color-text-secondary)]">Fallback: conversations are kept in the database waiting queue. Agent presence refreshes every 15 seconds and expires after 45 seconds. Manual accept or decline is not part of this inbox workflow.</p>
    </div>
    <button onClick={() => void save()} disabled={busy} className="mt-5 min-h-11 rounded-xl bg-[var(--color-accent)] px-5 text-sm font-bold text-white disabled:opacity-50">{busy ? "Saving…" : "Save chat settings"}</button>
  </main>;
}
