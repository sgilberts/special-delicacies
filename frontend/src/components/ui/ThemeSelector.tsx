import type { ChangeEvent } from "react";
import { useTheme } from "../../app/providers/useTheme";
import type { ThemeMode } from "../../app/providers/ThemeContext";

function ThemeSelector() {
  const { theme, setTheme } = useTheme();

  const handleThemeChange = (event: ChangeEvent<HTMLSelectElement>) => {
    setTheme(event.target.value as ThemeMode);
  };

  return (
    <div className="theme-selector">
      <label className="theme-selector__label" htmlFor="theme-selector">
        Appearance
      </label>

      <select
        id="theme-selector"
        className="theme-selector__control"
        value={theme}
        onChange={handleThemeChange}
        aria-label="Choose appearance theme"
      >
        <option value="system">System</option>
        <option value="light">Light</option>
        <option value="dark">Dark</option>
      </select>
    </div>
  );
}

export default ThemeSelector;
