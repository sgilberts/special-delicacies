export default function LoadingSpinner({ message = "Loading..." }: { message?: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-20 gap-4">
      <div className="w-10 h-10 rounded-full border-4 border-t-transparent animate-spin"
        style={{ borderColor: "var(--color-border)", borderTopColor: "var(--color-accent)" }} />
      <p style={{ color: "var(--color-text-secondary)" }}>{message}</p>
    </div>
  );
}
