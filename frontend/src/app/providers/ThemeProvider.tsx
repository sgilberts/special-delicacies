import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import {
  ThemeContext,
  type ThemeMode,
  type ResolvedTheme,
} from "./ThemeContext";

const STORAGE_KEY = "special-delicacies-theme";

function getSystemTheme(): ResolvedTheme {
  return window.matchMedia("(prefers-color-scheme: dark)").matches
    ? "dark"
    : "light";
}

function getInitialTheme(): ThemeMode {
  try {
    const storedTheme = localStorage.getItem(STORAGE_KEY);

    if (
      storedTheme === "light" ||
      storedTheme === "dark" ||
      storedTheme === "system"
    ) {
      return storedTheme;
    }
  } catch {
    // Use the system preference when local storage is unavailable.
  }

  return "system";
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<ThemeMode>(getInitialTheme);
  const [systemTheme, setSystemTheme] =
    useState<ResolvedTheme>(getSystemTheme);

  const resolvedTheme: ResolvedTheme =
    theme === "system" ? systemTheme : theme;

  const setTheme = useCallback((nextTheme: ThemeMode) => {
    setThemeState(nextTheme);

    try {
      localStorage.setItem(STORAGE_KEY, nextTheme);
    } catch {
      // Theme selection remains active for the current session.
    }
  }, []);

  useEffect(() => {
    const mediaQuery = window.matchMedia("(prefers-color-scheme: dark)");

    const updateSystemTheme = (event: MediaQueryListEvent) => {
      setSystemTheme(event.matches ? "dark" : "light");
    };

    mediaQuery.addEventListener("change", updateSystemTheme);

    return () => {
      mediaQuery.removeEventListener("change", updateSystemTheme);
    };
  }, []);

  useEffect(() => {
    document.documentElement.dataset.theme = theme;
    document.documentElement.dataset.resolvedTheme = resolvedTheme;
    document.documentElement.style.colorScheme = resolvedTheme;
  }, [theme, resolvedTheme]);

  const value = useMemo(
    () => ({ theme, resolvedTheme, setTheme }),
    [theme, resolvedTheme, setTheme],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}
