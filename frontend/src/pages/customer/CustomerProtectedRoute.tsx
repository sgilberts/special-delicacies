import { useEffect, useState, type ReactNode } from "react";
import { Navigate } from "react-router-dom";
import customerApi from "@/lib/customerApi";

export default function CustomerProtectedRoute({ children }: { children: ReactNode }) {
  const [valid, setValid] = useState<boolean | null>(null);
  useEffect(() => {
    const legacyToken = localStorage.getItem("special_delicacies_customer_token");
    if (legacyToken && !sessionStorage.getItem("special_delicacies_customer_token")) sessionStorage.setItem("special_delicacies_customer_token", legacyToken);
    localStorage.removeItem("special_delicacies_customer_token");
    let active = true;
    customerApi.get("/customer/profile").then(() => { if (active) setValid(true); }).catch(() => {
      sessionStorage.removeItem("special_delicacies_customer_token");
      if (active) setValid(false);
    });
    return () => { active = false; };
  }, []);
  if (valid === null) return <div className="p-10 text-center text-[var(--color-text-secondary)]">Loading your account…</div>;
  return valid ? <>{children}</> : <Navigate to="/account/login" replace />;
}
