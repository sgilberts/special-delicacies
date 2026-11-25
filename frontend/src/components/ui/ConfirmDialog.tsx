import React, { createContext, useCallback, useContext, useState } from "react";
import { AlertTriangle, X } from "lucide-react";

interface ConfirmOptions {
  title: string;
  message: string;
  confirmLabel?: string;
  tone?: "danger" | "primary";
}

interface PendingConfirm extends ConfirmOptions {
  resolve: (confirmed: boolean) => void;
}

const ConfirmContext = createContext<((options: ConfirmOptions) => Promise<boolean>) | null>(null);

export function useConfirm() {
  const confirm = useContext(ConfirmContext);
  if (!confirm) throw new Error("useConfirm must be used inside ConfirmProvider");
  return confirm;
}

export function ConfirmProvider({ children }: { children: React.ReactNode }) {
  const [pending, setPending] = useState<PendingConfirm | null>(null);
  const confirm = useCallback((options: ConfirmOptions) => new Promise<boolean>((resolve) => {
    setPending({ ...options, resolve });
  }), []);
  const close = (value: boolean) => {
    pending?.resolve(value);
    setPending(null);
  };

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      {pending && (
        <div className="fixed inset-0 z-[100] grid place-items-center p-4" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && close(false)}>
          <div className="confirm-modal-backdrop absolute inset-0 bg-slate-950/60 backdrop-blur-sm" />
          <section role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message" className="confirm-modal-panel relative w-full max-w-md rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6 shadow-2xl">
            <button type="button" onClick={() => close(false)} className="absolute right-4 top-4 rounded-xl p-2 text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-secondary)]" aria-label="Close confirmation"><X size={18} /></button>
            <div className={`mb-4 grid h-12 w-12 place-items-center rounded-2xl ${pending.tone === "danger" ? "bg-red-500/10 text-red-600" : "bg-[var(--color-accent)]/10 text-[var(--color-accent)]"}`}><AlertTriangle size={23} /></div>
            <h2 id="confirm-title" className="text-lg font-bold text-[var(--color-text)]">{pending.title}</h2>
            <p id="confirm-message" className="mt-2 text-sm leading-relaxed text-[var(--color-text-secondary)]">{pending.message}</p>
            <div className="mt-7 flex justify-end gap-3">
              <button type="button" onClick={() => close(false)} className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm font-semibold text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)]">Cancel</button>
              <button type="button" autoFocus onClick={() => close(true)} className={`min-h-11 rounded-xl px-4 text-sm font-bold text-white shadow-sm transition hover:brightness-110 ${pending.tone === "danger" ? "bg-red-600" : "bg-[var(--color-accent)]"}`}>{pending.confirmLabel || "Confirm"}</button>
            </div>
          </section>
        </div>
      )}
    </ConfirmContext.Provider>
  );
}
