import React, { createContext, useCallback, useContext, useState } from "react";
import { AlertTriangle, X } from "lucide-react";

interface ConfirmOptions {
  title: string;
  message: string;
  confirmLabel?: string;
  tone?: "danger" | "primary";
}

interface PromptOptions {
  title: string;
  message: string;
  label: string;
  placeholder?: string;
  confirmLabel?: string;
  tone?: "danger" | "primary";
}

interface PendingConfirm extends ConfirmOptions {
  resolve: (confirmed: boolean) => void;
}

interface PendingPrompt extends PromptOptions {
  resolve: (value: string | null) => void;
}

const ConfirmContext = createContext<((options: ConfirmOptions) => Promise<boolean>) | null>(null);
const PromptContext = createContext<((options: PromptOptions) => Promise<string | null>) | null>(null);

export function useConfirm() {
  const confirm = useContext(ConfirmContext);
  if (!confirm) throw new Error("useConfirm must be used inside ConfirmProvider");
  return confirm;
}

export function usePrompt() {
  const prompt = useContext(PromptContext);
  if (!prompt) throw new Error("usePrompt must be used inside ConfirmProvider");
  return prompt;
}

export function ConfirmProvider({ children }: { children: React.ReactNode }) {
  const [pending, setPending] = useState<PendingConfirm | null>(null);
  const [pendingPrompt, setPendingPrompt] = useState<PendingPrompt | null>(null);
  const [promptValue, setPromptValue] = useState("");

  const confirm = useCallback((options: ConfirmOptions) => new Promise<boolean>((resolve) => {
    setPending({ ...options, resolve });
  }), []);
  const prompt = useCallback((options: PromptOptions) => new Promise<string | null>((resolve) => {
    setPromptValue("");
    setPendingPrompt({ ...options, resolve });
  }), []);

  const close = (value: boolean) => {
    pending?.resolve(value);
    setPending(null);
  };
  const closePrompt = (value: string | null) => {
    pendingPrompt?.resolve(value);
    setPendingPrompt(null);
    setPromptValue("");
  };

  const tone = pending?.tone ?? pendingPrompt?.tone;
  const title = pending?.title ?? pendingPrompt?.title;
  const message = pending?.message ?? pendingPrompt?.message;
  const confirmLabel = pending?.confirmLabel ?? pendingPrompt?.confirmLabel;

  return (
    <ConfirmContext.Provider value={confirm}>
      <PromptContext.Provider value={prompt}>
        {children}
        {(pending || pendingPrompt) && (
          <div className="fixed inset-0 z-[100] grid place-items-center p-4" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && (pendingPrompt ? closePrompt(null) : close(false))}>
            <div className="confirm-modal-backdrop absolute inset-0 bg-slate-950/60 backdrop-blur-sm" />
            <section role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message" className="confirm-modal-panel relative w-full max-w-md rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6 shadow-2xl">
              <button type="button" onClick={() => (pendingPrompt ? closePrompt(null) : close(false))} className="absolute right-4 top-4 rounded-xl p-2 text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]" aria-label="Close confirmation"><X size={18} /></button>
              <div className={`mb-4 grid h-12 w-12 place-items-center rounded-2xl ${tone === "danger" ? "bg-red-500/10 text-red-600" : "bg-[var(--color-accent)]/10 text-[var(--color-accent)]"}`}><AlertTriangle size={23} /></div>
              <h2 id="confirm-title" className="text-lg font-bold text-[var(--color-text)]">{title}</h2>
              <p id="confirm-message" className="mt-2 text-sm leading-relaxed text-[var(--color-text-secondary)]">{message}</p>
              {pendingPrompt && (
                <label className="mt-4 block text-sm font-semibold text-[var(--color-text)]">{pendingPrompt.label}
                  <input
                    autoFocus
                    value={promptValue}
                    onChange={(event) => setPromptValue(event.target.value)}
                    placeholder={pendingPrompt.placeholder}
                    aria-label={pendingPrompt.label}
                    className="mt-1 min-h-11 w-full rounded-xl border bg-[var(--color-input-background)] px-3 text-sm font-normal"
                    style={{ borderColor: "var(--color-border)" }}
                  />
                </label>
              )}
              <div className="mt-7 flex justify-end gap-3">
                <button type="button" onClick={() => (pendingPrompt ? closePrompt(null) : close(false))} className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm font-semibold text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]">Cancel</button>
                <button
                  type="button"
                  autoFocus={!pendingPrompt}
                  disabled={pendingPrompt ? promptValue.trim().length === 0 : false}
                  onClick={() => (pendingPrompt ? closePrompt(promptValue.trim()) : close(true))}
                  className={`min-h-11 rounded-xl px-4 text-sm font-bold text-white shadow-sm transition hover:brightness-110 disabled:opacity-50 ${tone === "danger" ? "bg-red-600" : "bg-[var(--color-accent)]"}`}
                >{confirmLabel || "Confirm"}</button>
              </div>
            </section>
          </div>
        )}
      </PromptContext.Provider>
    </ConfirmContext.Provider>
  );
}
