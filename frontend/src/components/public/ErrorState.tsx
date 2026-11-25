import { AlertCircle } from "lucide-react";
interface Props { message?: string; onRetry?: () => void; }
export default function ErrorState({ message = "Something went wrong.", onRetry }: Props) {
  return (
    <div className="flex flex-col items-center justify-center py-20 gap-4 text-center">
      <AlertCircle size={48} strokeWidth={1.5} style={{ color: "var(--color-danger)" }} />
      <p style={{ color: "var(--color-text-secondary)" }}>{message}</p>
      {onRetry && (
        <button onClick={onRetry}
          className="px-5 py-2 rounded-full text-sm font-semibold transition-colors"
          style={{ background: "var(--color-accent)", color: "#fff" }}>
          Try again
        </button>
      )}
    </div>
  );
}
