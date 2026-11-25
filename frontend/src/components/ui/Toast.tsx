import React, { useState, useCallback } from "react";
import { CheckCircle2, AlertCircle, X, Info } from "lucide-react";
import { ToastContext, type Toast, type ToastType } from "./ToastContext";

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const removeToast = useCallback((id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const toast = useCallback(
    (message: string, type: ToastType = "info") => {
      const id = Math.random().toString(36).substring(2, 9);
      setToasts((prev) => [...prev, { id, type, message }]);

      setTimeout(() => {
        removeToast(id);
      }, 4500);
    },
    [removeToast]
  );

  const success = useCallback((msg: string) => toast(msg, "success"), [toast]);
  const error = useCallback((msg: string) => toast(msg, "error"), [toast]);
  const info = useCallback((msg: string) => toast(msg, "info"), [toast]);

  return (
    <ToastContext.Provider value={{ toast, success, error, info }}>
      {children}
      <div className="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm w-full pointer-events-none p-2">
        {toasts.map((t) => (
          <div
            key={t.id}
            role="status"
            aria-live="polite"
            className={`pointer-events-auto flex items-start gap-3 p-4 rounded-xl shadow-lg border transition-all animate-in fade-in slide-in-from-bottom-2 ${
              t.type === "success"
                ? "bg-[var(--color-surface)] border-[var(--color-success)] text-[var(--color-text)]"
                : t.type === "error"
                ? "bg-[var(--color-surface)] border-[var(--color-danger)] text-[var(--color-text)]"
                : "bg-[var(--color-surface)] border-[var(--color-border)] text-[var(--color-text)]"
            }`}
          >
            {t.type === "success" && (
              <CheckCircle2 className="w-5 h-5 text-[var(--color-success)] shrink-0 mt-0.5" />
            )}
            {t.type === "error" && (
              <AlertCircle className="w-5 h-5 text-[var(--color-danger)] shrink-0 mt-0.5" />
            )}
            {t.type === "info" && (
              <Info className="w-5 h-5 text-[var(--color-accent)] shrink-0 mt-0.5" />
            )}

            <div className="flex-1 text-sm font-medium leading-snug">{t.message}</div>

            <button
              type="button"
              onClick={() => removeToast(t.id)}
              className="text-[var(--color-text-secondary)] hover:text-[var(--color-text)] p-1 rounded-md transition-colors"
              aria-label="Dismiss notification"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
};
