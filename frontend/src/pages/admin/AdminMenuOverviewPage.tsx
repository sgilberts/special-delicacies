import { useEffect, useState, useCallback } from "react";
import { Link } from "react-router-dom";
import {
  FolderTree,
  Utensils,
  CheckCircle2,
  XCircle,
  Plus,
  RefreshCw,
  ArrowRight,
  TrendingUp,
  Image as ImageIcon,
} from "lucide-react";
import { cachedGet } from "@/lib/api";
import { assetUrl } from "@/lib/assetUrl";
import type { ApiResponse, MenuOverviewStats } from "@/types/api";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ErrorState from "@/components/public/ErrorState";

function formatGhs(price: string): string {
  const val = parseFloat(price);
  return isNaN(val) ? `GH₵${price}` : `GH₵${val.toFixed(2)}`;
}

export default function AdminMenuOverviewPage() {
  const [stats, setStats] = useState<MenuOverviewStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchStats = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const resp = await cachedGet<ApiResponse<MenuOverviewStats>>("/admin/menu/overview", 15_000);
      if (resp.data?.success && resp.data.data) {
        setStats(resp.data.data);
      } else {
        setError("Failed to load overview data.");
      }
    } catch {
      setError("Unable to connect to the menu statistics endpoint.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    document.title = "Overview | Menu Management";
    fetchStats();
  }, [fetchStats]);

  if (loading && !stats) {
    return (
      <div className="py-16">
        <LoadingSpinner message="Loading menu analytics..." />
      </div>
    );
  }

  if (error && !stats) {
    return (
      <div className="py-12">
        <ErrorState message={error} onRetry={fetchStats} />
      </div>
    );
  }

  return (
    <div className="space-y-8 animate-in fade-in duration-200">
      {/* 1. PAGE HEADER */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[var(--color-border)] pb-5">
        <div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--color-text)]">
            Menu Overview
          </h1>
          <p className="text-xs sm:text-sm text-[var(--color-text-secondary)] mt-1">
            Real-time status of categories, dishes, prices, and food availability.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={fetchStats}
            disabled={loading}
            className="btn-ghost flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold min-h-[44px]"
            title="Refresh statistics"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
            <span>Refresh</span>
          </button>

          <Link
            to="/admin/items"
            className="btn-accent flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold min-h-[44px]"
          >
            <Plus className="w-4 h-4" />
            <span>Manage Items</span>
          </Link>
        </div>
      </div>

      {/* 2. STATS CARDS */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        {/* Card 1: Total Categories */}
        <div className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-5 shadow-xs flex items-center justify-between">
          <div>
            <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
              Categories
            </span>
            <div className="text-3xl font-extrabold mt-1 text-[var(--color-text)]">
              {stats?.total_categories ?? 0}
            </div>
            <span className="text-[11px] text-[var(--color-text-secondary)] mt-1 block">
              {stats?.active_categories ?? 0} active &bull; {stats?.inactive_categories ?? 0} inactive
            </span>
          </div>
          <div
            className="w-12 h-12 rounded-xl flex items-center justify-center shrink-0"
            style={{
              background: "var(--color-surface-secondary)",
              color: "var(--color-accent)",
            }}
          >
            <FolderTree className="w-6 h-6" />
          </div>
        </div>

        {/* Card 2: Total Menu Items */}
        <div className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-5 shadow-xs flex items-center justify-between">
          <div>
            <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
              Total Menu Items
            </span>
            <div className="text-3xl font-extrabold mt-1 text-[var(--color-text)]">
              {stats?.total_items ?? 0}
            </div>
            <span className="text-[11px] text-[var(--color-text-secondary)] mt-1 block">
              Configured dishes & drinks
            </span>
          </div>
          <div
            className="w-12 h-12 rounded-xl flex items-center justify-center shrink-0"
            style={{
              background: "var(--color-surface-secondary)",
              color: "var(--brand-gold)",
            }}
          >
            <Utensils className="w-6 h-6" />
          </div>
        </div>

        {/* Card 3: Available Items */}
        <div className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-5 shadow-xs flex items-center justify-between">
          <div>
            <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
              Available Items
            </span>
            <div className="text-3xl font-extrabold mt-1 text-[var(--color-success)]">
              {stats?.available_items ?? 0}
            </div>
            <span className="text-[11px] text-[var(--color-text-secondary)] mt-1 block">
              Live on public website
            </span>
          </div>
          <div
            className="w-12 h-12 rounded-xl flex items-center justify-center shrink-0"
            style={{
              background: "rgba(85, 123, 85, 0.12)",
              color: "var(--color-success)",
            }}
          >
            <CheckCircle2 className="w-6 h-6" />
          </div>
        </div>

        {/* Card 4: Unavailable Items */}
        <div className="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-5 shadow-xs flex items-center justify-between">
          <div>
            <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
              Unavailable Items
            </span>
            <div className="text-3xl font-extrabold mt-1 text-[var(--color-danger)]">
              {stats?.unavailable_items ?? 0}
            </div>
            <span className="text-[11px] text-[var(--color-text-secondary)] mt-1 block">
              Sold out or hidden
            </span>
          </div>
          <div
            className="w-12 h-12 rounded-xl flex items-center justify-center shrink-0"
            style={{
              background: "rgba(184, 76, 69, 0.12)",
              color: "var(--color-danger)",
            }}
          >
            <XCircle className="w-6 h-6" />
          </div>
        </div>
      </div>

      {/* 3. QUICK ACTIONS & RECENT DISHES */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Quick Management Shortcuts */}
        <div className="lg:col-span-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-6 shadow-xs flex flex-col justify-between">
          <div>
            <h2 className="text-lg font-bold text-[var(--color-text)] flex items-center gap-2">
              <TrendingUp className="w-5 h-5 text-[var(--color-accent)]" />
              <span>Quick Management</span>
            </h2>
            <p className="text-xs text-[var(--color-text-secondary)] mt-1">
              Shortcuts for everyday kitchen and menu operations.
            </p>

            <div className="mt-5 space-y-2.5">
              <Link
                to="/admin/categories"
                className="flex items-center justify-between p-3.5 rounded-xl border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]/60 transition-all group min-h-[48px]"
              >
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-[var(--color-surface-secondary)] flex items-center justify-center text-[var(--color-accent)]">
                    <FolderTree className="w-4 h-4" />
                  </div>
                  <div className="text-left">
                    <div className="text-xs font-bold text-[var(--color-text)]">
                      Manage Food Categories
                    </div>
                    <div className="text-[11px] text-[var(--color-text-secondary)]">
                      Add, reorder, or toggle categories
                    </div>
                  </div>
                </div>
                <ArrowRight className="w-4 h-4 text-[var(--color-text-secondary)] group-hover:translate-x-1 transition-transform" />
              </Link>

              <Link
                to="/admin/items"
                className="flex items-center justify-between p-3.5 rounded-xl border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]/60 transition-all group min-h-[48px]"
              >
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-[var(--color-surface-secondary)] flex items-center justify-center text-[var(--color-accent)]">
                    <Utensils className="w-4 h-4" />
                  </div>
                  <div className="text-left">
                    <div className="text-xs font-bold text-[var(--color-text)]">
                      Manage Menu Items
                    </div>
                    <div className="text-[11px] text-[var(--color-text-secondary)]">
                      Update prices, availability & photos
                    </div>
                  </div>
                </div>
                <ArrowRight className="w-4 h-4 text-[var(--color-text-secondary)] group-hover:translate-x-1 transition-transform" />
              </Link>

              <Link
                to="/menu"
                target="_blank"
                rel="noreferrer"
                className="flex items-center justify-between p-3.5 rounded-xl border border-[var(--color-border)] hover:bg-[var(--color-surface-secondary)]/60 transition-all group min-h-[48px]"
              >
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-[var(--color-surface-secondary)] flex items-center justify-center text-[var(--brand-gold)]">
                    <CheckCircle2 className="w-4 h-4" />
                  </div>
                  <div className="text-left">
                    <div className="text-xs font-bold text-[var(--color-text)]">
                      Preview Live Website Menu
                    </div>
                    <div className="text-[11px] text-[var(--color-text-secondary)]">
                      Inspect guest-facing presentation
                    </div>
                  </div>
                </div>
                <ArrowRight className="w-4 h-4 text-[var(--color-text-secondary)] group-hover:translate-x-1 transition-transform" />
              </Link>
            </div>
          </div>

          <div className="mt-6 pt-4 border-t border-[var(--color-border)] text-[11px] text-[var(--color-text-secondary)]">
            Prices configured in <strong className="text-[var(--color-text)]">Ghana Cedis (GH₵)</strong>.
          </div>
        </div>

        {/* Recently Added / Updated Items */}
        <div className="lg:col-span-2 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-2xl p-6 shadow-xs flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between mb-4">
              <div>
                <h2 className="text-lg font-bold text-[var(--color-text)]">
                  Recently Added Dishes
                </h2>
                <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">
                  Latest items stored in the database.
                </p>
              </div>
              <Link
                to="/admin/items"
                className="text-xs font-bold text-[var(--color-accent)] hover:underline min-h-[44px] flex items-center px-1"
              >
                View all items
              </Link>
            </div>

            {(!stats?.recent_items || stats.recent_items.length === 0) ? (
              <div className="text-center py-10 px-4 rounded-xl border border-dashed border-[var(--color-border)]">
                <Utensils className="w-8 h-8 mx-auto text-[var(--color-text-secondary)] mb-2 opacity-50" />
                <h3 className="text-sm font-bold text-[var(--color-text)]">No menu items created yet</h3>
                <p className="text-xs text-[var(--color-text-secondary)] mt-1 max-w-sm mx-auto">
                  Get started by creating categories, then add dishes with prices in GH₵ and photographs.
                </p>
                <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                  <Link
                    to="/admin/categories"
                    className="btn-ghost text-xs px-3 py-1.5 rounded-lg min-h-[40px] flex items-center"
                  >
                    1. Create Category
                  </Link>
                  <Link
                    to="/admin/items"
                    className="btn-accent text-xs px-3 py-1.5 rounded-lg min-h-[40px] flex items-center"
                  >
                    2. Add Menu Item
                  </Link>
                </div>
              </div>
            ) : (
              <div className="divide-y divide-[var(--color-border)]">
                {stats.recent_items.map((item) => (
                  <div key={item.id} className="py-3 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3 min-w-0">
                      <div className="w-12 h-12 rounded-xl bg-[var(--color-surface-secondary)] overflow-hidden shrink-0 flex items-center justify-center border border-[var(--color-border)]">
                        {item.image_path ? (
                          <img
                            src={assetUrl(item.image_path) ?? undefined}
                            alt={item.name}
                            className="w-full h-full object-cover"
                          />
                        ) : (
                          <ImageIcon className="w-5 h-5 text-[var(--color-text-secondary)]" />
                        )}
                      </div>
                      <div className="min-w-0">
                        <h4 className="text-sm font-bold text-[var(--color-text)] truncate">
                          {item.name}
                        </h4>
                        <div className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)]">
                          <span>{item.category?.name || "Uncategorized"}</span>
                          <span>&bull;</span>
                          <span
                            className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                              item.is_available
                                ? "bg-[var(--color-success)]/15 text-[var(--color-success)]"
                                : "bg-[var(--color-danger)]/15 text-[var(--color-danger)]"
                            }`}
                          >
                            {item.is_available ? "Available" : "Unavailable"}
                          </span>
                        </div>
                      </div>
                    </div>

                    <div className="text-right shrink-0">
                      <div className="text-sm font-extrabold text-[var(--color-accent)]">
                        {formatGhs(item.price)}
                      </div>
                      <Link
                        to="/admin/items"
                        className="text-[11px] font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)] underline mt-0.5 block min-h-[30px] flex items-center justify-end"
                      >
                        Edit
                      </Link>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="mt-4 pt-3 border-t border-[var(--color-border)] flex items-center justify-between text-xs text-[var(--color-text-secondary)]">
            <span>Live data from Special Delicacies database</span>
            <Link to="/admin/items" className="font-semibold text-[var(--color-accent)] hover:underline">
              Manage all &rarr;
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}
