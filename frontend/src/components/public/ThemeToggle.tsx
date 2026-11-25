import { Sun, Moon, Monitor } from "lucide-react";
import { useTheme } from "@/app/providers/useTheme";
import type { ThemeMode } from "@/app/providers/ThemeContext";

const modes: ThemeMode[] = ["light", "dark", "system"];
const labels: Record<ThemeMode, string> = { light: "Light", dark: "Dark", system: "System" };
const icons: Record<ThemeMode, React.ReactNode> = {
  light: <Sun size={18} />, dark: <Moon size={18} />, system: <Monitor size={18} />,
};

export default function ThemeToggle() {
  const { theme, setTheme } = useTheme();
  const next = () => {
    const idx = modes.indexOf(theme);
    setTheme(modes[(idx + 1) % modes.length]);
  };
  return (
    <button
      onClick={next}
      aria-label={`Theme: ${labels[theme]}. Click to switch.`}
      className="flex items-center justify-center w-10 h-10 rounded-full transition-colors"
      style={{
        color: "var(--color-text-secondary)",
        background: "var(--color-surface-secondary)",
        border: "1px solid var(--color-border)",
      }}
    >
      {icons[theme]}
    </button>
  );
}
