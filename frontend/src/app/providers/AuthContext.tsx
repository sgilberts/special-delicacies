import React, { useEffect, useState, useCallback } from "react";
import api, { invalidateCachedGets } from "@/lib/api";
import type { ApiResponse, AuthUser } from "@/types/api";
import { AuthContext } from "./AuthContextState";

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [token, setToken] = useState<string | null>(() => {
    return localStorage.getItem("special_delicacies_token");
  });
  const [user, setUser] = useState<AuthUser | null>(() => {
    const saved = localStorage.getItem("special_delicacies_user");
    if (saved) {
      try {
        return JSON.parse(saved);
      } catch {
        return null;
      }
    }
    return null;
  });
  const [loading, setLoading] = useState<boolean>(true);

  const logout = useCallback(async () => {
    try {
      if (token) {
        await api.post("/auth/logout");
      }
    } catch {
      // Ignore network errors on logout
    } finally {
      localStorage.removeItem("special_delicacies_token");
      localStorage.removeItem("special_delicacies_user");
      invalidateCachedGets();
      setToken(null);
      setUser(null);
    }
  }, [token]);

  const refreshUser = useCallback(async () => {
    const currentToken = localStorage.getItem("special_delicacies_token");
    if (!currentToken) {
      setUser(null);
      setLoading(false);
      return;
    }

    try {
      const resp = await api.get<ApiResponse<AuthUser>>("/auth/me");
      if (resp.data?.success && resp.data.data) {
        const previous = localStorage.getItem("special_delicacies_user");
        const next = JSON.stringify(resp.data.data);
        if (previous !== next) invalidateCachedGets();
        setUser(resp.data.data);
        localStorage.setItem("special_delicacies_user", next);
      } else {
        await logout();
      }
    } catch {
      // If 401 or network error while token is invalid, clear session
      await logout();
    } finally {
      setLoading(false);
    }
  }, [logout]);

  useEffect(() => {
    refreshUser();
  }, [refreshUser]);

  useEffect(() => {
    const refreshVisibleSession = () => {
      if (document.visibilityState === "visible" && localStorage.getItem("special_delicacies_token")) {
        void refreshUser();
      }
    };

    document.addEventListener("visibilitychange", refreshVisibleSession);
    return () => document.removeEventListener("visibilitychange", refreshVisibleSession);
  }, [refreshUser]);

  const login = (newToken: string, newUser: AuthUser) => {
    invalidateCachedGets();
    localStorage.setItem("special_delicacies_token", newToken);
    localStorage.setItem("special_delicacies_user", JSON.stringify(newUser));
    setToken(newToken);
    setUser(newUser);
  };

  return (
    <AuthContext.Provider value={{ user, token, loading, login, logout, refreshUser }}>
      {children}
    </AuthContext.Provider>
  );
};
