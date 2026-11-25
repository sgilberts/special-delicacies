import React, { useEffect, useState } from "react";
import { AlertCircle } from "lucide-react";
import { Link, Navigate, useLocation } from "react-router-dom";
import { useAuth } from "@/app/providers/useAuth";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import { hasAnyPermission } from "@/lib/permissionAccess";

export const ProtectedRoute: React.FC<{
  children: React.ReactNode;
  requiredPermissions?: string[];
}> = ({ children, requiredPermissions }) => {
  const { user, loading, refreshUser } = useAuth();
  const location = useLocation();
  const permissionKey = requiredPermissions?.join("|") ?? "";
  const validationKey = permissionKey ? `${location.pathname}:${permissionKey}` : "";
  const [validatedRoute, setValidatedRoute] = useState("");

  useEffect(() => {
    if (loading || !user || !permissionKey) return;

    let active = true;
    setValidatedRoute("");
    void refreshUser().finally(() => {
      if (active) setValidatedRoute(validationKey);
    });

    return () => {
      active = false;
    };
  }, [loading, user?.id, permissionKey, validationKey, refreshUser]);

  if (loading || (permissionKey && validatedRoute !== validationKey)) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[var(--color-background)]">
        <LoadingSpinner message="Verifying administrative access..." />
      </div>
    );
  }

  if (!user || user.roles.length === 0) {
    return <Navigate to="/admin/login" state={{ from: location }} replace />;
  }

  if (requiredPermissions?.length && !hasAnyPermission(user.permissions, requiredPermissions)) {
    return (
      <section
        role="alert"
        className="min-h-[50vh] flex flex-col items-center justify-center gap-4 px-6 text-center text-[var(--color-text)]"
      >
        <AlertCircle size={44} className="text-[var(--color-danger)]" />
        <div>
          <h1 className="text-xl font-bold">403 — Access denied</h1>
          <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
            Your account does not have permission to open this page.
          </p>
        </div>
        <Link
          to="/admin"
          className="rounded-xl bg-[var(--brand-copper)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90"
        >
          Return to dashboard
        </Link>
      </section>
    );
  }

  return <>{children}</>;
};
