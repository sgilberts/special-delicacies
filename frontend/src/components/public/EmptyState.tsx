import { UtensilsCrossed } from "lucide-react";
interface Props { title?: string; description?: string; icon?: React.ReactNode; }
export default function EmptyState({ title = "Nothing here yet", description, icon }: Props) {
  return (
    <div className="flex flex-col items-center justify-center py-20 gap-4 text-center">
      <div style={{ color: "var(--color-text-secondary)" }}>
        {icon ?? <UtensilsCrossed size={48} strokeWidth={1.5} />}
      </div>
      <h3 className="text-xl font-semibold" style={{ color: "var(--color-text)" }}>{title}</h3>
      {description && <p className="max-w-sm" style={{ color: "var(--color-text-secondary)" }}>{description}</p>}
    </div>
  );
}
