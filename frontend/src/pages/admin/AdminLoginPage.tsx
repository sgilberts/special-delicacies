import { useState } from "react";
import { useNavigate, useLocation, Link } from "react-router-dom";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Lock, Mail, Utensils, AlertCircle, ArrowLeft, Loader2 } from "lucide-react";
import api from "@/lib/api";
import { useAuth } from "@/app/providers/useAuth";
import { useToast } from "@/components/ui/useToast";
import type { ApiResponse, AuthUser } from "@/types/api";
import ThemeToggle from "@/components/public/ThemeToggle";
import PasswordInput from "@/components/ui/PasswordInput";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import { assetUrl } from "@/lib/assetUrl";

const loginSchema = z.object({
  email: z.string().min(1, "Email address is required").email("Please enter a valid email address"),
  password: z.string().min(1, "Password is required"),
  remember: z.boolean().optional(),
});

type LoginFormValues = z.infer<typeof loginSchema>;

export default function AdminLoginPage() {
  const { login } = useAuth();
  const { content } = useRestaurantContent();
  const navigate = useNavigate();
  const location = useLocation();
  const { success } = useToast();
  const [serverError, setServerError] = useState<string | null>(null);

  const from = (location.state as { from?: { pathname: string } })?.from?.pathname || "/admin";

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      email: "admin@special-delicacies.local",
      password: "",
      remember: false,
    },
  });

  const onSubmit = async (values: LoginFormValues) => {
    setServerError(null);
    try {
      const resp = await api.post<ApiResponse<{ token: string | null; user: AuthUser }>>("/auth/login", values);
      if (resp.data?.success && resp.data.data) {
        login(resp.data.data.token, resp.data.data.user);
        success(`Welcome back, ${resp.data.data.user.name}!`);
        navigate(from, { replace: true });
      } else {
        setServerError(resp.data?.message || "Authentication failed.");
      }
    } catch (err: unknown) {
      if (err && typeof err === "object" && "response" in err) {
        const axErr = err as { response?: { data?: { message?: string } } };
        setServerError(axErr.response?.data?.message || "Invalid credentials. Please verify your email and password.");
      } else {
        setServerError("Unable to connect to the authentication service. Please check your network.");
      }
    }
  };

  return (
    <div className="min-h-screen flex flex-col justify-between bg-[var(--color-background)] px-4 py-8">
      {/* Top Header */}
      <div className="w-full max-w-md mx-auto flex items-center justify-between">
        <Link
          to="/"
          className="inline-flex items-center gap-1.5 text-xs font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)] transition-colors min-h-[44px] px-2"
        >
          <ArrowLeft className="w-4 h-4" />
          <span>Return to Website</span>
        </Link>
        <ThemeToggle />
      </div>

      {/* Main Card */}
      <div className="w-full max-w-md mx-auto my-auto">
        <div className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-6 sm:p-8 shadow-md">
          {/* Brand Icon & Heading */}
          <div className="text-center mb-6">
            {content.restaurant_logo ? <img src={assetUrl(content.restaurant_logo) ?? undefined} alt={content.restaurant_name} className="mx-auto mb-3 h-14 w-14 rounded-2xl border border-[var(--color-border)] bg-[var(--color-background)] object-contain p-1 shadow-sm" /> : <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--color-primary)] shadow-sm"><Utensils className="h-7 w-7 text-[var(--color-background)]" /></div>}
            <h1 className="text-2xl font-extrabold tracking-tight text-[var(--color-text)]">
              {content.restaurant_name || "Restaurant"} Admin Sign In
            </h1>
            <p className="text-xs sm:text-sm text-[var(--color-text-secondary)] mt-1">
              Enter your credentials to manage restaurant menu items and categories.
            </p>
          </div>

          {/* Error Banner */}
          {serverError && (
            <div
              role="alert"
              className="mb-5 p-3.5 rounded-xl border border-[var(--color-danger)] bg-[var(--color-danger)]/10 text-[var(--color-danger)] flex items-start gap-2.5 text-xs sm:text-sm animate-in fade-in"
            >
              <AlertCircle className="w-5 h-5 shrink-0 mt-0.5" />
              <span className="leading-snug">{serverError}</span>
            </div>
          )}

          {/* Form */}
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
            <div>
              <label
                htmlFor="email"
                className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1.5"
              >
                Email Address
              </label>
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[var(--color-text-secondary)]">
                  <Mail className="w-4 h-4" />
                </div>
                <input
                  id="email"
                  type="email"
                  autoComplete="email"
                  placeholder="admin@special-delicacies.local"
                  {...register("email")}
                  aria-invalid={!!errors.email}
                  aria-describedby={errors.email ? "email-error" : undefined}
                  className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] transition-all min-h-[44px]"
                />
              </div>
              {errors.email && (
                <p id="email-error" className="mt-1.5 text-xs text-[var(--color-danger)]">
                  {errors.email.message}
                </p>
              )}
            </div>

            <div>
              <label
                htmlFor="password"
                className="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)] mb-1.5"
              >
                Password
              </label>
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[var(--color-text-secondary)]">
                  <Lock className="w-4 h-4" />
                </div>
                <PasswordInput
                  id="password"
                  autoComplete="current-password"
                  placeholder="••••••••••••"
                  {...register("password")}
                  aria-invalid={!!errors.password}
                  aria-describedby={errors.password ? "password-error" : undefined}
                  className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-input-background)] text-[var(--color-text)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] transition-all min-h-[44px]"
                />
              </div>
              {errors.password && (
                <p id="password-error" className="mt-1.5 text-xs text-[var(--color-danger)]">
                  {errors.password.message}
                </p>
              )}
            </div>

            <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium text-[var(--color-text)]">
              <input type="checkbox" {...register("remember")} className="h-4 w-4 accent-[var(--color-accent)]" />
              Remember me
            </label>

            <button
              type="submit"
              disabled={isSubmitting}
              className="w-full mt-2 btn-accent flex items-center justify-center gap-2 py-3 rounded-xl font-bold text-sm shadow-md transition-all disabled:opacity-60 min-h-[46px]"
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>Signing in...</span>
                </>
              ) : (
                <span>Sign In to Menu Management</span>
              )}
            </button>
          </form>
          <p className="mt-4 text-center text-sm"><Link className="font-semibold text-[var(--color-accent)]" to="/admin/password/forgot">Forgot password?</Link></p>
        </div>
      </div>

      {/* Footer info */}
      <div className="text-center text-xs text-[var(--color-text-secondary)] mt-8">
        {content.restaurant_name || "Special Delicacies"} Administration
      </div>
    </div>
  );
}
