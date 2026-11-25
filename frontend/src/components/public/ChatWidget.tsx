import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { CSSProperties, FormEvent } from "react";
import { MessageCircle, Paperclip, Send, Square, X } from "lucide-react";
import MessageReceipt from "@/components/chat/MessageReceipt";
import api from "@/lib/api";
import echo from "@/lib/echo";
import type { ApiResponse } from "@/types/api";

interface ChatMessage {
  id: number;
  client_message_id: string | null;
  author_kind: "visitor" | "staff" | "system";
  message_type: "text" | "image" | "video" | "document" | "voice";
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
interface ChatConversation { public_id: string; visitor_channel: string; status: "open" | "closed"; assigned: boolean }
interface ChatPayload { conversation: ChatConversation; messages: ChatMessage[] }
interface ChatAvailability { enabled: boolean; accepting: boolean; message: string }

const TOKEN_KEY = "special_delicacies_chat_visitor_token";
const CONVERSATION_KEY = "special_delicacies_chat_conversation";

function visitorToken(): string {
  let token = localStorage.getItem(TOKEN_KEY);
  if (!token) {
    const bytes = crypto.getRandomValues(new Uint8Array(32));
    token = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
    localStorage.setItem(TOKEN_KEY, token);
  }
  return token;
}

export default function ChatWidget() {
  const [open, setOpen] = useState(false);
  const [conversation, setConversation] = useState<ChatConversation | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [body, setBody] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [typing, setTyping] = useState(false);
  const [recording, setRecording] = useState(false);
  const [voice, setVoice] = useState<Blob | null>(null);
  const [mediaUrls, setMediaUrls] = useState<Record<number, string>>({});
  const [visualViewport, setVisualViewport] = useState<{ height: number; offsetTop: number } | null>(null);
  const [availability, setAvailability] = useState<ChatAvailability>({ enabled: true, accepting: true, message: "Our team is away right now. Leave a message and we will get back to you." });
  const listRef = useRef<HTMLDivElement>(null);
  const recorderRef = useRef<MediaRecorder | null>(null);
  const messageKeyRef = useRef<string | null>(null);
  const deliveredAckedRef = useRef(new Set<number>());
  const readAckedRef = useRef(new Set<number>());
  const lastTypingAt = useRef(0);
  const token = useMemo(() => visitorToken(), []);
  const voiceUrl = useMemo(() => voice ? URL.createObjectURL(voice) : null, [voice]);

  useEffect(() => () => { if (voiceUrl) URL.revokeObjectURL(voiceUrl); }, [voiceUrl]);

  useEffect(() => {
    const viewport = window.visualViewport;
    const syncViewport = () => setVisualViewport({
      height: viewport?.height ?? window.innerHeight,
      offsetTop: viewport?.offsetTop ?? 0,
    });
    syncViewport();
    window.addEventListener("resize", syncViewport);
    viewport?.addEventListener("resize", syncViewport);
    viewport?.addEventListener("scroll", syncViewport);
    return () => {
      window.removeEventListener("resize", syncViewport);
      viewport?.removeEventListener("resize", syncViewport);
      viewport?.removeEventListener("scroll", syncViewport);
    };
  }, []);

  useEffect(() => {
    let active = true;
    const refreshAvailability = () => void api.get<ApiResponse<ChatAvailability>>("/chat/availability").then((response) => { if (active) setAvailability(response.data.data); }).catch(() => undefined);
    refreshAvailability();
    const timer = window.setInterval(refreshAvailability, 30_000);
    return () => { active = false; window.clearInterval(timer); };
  }, []);

  const refresh = useCallback(async (publicId: string) => {
    try {
      const response = await api.get<ApiResponse<ChatPayload>>(`/chat/conversations/${publicId}/messages`, { headers: { "X-Visitor-Token": token } });
      const fetched = response.data.data.messages;
      setConversation(response.data.data.conversation);
      setMessages(fetched);
      fetched.filter((message) => message.author_kind === "staff" && message.delivered_at).forEach((message) => deliveredAckedRef.current.add(message.id));
      fetched.filter((message) => message.author_kind === "staff" && message.read_at).forEach((message) => readAckedRef.current.add(message.id));
      const pendingDelivery = fetched.filter((message) => message.author_kind === "staff" && !message.delivered_at && !deliveredAckedRef.current.has(message.id)).map((message) => message.id);
      if (pendingDelivery.length) {
        pendingDelivery.forEach((id) => deliveredAckedRef.current.add(id));
        void api.post(`/chat/conversations/${publicId}/receipts`, { message_ids: pendingDelivery, status: "delivered" }, { headers: { "X-Visitor-Token": token } })
          .catch(() => pendingDelivery.forEach((id) => deliveredAckedRef.current.delete(id)));
      }
    } catch { setError("Chat history is temporarily unavailable. Please try again."); }
  }, [token]);

  useEffect(() => {
    if (!open) return;
    const timer = window.setTimeout(() => {
      const saved = localStorage.getItem(CONVERSATION_KEY);
      if (saved) void refresh(saved);
    }, 0);
    return () => window.clearTimeout(timer);
  }, [open, refresh]);

  useEffect(() => {
    const visitorChannel = conversation?.visitor_channel;
    const publicId = conversation?.public_id;
    if (!open || !visitorChannel || !publicId) return;
    const channel = echo.channel(visitorChannel);
    channel.listen(".chat.message.sent", () => void refresh(publicId));
    channel.listen(".chat.receipt.updated", () => void refresh(publicId));
    channel.listen(".chat.conversation.status.updated", () => void refresh(publicId));
    channel.listen(".chat.typing", (payload: { sender?: string }) => {
      if (payload.sender === "staff") { setTyping(true); window.setTimeout(() => setTyping(false), 1800); }
    });
    const timer = window.setInterval(() => void refresh(publicId), 20_000);
    return () => { window.clearInterval(timer); echo.leaveChannel(visitorChannel); };
  }, [open, conversation, refresh]);

  useEffect(() => { listRef.current?.scrollTo({ top: listRef.current.scrollHeight }); }, [messages.length, typing]);

  useEffect(() => {
    const root = listRef.current;
    const publicId = conversation?.public_id;
    if (!open || !root || !publicId || typeof IntersectionObserver === "undefined") return;
    const observer = new IntersectionObserver((entries) => {
      if (document.visibilityState !== "visible") return;
      const ids = entries.filter((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.6)
        .map((entry) => Number((entry.target as HTMLElement).dataset.chatMessageId))
        .filter((id) => {
          const message = messages.find((item) => item.id === id);
          return message?.author_kind === "staff" && !message.read_at && !readAckedRef.current.has(id);
        });
      if (!ids.length) return;
      ids.forEach((id) => readAckedRef.current.add(id));
      void api.post(`/chat/conversations/${publicId}/receipts`, { message_ids: ids, status: "read" }, { headers: { "X-Visitor-Token": token } })
        .catch(() => ids.forEach((id) => readAckedRef.current.delete(id)));
    }, { root, threshold: 0.6 });
    root.querySelectorAll<HTMLElement>("[data-chat-message-id]").forEach((element) => observer.observe(element));
    const onVisibility = () => {
      if (document.visibilityState === "visible") root.querySelectorAll<HTMLElement>("[data-chat-message-id]").forEach((element) => observer.observe(element));
    };
    document.addEventListener("visibilitychange", onVisibility);
    return () => { document.removeEventListener("visibilitychange", onVisibility); observer.disconnect(); };
  }, [open, conversation?.public_id, messages, token]);

  useEffect(() => {
    let active = true;
    const pending = messages.filter((message) => message.message_type === "image" && message.thumbnail_url && !mediaUrls[message.id]);
    pending.forEach((message) => void api.get(message.thumbnail_url!, { responseType: "blob", headers: { "X-Visitor-Token": token } }).then((response) => {
      const url = URL.createObjectURL(response.data);
      if (active) setMediaUrls((urls) => ({ ...urls, [message.id]: url })); else URL.revokeObjectURL(url);
    }).catch(() => undefined));
    return () => { active = false; };
  }, [messages, mediaUrls, token]);

  const start = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoading(true); setError("");
    try {
      const response = await api.post<ApiResponse<ChatConversation>>("/chat/conversations", { visitor_token: token, name, email });
      const next = response.data.data;
      localStorage.setItem(CONVERSATION_KEY, next.public_id);
      setConversation(next);
      await refresh(next.public_id);
    } catch (cause) {
      const response = (cause as { response?: { status?: number; data?: { message?: string; errors?: { email?: string[] } } } }).response;
      if (response?.status === 409) setAvailability((current) => ({ ...current, accepting: false, message: response.data?.message || current.message }));
      setError(response?.data?.errors?.email?.[0] || response?.data?.message || "Could not start the chat. Please try again.");
    }
    finally { setLoading(false); }
  };

  const send = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!conversation || (!body.trim() && !file && !voice)) return;
    setLoading(true); setError("");
    const payload = new FormData();
    if (body.trim()) payload.append("body", body.trim());
    if (file) payload.append("attachment", file);
    if (voice) payload.append("attachment", new File([voice], `voice-${Date.now()}.webm`, { type: voice.type || "audio/webm" }));
    messageKeyRef.current ||= crypto.randomUUID();
    payload.append("client_message_id", messageKeyRef.current);
    try {
      await api.post(`/chat/conversations/${conversation.public_id}/messages`, payload, { headers: { "X-Visitor-Token": token } });
      messageKeyRef.current = null;
      setBody(""); setFile(null); setVoice(null);
      await refresh(conversation.public_id);
    } catch { setError("Your message was not sent. Check the attachment size and try again."); }
    finally { setLoading(false); }
  };

  const pingTyping = () => {
    if (!conversation || !body || Date.now() - lastTypingAt.current < 2500) return;
    lastTypingAt.current = Date.now();
    void api.post(`/chat/conversations/${conversation.public_id}/typing`, {}, { headers: { "X-Visitor-Token": token } }).catch(() => undefined);
  };

  const toggleRecording = async () => {
    if (recording) { recorderRef.current?.stop(); setRecording(false); return; }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const recorder = new MediaRecorder(stream);
      const chunks: BlobPart[] = [];
      recorder.ondataavailable = (event) => { if (event.data.size) chunks.push(event.data); };
      recorder.onstop = () => { messageKeyRef.current = null; setVoice(new Blob(chunks, { type: recorder.mimeType || "audio/webm" })); stream.getTracks().forEach((track) => track.stop()); };
      recorder.start(); recorderRef.current = recorder; setRecording(true);
    } catch { setError("Microphone access is unavailable in this browser."); }
  };

  const download = async (message: ChatMessage) => {
    if (!message.attachment_url) return;
    try {
      const response = await api.get(message.attachment_url, { responseType: "blob", headers: { "X-Visitor-Token": token } });
      const url = URL.createObjectURL(response.data);
      setMediaUrls((urls) => ({ ...urls, [message.id]: url }));
    } catch { setError("This attachment could not be loaded."); }
  };

  return <>
    {open && <section aria-label="Customer live chat" style={visualViewport ? { "--chat-viewport-height": `${visualViewport.height}px`, "--chat-viewport-offset-top": `${visualViewport.offsetTop}px` } as CSSProperties : undefined} className="chat-widget-panel fixed z-[80] flex flex-col overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)] shadow-2xl">
      <header className="flex items-center justify-between bg-[var(--color-primary)] px-4 py-3 text-[var(--color-background)]"><div><p className="font-bold">Chat with our team</p><p className="text-xs opacity-80">{conversation?.assigned ? "A team member is assigned" : "We usually reply soon"}</p></div><button aria-label="Close chat" onClick={() => setOpen(false)} className="rounded-lg p-2 hover:bg-white/10"><X size={18} /></button></header>
      {!conversation ? <form onSubmit={start} className="space-y-3 p-4"><p className="text-sm text-[var(--color-text-secondary)]">{availability.accepting ? "Share your name and email to start a conversation." : availability.message}</p><input required minLength={2} maxLength={120} value={name} onChange={(event) => setName(event.target.value)} placeholder="Your name" className="min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm" /><input required type="email" maxLength={254} value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Email address" className="min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-background)] px-3 text-sm" /><button disabled={loading || !availability.accepting} className="min-h-11 w-full rounded-xl bg-[var(--color-accent)] px-4 text-sm font-bold text-white disabled:opacity-50">{loading ? "Starting…" : "Start chat"}</button></form> : <>
        <div ref={listRef} className="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-3" aria-live="polite">{messages.map((message) => <div key={message.id} data-chat-message-id={message.id} className={`max-w-[88%] break-words rounded-2xl px-3 py-2 text-sm ${message.author_kind === "visitor" ? "ml-auto bg-[var(--color-accent)] text-[var(--color-on-accent)]" : "bg-[var(--color-surface-secondary)] text-[var(--color-text)]"}`}><p className="mb-1 text-[10px] font-bold uppercase opacity-70">{message.author_kind === "visitor" ? "You" : "Restaurant team"}</p>{message.deleted ? <p className="italic opacity-70">This message was deleted.</p> : <>{message.body && <p className="whitespace-pre-wrap break-words">{message.body}</p>}{message.attachment_name && <div className="mt-2">{mediaUrls[message.id] ? message.message_type === "image" ? <img src={mediaUrls[message.id]} alt={message.attachment_name} className="max-h-48 max-w-full rounded-lg object-contain" /> : message.message_type === "video" ? <video controls src={mediaUrls[message.id]} className="max-h-48 max-w-full rounded-lg" /> : message.message_type === "voice" ? <audio controls src={mediaUrls[message.id]} className="max-w-full" /> : <a href={mediaUrls[message.id]} download={message.attachment_name} className="underline">Download {message.attachment_name}</a> : <button onClick={() => void download(message)} className="underline">Load {message.attachment_name}</button>}</div>}</>}<time className="mt-1 flex items-center justify-end gap-1 text-[9px] opacity-70"><span>{new Date(message.sent_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}</span>{message.author_kind === "visitor" && <MessageReceipt deliveredAt={message.delivered_at} readAt={message.read_at} />}</time></div>)}{typing && <p className="text-xs text-[var(--color-text-secondary)]">Team member is typing…</p>}</div>
        {conversation.status === "closed" ? <div className="border-t border-[var(--color-border)] p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] text-center"><p className="mb-2 text-sm text-[var(--color-text-secondary)]">This conversation is closed.</p><button onClick={() => { setConversation(null); localStorage.removeItem(CONVERSATION_KEY); }} className="min-h-11 rounded-lg bg-[var(--color-accent)] px-3 text-xs font-bold">Start a new chat</button></div> : <form onSubmit={send} className="shrink-0 space-y-2 border-t border-[var(--color-border)] p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"><div className="flex items-end gap-2"><textarea value={body} onChange={(event) => { if (messageKeyRef.current) messageKeyRef.current = null; setError(""); setBody(event.target.value); pingTyping(); }} maxLength={5000} rows={2} placeholder="Write a message…" className="min-h-11 min-w-0 flex-1 resize-none rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] p-2.5 text-sm" /><button aria-label="Send message" disabled={loading || (!body.trim() && !file && !voice)} className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--color-accent)] disabled:opacity-50"><Send size={17} /></button></div><div className="flex flex-wrap items-center justify-between gap-2"><label className="inline-flex min-h-10 cursor-pointer items-center gap-1 text-xs font-semibold text-[var(--color-text-secondary)]"><Paperclip size={14} />{file?.name || "Attach file"}<input type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,application/pdf,audio/*" onChange={(event) => { messageKeyRef.current = null; setError(""); setFile(event.target.files?.[0] ?? null); }} className="sr-only" /></label><button type="button" onClick={() => void toggleRecording()} className="inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-[var(--color-text-secondary)]">{recording ? <><Square size={13} />Stop recording</> : "Record voice note"}</button></div>{voiceUrl && <audio controls src={voiceUrl} className="h-8 max-w-full" />}</form>}
      </>}
      {error && <p role="alert" className="border-t border-[var(--color-border)] bg-[var(--color-surface-secondary)] px-3 py-2 text-xs text-[var(--color-danger)]">{error}</p>}
    </section>}
    {availability.enabled && <button onClick={() => setOpen((value) => !value)} aria-label={open ? "Close customer chat" : "Open customer chat"} className="fixed bottom-20 right-4 z-[79] grid h-14 w-14 place-items-center rounded-full bg-[var(--color-accent)] text-white shadow-xl transition-transform hover:scale-105 sm:bottom-6 sm:right-6"><MessageCircle size={23} /></button>}
  </>;
}
