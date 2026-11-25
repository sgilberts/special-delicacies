import { useCallback, useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import { Check, Paperclip, Search, Send, UserRound } from "lucide-react";
import MessageReceipt from "@/components/chat/MessageReceipt";
import api, { cachedGet, invalidateCachedGets } from "@/lib/api";
import echo from "@/lib/echo";
import { useAuth } from "@/app/providers/useAuth";
import type { ApiResponse, PaginatedResponse } from "@/types/api";

interface Conversation {
  id: number;
  public_id: string;
  visitor_name: string;
  visitor_email: string;
  status: "open" | "closed";
  assigned_to: number | null;
  assigned_at: string | null;
  assignee: { id: number; name: string } | null;
  unread_count: number;
  last_message_at: string | null;
}
interface Message {
  id: number;
  client_message_id: string | null;
  author_kind: "visitor" | "staff";
  author_name: string | null;
  message_type: string;
  body: string | null;
  deleted: boolean;
  attachment_name: string | null;
  attachment_url: string | null;
  thumbnail_url: string | null;
  created_at: string;
  sent_at: string;
  delivered_at: string | null;
  read_at: string | null;
}
interface Staff { id: number; name: string }

export default function AdminChatPage() {
  const { user } = useAuth();
  const [rows, setRows] = useState<Conversation[]>([]);
  const [selected, setSelected] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [thumbnailUrls, setThumbnailUrls] = useState<Record<number, string>>({});
  const [staff, setStaff] = useState<Staff[]>([]);
  const [query, setQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState<"open" | "closed" | "all">("open");
  const [body, setBody] = useState("");
  const [attachment, setAttachment] = useState<File | null>(null);
  const [typing, setTyping] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [draftAssignee, setDraftAssignee] = useState("");
  const [presence, setPresence] = useState<"online" | "unavailable" | "offline">("online");
  const messageListRef = useRef<HTMLDivElement>(null);
  const messageKeyRef = useRef<string | null>(null);
  const deliveredAckedRef = useRef(new Set<number>());
  const readAckedRef = useRef(new Set<number>());
  const selectedId = selected?.id;

  const loadInbox = useCallback(async () => {
    try {
      const params = new URLSearchParams({ per_page: "50" });
      if (statusFilter !== "all") params.set("status", statusFilter);
      if (query.trim()) params.set("search", query.trim());
      const response = await cachedGet<PaginatedResponse<Conversation>>(`/admin/chat/conversations?${params}`, 4_000);
      setRows(response.data.data);
    } catch { setError("Chat inbox could not be loaded. Check your chat permissions."); }
  }, [statusFilter, query]);

  const loadMessages = useCallback(async (id: number) => {
    try {
      const response = await api.get<ApiResponse<{ conversation: Conversation; messages: Message[] }>>(`/admin/chat/conversations/${id}`);
      const fetched = response.data.data.messages;
      setSelected(response.data.data.conversation);
      setMessages(fetched);
      setRows((current) => current.map((row) => row.id === id ? { ...row, unread_count: 0 } : row));
      fetched.filter((message) => message.author_kind === "visitor" && message.delivered_at).forEach((message) => deliveredAckedRef.current.add(message.id));
      fetched.filter((message) => message.author_kind === "visitor" && message.read_at).forEach((message) => readAckedRef.current.add(message.id));
      const pendingDelivery = fetched.filter((message) => message.author_kind === "visitor" && !message.delivered_at && !deliveredAckedRef.current.has(message.id)).map((message) => message.id);
      if (pendingDelivery.length) {
        pendingDelivery.forEach((messageId) => deliveredAckedRef.current.add(messageId));
        void api.post(`/admin/chat/conversations/${id}/receipts`, { message_ids: pendingDelivery, status: "delivered" })
          .catch(() => pendingDelivery.forEach((messageId) => deliveredAckedRef.current.delete(messageId)));
      }
    } catch { setError("This conversation is not available to your account."); }
  }, []);

  useEffect(() => { messageListRef.current?.scrollTo({ top: messageListRef.current.scrollHeight }); }, [messages.length, selectedId]);

  useEffect(() => {
    const root = messageListRef.current;
    if (!selectedId || !root || typeof IntersectionObserver === "undefined") return;
    const observer = new IntersectionObserver((entries) => {
      if (document.visibilityState !== "visible") return;
      const ids = entries.filter((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.6)
        .map((entry) => Number((entry.target as HTMLElement).dataset.chatMessageId))
        .filter((id) => {
          const message = messages.find((item) => item.id === id);
          return message?.author_kind === "visitor" && !message.read_at && !readAckedRef.current.has(id);
        });
      if (!ids.length) return;
      ids.forEach((messageId) => readAckedRef.current.add(messageId));
      void api.post(`/admin/chat/conversations/${selectedId}/receipts`, { message_ids: ids, status: "read" })
        .catch(() => ids.forEach((messageId) => readAckedRef.current.delete(messageId)));
    }, { root, threshold: 0.6 });
    root.querySelectorAll<HTMLElement>("[data-chat-message-id]").forEach((element) => observer.observe(element));
    const onVisibility = () => {
      if (document.visibilityState === "visible") root.querySelectorAll<HTMLElement>("[data-chat-message-id]").forEach((element) => observer.observe(element));
    };
    document.addEventListener("visibilitychange", onVisibility);
    return () => { document.removeEventListener("visibilitychange", onVisibility); observer.disconnect(); };
  }, [messages, selectedId]);

  useEffect(() => { document.title = "Live Chat Inbox | Special Delicacies"; const initial = window.setTimeout(() => void loadInbox(), 0); const timer = window.setInterval(() => void loadInbox(), 8_000); return () => { window.clearTimeout(initial); window.clearInterval(timer); }; }, [loadInbox]);

  useEffect(() => {
    messages.filter((message) => message.message_type === "image" && message.thumbnail_url && !thumbnailUrls[message.id]).forEach((message) => {
      void api.get(message.thumbnail_url!, { responseType: "blob" }).then((response) => setThumbnailUrls((current) => current[message.id] ? current : { ...current, [message.id]: URL.createObjectURL(response.data) })).catch(() => undefined);
    });
  }, [messages, thumbnailUrls]);

  useEffect(() => {
    if (!user?.permissions.some((permission) => ["chat.assign", "chat.reassign"].includes(permission))) return;
    void cachedGet<ApiResponse<Staff[]>>("/admin/chat/eligible-staff", 15_000).then((response) => setStaff(response.data.data)).catch(() => undefined);
  }, [user?.id, user?.permissions]);

  useEffect(() => {
    if (!user?.id) return;
    const heartbeat = () => void api.post("/admin/chat/presence/heartbeat", { status: presence }).catch(() => undefined);
    heartbeat();
    const presenceTimer = window.setInterval(heartbeat, 15_000);
    const channelName = `chat.staff.${user.id}`;
    const channel = echo.private(channelName);
    const refresh = () => { invalidateCachedGets(); void loadInbox(); if (selectedId) void loadMessages(selectedId); };
    channel.listen(".chat.conversation.assigned", refresh).listen(".chat.conversation.status.updated", refresh).listen(".chat.message.sent", refresh);
    return () => { window.clearInterval(presenceTimer); void api.post("/admin/chat/presence/heartbeat", { status: "offline" }).catch(() => undefined); echo.leave(channelName); };
  }, [user?.id, selectedId, loadInbox, loadMessages, presence]);

  useEffect(() => {
    if (!selectedId) return;
    const name = `chat.conversation.${selectedId}`;
    const channel = echo.private(name);
    const refresh = () => void loadMessages(selectedId);
    channel.listen(".chat.message.sent", refresh).listen(".chat.receipt.updated", refresh).listen(".chat.conversation.status.updated", refresh).listen(".chat.typing", (payload: { sender?: string }) => {
      if (payload.sender === "visitor") { setTyping(true); window.setTimeout(() => setTyping(false), 1800); }
    });
    const timer = window.setInterval(refresh, 20_000);
    return () => { window.clearInterval(timer); echo.leave(name); };
  }, [selectedId, loadMessages]);

  const visibleRows = rows;

  const send = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selected || (!body.trim() && !attachment)) return;
    setLoading(true); setError("");
    try {
      const payload = new FormData();
      if (body.trim()) payload.append("body", body.trim());
      if (attachment) payload.append("attachment", attachment);
      messageKeyRef.current ||= crypto.randomUUID();
      payload.append("client_message_id", messageKeyRef.current);
      await api.post(`/admin/chat/conversations/${selected.id}/messages`, payload);
      messageKeyRef.current = null;
      setBody(""); setAttachment(null); await loadMessages(selected.id);
    } catch (cause) { setError((cause as { response?: { data?: { message?: string } } })?.response?.data?.message || "Message could not be sent."); }
    finally { setLoading(false); }
  };

  const closeConversation = async () => {
    if (!selected) return;
    const action = selected.status === "open" ? "close" : "reopen";
    try { await api.patch(`/admin/chat/conversations/${selected.id}/${action}`); await loadMessages(selected.id); await loadInbox(); }
    catch { setError("Conversation status could not be updated."); }
  };

  const assignConversation = async () => {
    if (!selected || !draftAssignee) return;
    try {
      await api.patch(`/admin/chat/conversations/${selected.id}/assignment`, { user_id: Number(draftAssignee) });
      setDraftAssignee(""); await loadMessages(selected.id); await loadInbox();
    } catch { setError("That assignment could not be saved."); }
  };

  const downloadAttachment = async (message: Message) => {
    if (!message.attachment_url) return;
    try {
      const response = await api.get(message.attachment_url, { responseType: "blob" });
      const url = URL.createObjectURL(response.data);
      const link = document.createElement("a"); link.href = url; link.download = message.attachment_name || "chat-attachment"; link.click(); URL.revokeObjectURL(url);
    } catch { setError("Attachment could not be downloaded."); }
  };

  const canAssign = user?.permissions.some((permission) => ["chat.assign", "chat.reassign"].includes(permission));
  const canClose = user?.permissions.includes("chat.close");

  return <main className="mx-auto max-w-[1500px] p-3 text-[var(--color-text)] sm:p-6"><header className="mb-4 flex flex-wrap items-end justify-between gap-3"><div><p className="text-xs font-bold uppercase tracking-[.16em] text-[var(--color-accent)]">Customer Support</p><h1 className="text-2xl font-black">Live chat inbox</h1></div>{user?.permissions.includes("chat.receive") && <label className="text-xs font-semibold text-[var(--color-text-secondary)]">Availability<select aria-label="Chat availability" value={presence} onChange={(event) => setPresence(event.target.value as "online" | "unavailable" | "offline")} className="ml-2 min-h-11 rounded-lg border border-[var(--color-border)] bg-[var(--color-input-background)] px-2 text-sm text-[var(--color-text)]"><option value="online">Online</option><option value="unavailable">Unavailable</option><option value="offline">Offline</option></select></label>}</header>{error && <p role="alert" className="mb-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-secondary)] p-3 text-sm text-[var(--color-danger)]">{error}</p>}
    <div className="grid h-[calc(100dvh-12rem)] min-h-[28rem] max-h-[calc(100dvh-8rem)] min-w-0 overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] lg:h-[min(76vh,780px)] lg:grid-cols-[320px_1fr]">
      <aside className={`${selected ? "hidden lg:flex" : "flex"} min-h-0 min-w-0 flex-col border-r border-[var(--color-border)]`}><div className="space-y-2 border-b border-[var(--color-border)] p-3"><label className="relative block"><Search size={15} className="absolute left-3 top-3 text-[var(--color-text-secondary)]" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search conversations" className="min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] pl-9 pr-3 text-sm" /></label><select aria-label="Filter conversations by status" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value as "open" | "closed" | "all")} className="min-h-11 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-background)] px-2 text-xs"><option value="open">Open conversations</option><option value="closed">Closed conversations</option><option value="all">All conversations</option></select></div><div className="min-h-0 flex-1 divide-y divide-[var(--color-border)] overflow-y-auto">{visibleRows.map((row) => <button key={row.id} onClick={() => void loadMessages(row.id)} className={`w-full p-3 text-left hover:bg-[var(--color-surface-secondary)] ${selected?.id === row.id ? "bg-[var(--color-surface-secondary)]" : ""}`}><div className="flex items-center justify-between gap-2"><span className="truncate text-sm font-bold">{row.visitor_name}</span>{row.unread_count > 0 && <span className="rounded-full bg-[var(--color-primary)] px-2 py-0.5 text-[10px] font-bold text-[var(--color-background)]">{row.unread_count}</span>}</div><p className="mt-1 truncate text-xs text-[var(--color-text-secondary)]">{row.visitor_email}</p><p className="mt-1 truncate text-[10px] text-[var(--color-text-secondary)]">{row.assignee?.name ? `Assigned to ${row.assignee.name}` : "Waiting for an agent"} · {row.status}{row.assigned_at ? ` · ${new Date(row.assigned_at).toLocaleString()}` : ""}</p></button>)}{!visibleRows.length && <p className="p-5 text-sm text-[var(--color-text-secondary)]">No matching conversations.</p>}</div></aside>
      <section className={`${selected ? "flex" : "hidden lg:flex"} min-h-0 min-w-0 flex-col`}>
        {!selected ? <div className="m-auto text-center text-sm text-[var(--color-text-secondary)]"><UserRound size={34} className="mx-auto mb-2 opacity-40" />Choose a conversation to reply.</div> : <>
          <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--color-border)] p-3 sm:p-4"><div className="min-w-0"><button onClick={() => setSelected(null)} className="mb-1 min-h-11 text-xs font-semibold text-[var(--color-accent)] lg:hidden">← Inbox</button><h2 className="font-bold">{selected.visitor_name}</h2><p className="break-all text-xs text-[var(--color-text-secondary)]">{selected.visitor_email} · {selected.assignee?.name ? `Assigned to ${selected.assignee.name}` : "Waiting for an agent"}{selected.assigned_at ? ` · ${new Date(selected.assigned_at).toLocaleString()}` : ""}</p></div><div className="flex flex-wrap gap-2">{canAssign && <><select aria-label="Assign conversation" value={draftAssignee} onChange={(event) => setDraftAssignee(event.target.value)} className="min-h-11 max-w-36 rounded-lg border border-[var(--color-border)] bg-[var(--color-input-background)] px-2 text-xs"><option value="">Assign staff…</option>{staff.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}</select><button onClick={() => void assignConversation()} disabled={!draftAssignee} className="grid h-11 w-11 place-items-center rounded-lg border border-[var(--color-border)] disabled:opacity-40" aria-label="Save assignment"><Check size={15} /></button></>}{canClose && <button onClick={() => void closeConversation()} className="min-h-11 rounded-lg border border-[var(--color-border)] px-3 text-xs font-bold">{selected.status === "open" ? "Close" : "Reopen"}</button>}</div></header>
          <div ref={messageListRef} className="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain bg-[var(--color-background)] p-3 sm:p-5" aria-live="polite">{messages.map((message) => <article key={message.id} data-chat-message-id={message.id} className={`max-w-[85%] break-words rounded-2xl border border-[var(--color-border)] p-3 ${message.author_kind === "staff" ? "ml-auto bg-[var(--color-surface)]" : "bg-[var(--color-surface-secondary)]"}`}><p className="mb-1 text-[10px] font-bold uppercase text-[var(--color-text-secondary)]">{message.author_name || message.author_kind}</p>{message.deleted ? <p className="italic text-sm text-[var(--color-text-secondary)]">This message was deleted.</p> : <>{message.body && <p className="whitespace-pre-wrap break-words text-sm">{message.body}</p>}{message.attachment_name && <div className="mt-2">{thumbnailUrls[message.id] && <img src={thumbnailUrls[message.id]} alt={`Preview: ${message.attachment_name}`} className="mb-2 max-h-48 max-w-full rounded-lg object-contain" />}<button onClick={() => void downloadAttachment(message)} className="min-h-10 text-left text-sm font-semibold text-[var(--color-accent)] underline">Download {message.attachment_name}</button></div>}</>}<time className="mt-2 flex items-center justify-end gap-1 text-[10px] text-[var(--color-text-secondary)]"><span>{new Date(message.sent_at).toLocaleString()}</span>{message.author_kind === "staff" && <MessageReceipt deliveredAt={message.delivered_at} readAt={message.read_at} />}</time></article>)}{typing && <p className="text-xs text-[var(--color-text-secondary)]">Visitor is typing…</p>}</div>
          {selected.status === "open" && <form onSubmit={send} className="flex shrink-0 items-end gap-2 border-t border-[var(--color-border)] p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"><label aria-label="Attach chat file" className="grid h-11 w-11 shrink-0 cursor-pointer place-items-center rounded-xl border border-[var(--color-border)] text-[var(--color-text-secondary)]"><Paperclip size={17} /><input type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,application/pdf,audio/*" onChange={(event) => { messageKeyRef.current = null; setError(""); setAttachment(event.target.files?.[0] ?? null); }} className="sr-only" /></label><div className="min-w-0 flex-1"><textarea value={body} onChange={(event) => { if (messageKeyRef.current) messageKeyRef.current = null; setError(""); setBody(event.target.value); }} maxLength={5000} rows={2} placeholder={attachment?.name || "Write a reply…"} className="min-h-11 w-full resize-none rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] p-3 text-sm" /></div><button aria-label="Send reply" disabled={loading || (!body.trim() && !attachment)} className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--color-accent)] disabled:opacity-50"><Send size={17} /></button></form>}
        </>}
      </section>
    </div>
  </main>;
}
