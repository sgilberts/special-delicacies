import { useEffect, useState, useCallback, useRef } from "react";
import {
  Radio,
  Power,
  RefreshCw,
  Play,
  Square,
  Zap,
  Clock,
  Server,
  Activity,
  Terminal,
  Trash2,
  Cpu,
} from "lucide-react";
import apiClient from "@/lib/api";
import echo from "@/lib/echo";
import { useToast } from "@/components/ui/useToast";
import LoadingSpinner from "@/components/public/LoadingSpinner";
import ErrorState from "@/components/public/ErrorState";
import { useConfirm } from "@/components/ui/ConfirmDialog";

interface ReverbStatus {
  is_running: boolean;
  driver: string;
  pid: number | null;
  host: string;
  port: number;
  scheme: string;
  app_id: string;
  app_key: string;
  memory: string | null;
  uptime: string | null;
  server_time: string;
}

interface BroadcastEventLog {
  id: string;
  timestamp: string;
  event: string;
  channel: string;
  data: Record<string, unknown>;
  latencyMs?: number;
}

export default function AdminReverbPage() {
  const confirmDialog = useConfirm();
  const { success, error: toastError } = useToast();

  const [status, setStatus] = useState<ReverbStatus | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [actionLoading, setActionLoading] = useState<string | null>(null);

  // WebSocket Live Monitor
  const [wsState, setWsState] = useState<"initializing" | "connecting" | "connected" | "reconnecting" | "disconnected" | "unavailable">("initializing");
  const [channelAuthState, setChannelAuthState] = useState<"initializing" | "authorizing" | "authorized" | "auth_failed">("initializing");
  const [events, setEvents] = useState<BroadcastEventLog[]>([]);
  const pingTimestampRef = useRef<number | null>(null);

  // Fetch status from API
  const fetchStatus = useCallback(async () => {
    try {
      const resp = await apiClient.get<{ success: boolean; data: ReverbStatus }>("/admin/reverb/status");
      if (resp.data?.success) {
        setStatus(resp.data.data);
        setError(null);
      }
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      setError(e?.response?.data?.message || "Failed to query Reverb operational status.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    document.title = "Reverb Real-Time Broadcast Monitor | Special Delicacies Admin";
    fetchStatus();

    const interval = setInterval(fetchStatus, 6000);
    return () => clearInterval(interval);
  }, [fetchStatus]);

  // Connect to Echo monitor channel
  useEffect(() => {
    setWsState("initializing");
    let connection: { state: string; bind: (event: string, callback: (data?: unknown) => void) => void; unbind: (event: string, callback: (data?: unknown) => void) => void } | undefined;
    let channel: ReturnType<typeof echo.private> | undefined;
    let onStateChange: ((data?: unknown) => void) | undefined;
    let onSubscribed: (() => void) | undefined;
    let onAuthError: ((error: unknown) => void) | undefined;
    let onBroadcastEvent: ((event: string, payload?: unknown) => void) | undefined;
    try {
      const pusherInstance = (echo as unknown as { connector?: { pusher?: { connection?: typeof connection } } }).connector?.pusher;
      connection = pusherInstance?.connection;
      if (!connection) {
        setWsState("unavailable");
        return;
      }
      const hasConnected = { current: connection.state === "connected" };
      const setConnectionState = (state: string) => {
        if (state === "connected") {
          hasConnected.current = true;
          setWsState("connected");
          setChannelAuthState("authorizing");
        } else if (state === "connecting") {
          setWsState(hasConnected.current ? "reconnecting" : "connecting");
          setChannelAuthState("authorizing");
        } else if (state === "unavailable") {
          setWsState("unavailable");
          setChannelAuthState("initializing");
        } else if (state === "initialized") {
          setWsState("initializing");
          setChannelAuthState("initializing");
        } else if (state === "disconnected" || state === "failed") {
          setWsState("disconnected");
          setChannelAuthState("initializing");
        } else {
          setWsState("disconnected");
        }
      };
      setConnectionState(connection.state);
      onStateChange = (change?: unknown) => setConnectionState((change as { current?: string } | undefined)?.current || "disconnected");
      connection.bind("state_change", onStateChange);

      channel = echo.private("reverb.monitor");
      onSubscribed = () => setChannelAuthState("authorized");
      onAuthError = () => setChannelAuthState("auth_failed");
      channel.subscribed(onSubscribed);
      channel.error(onAuthError);
      onBroadcastEvent = (eventName, eventPayload) => {
        if (eventName !== ".reverb.ping" && eventName !== "reverb.ping") return;
        const payload = eventPayload && typeof eventPayload === "object"
          ? eventPayload as Record<string, unknown>
          : {};
        const now = Date.now();
        const latency = pingTimestampRef.current ? now - pingTimestampRef.current : undefined;
        pingTimestampRef.current = null;

        const newLog: BroadcastEventLog = {
          id: Math.random().toString(36).substring(2, 9),
          timestamp: typeof payload.timestamp === "string" ? new Date(payload.timestamp).toLocaleTimeString() : new Date().toLocaleTimeString(),
          event: "reverb.ping",
          channel: "private-reverb.monitor",
          data: payload,
          latencyMs: latency,
        };

        setEvents((prev) => [newLog, ...prev.slice(0, 49)]);
      };
      channel.listenToAll(onBroadcastEvent);

      return () => {
        if (onStateChange) connection?.unbind("state_change", onStateChange);
        if (onSubscribed) channel?.stopListening(".pusher:subscription_succeeded");
        if (onAuthError) channel?.stopListening(".pusher:subscription_error");
        if (onBroadcastEvent) channel?.stopListeningToAll(onBroadcastEvent);
        echo.leave("reverb.monitor");
      };
    } catch {
      setWsState("unavailable");
      setChannelAuthState("auth_failed");
      return () => {
        if (onStateChange) connection?.unbind("state_change", onStateChange);
        echo.leave("reverb.monitor");
      };
    }
  }, []);

  // Server actions
  const handleStart = async () => {
    setActionLoading("start");
    try {
      const resp = await apiClient.post<{ success: boolean; message: string }>("/admin/reverb/start");
      if (resp.data?.success) {
        success(resp.data.message);
        await fetchStatus();
      } else {
        toastError(resp.data?.message || "Failed to start Reverb.");
      }
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to start Reverb server.");
    } finally {
      setActionLoading(null);
    }
  };

  const handleStop = async () => {
    if (!await confirmDialog({ title: "Stop Reverb?", message: "Real-time order updates will pause until the broadcast server is running again.", confirmLabel: "Stop server", tone: "danger" })) {
      return;
    }
    setActionLoading("stop");
    try {
      const resp = await apiClient.post<{ success: boolean; message: string }>("/admin/reverb/stop");
      if (resp.data?.success) {
        success(resp.data.message);
        await fetchStatus();
      } else {
        toastError(resp.data?.message || "Failed to stop Reverb.");
      }
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to stop Reverb server.");
    } finally {
      setActionLoading(null);
    }
  };

  const handleRestart = async () => {
    setActionLoading("restart");
    try {
      const resp = await apiClient.post<{ success: boolean; message: string }>("/admin/reverb/restart");
      if (resp.data?.success) {
        success(resp.data.message);
        await fetchStatus();
      } else {
        toastError(resp.data?.message || "Failed to restart Reverb.");
      }
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to restart Reverb server.");
    } finally {
      setActionLoading(null);
    }
  };

  const handleTestPing = async () => {
    setActionLoading("ping");
    pingTimestampRef.current = Date.now();
    try {
      const resp = await apiClient.post<{ success: boolean; message: string }>("/admin/reverb/test-broadcast", {
        note: `Admin broadcast test at ${new Date().toLocaleTimeString()}`,
      });
      if (resp.data?.success) {
        success("Test broadcast event dispatched!");
      }
    } catch (err: unknown) {
      pingTimestampRef.current = null;
      const e = err as { response?: { data?: { message?: string } } };
      toastError(e?.response?.data?.message || "Failed to dispatch test broadcast.");
    } finally {
      setActionLoading(null);
    }
  };

  if (loading && !status) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <LoadingSpinner />
      </div>
    );
  }

  if (error && !status) {
    return <ErrorState message={error} onRetry={fetchStatus} />;
  }

  const isRunning = !!status?.is_running;
  const managesLocalReverb = (status?.driver || "reverb") === "reverb";

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      {/* ── Page Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <Radio className="w-7 h-7 text-[var(--color-accent)] animate-pulse" />
            <h1 className="text-2xl font-extrabold text-[var(--color-text)]">
              Laravel Reverb & WebSocket Monitor
            </h1>
          </div>
          <p className="text-sm text-[var(--color-text-secondary)] mt-1">
            Real-time server management, process reliability controls, and broadcast event telemetry
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={fetchStatus}
            disabled={loading}
            className="flex items-center gap-2 px-3.5 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-xs font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] transition-colors min-h-[44px]"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin text-[var(--color-accent)]" : ""}`} />
            Refresh Status
          </button>
        </div>
      </div>

      {/* ── Status Banners & KPI Cards ── */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Reverb Server State */}
        <div className="rounded-xl p-4 border border-[var(--color-border)] bg-[var(--color-surface)] space-y-2">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
              Server Process
            </span>
            <Server className={`w-4 h-4 ${isRunning ? "text-green-500" : "text-red-500"}`} />
          </div>
          <div className="flex items-center gap-2">
            <span
              className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider ${
                isRunning
                  ? "bg-green-100 text-green-800 dark:bg-green-950/60 dark:text-green-300"
                  : "bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300"
              }`}
            >
              <span className={`w-2 h-2 rounded-full ${isRunning ? "bg-green-500 animate-ping" : "bg-red-500"}`} />
            {managesLocalReverb ? (isRunning ? "Running" : "Stopped") : `${status?.driver} hosted`}
            </span>
            {status?.pid && (
              <span className="text-xs font-mono font-bold text-[var(--color-text-secondary)]">
                PID: {status.pid}
              </span>
            )}
          </div>
        </div>

        {/* Port Status */}
        <div className="rounded-xl p-4 border border-[var(--color-border)] bg-[var(--color-surface)] space-y-2">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
              Port & Host
            </span>
            <Activity className="w-4 h-4 text-[var(--color-accent)]" />
          </div>
          <p className="text-base font-extrabold font-mono text-[var(--color-text)]">
            {status?.host}:{status?.port}
          </p>
          <span className="text-[11px] text-[var(--color-text-secondary)] block">
            Protocol: {status?.scheme.toUpperCase()} (WS Proxy)
          </span>
        </div>

        {/* WebSocket Connection State (Client) */}
        <div className="rounded-xl p-4 border border-[var(--color-border)] bg-[var(--color-surface)] space-y-2">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
              Client Echo State
            </span>
            <Radio className={`w-4 h-4 ${wsState === "connected" ? "text-green-500" : wsState === "unavailable" || wsState === "disconnected" ? "text-red-500" : "text-amber-500"}`} />
          </div>
          <div className="flex items-center gap-2">
            <span
              className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold capitalize ${
                wsState === "connected"
                  ? "bg-green-100 text-green-800 dark:bg-green-950/60 dark:text-green-300"
                  : wsState === "unavailable" || wsState === "disconnected"
                  ? "bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300"
                  : wsState === "connecting" || wsState === "reconnecting" || wsState === "initializing"
                  ? "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                  : "bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300"
              }`}
            >
              <span
                className={`w-1.5 h-1.5 rounded-full ${
                  wsState === "connected" ? "bg-green-500" : wsState === "unavailable" || wsState === "disconnected" ? "bg-red-500" : "bg-amber-500"
                }`}
              />
              {wsState.replaceAll("_", " ")}
            </span>
          </div>
          <span className="text-[11px] text-[var(--color-text-secondary)] block truncate">
              Channel: private-reverb.monitor
          </span>
          <span className={`text-[11px] block ${channelAuthState === "authorized" ? "text-green-600 dark:text-green-400" : channelAuthState === "auth_failed" ? "text-red-600 dark:text-red-400" : "text-[var(--color-text-secondary)]"}`}>
            Private channel: {channelAuthState.replaceAll("_", " ")}
          </span>
        </div>

        {/* Process Telemetry */}
        <div className="rounded-xl p-4 border border-[var(--color-border)] bg-[var(--color-surface)] space-y-2">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-secondary)]">
              Process Resource
            </span>
            <Cpu className="w-4 h-4 text-[var(--brand-gold)]" />
          </div>
          <p className="text-base font-extrabold font-mono text-[var(--color-text)]">
            {status?.memory || "—"}
          </p>
          <span className="text-[11px] text-[var(--color-text-secondary)] block truncate">
            {status?.uptime ? `Started: ${new Date(status.uptime).toLocaleTimeString()}` : "Process inactive"}
          </span>
        </div>
      </div>

      {/* ── Operational Control Center ── */}
      <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 sm:p-6 space-y-4 shadow-sm">
        <div className="flex items-center justify-between border-b border-[var(--color-border)] pb-4 flex-wrap gap-2">
          <div>
            <h2 className="text-base font-extrabold text-[var(--color-text)] flex items-center gap-2">
              <Power className="w-5 h-5 text-[var(--color-accent)]" />
              Reverb Process Control
            </h2>
            <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">
              Execute lifecycle actions on the background Reverb server instance
            </p>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-xs font-mono text-[var(--color-text-secondary)]">
              App ID: {status?.app_id}
            </span>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          {/* Start Button */}
          <button
            type="button"
            onClick={handleStart}
            disabled={!managesLocalReverb || isRunning || actionLoading !== null}
            className="flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-green-600 hover:bg-green-700 disabled:opacity-40 disabled:cursor-not-allowed transition-all min-h-[44px] shadow-sm"
          >
            <Play className={`w-4 h-4 ${actionLoading === "start" ? "animate-spin" : ""}`} />
            {actionLoading === "start" ? "Starting Reverb..." : "Start Reverb Server"}
          </button>

          {/* Stop Button */}
          <button
            type="button"
            onClick={handleStop}
            disabled={!managesLocalReverb || !isRunning || actionLoading !== null}
            className="flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-red-600 hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed transition-all min-h-[44px] shadow-sm"
          >
            <Square className={`w-4 h-4 ${actionLoading === "stop" ? "animate-spin" : ""}`} />
            {actionLoading === "stop" ? "Stopping Reverb..." : "Stop Reverb Server"}
          </button>

          {/* Restart Button */}
          <button
            type="button"
            onClick={handleRestart}
            disabled={!managesLocalReverb || !isRunning || actionLoading !== null}
            className="flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold border border-[var(--color-border)] text-[var(--color-text)] hover:bg-[var(--color-surface-secondary)] disabled:opacity-40 disabled:cursor-not-allowed transition-all min-h-[44px]"
          >
            <RefreshCw className={`w-4 h-4 ${actionLoading === "restart" ? "animate-spin" : ""}`} />
            {actionLoading === "restart" ? "Restarting..." : "Restart Server"}
          </button>

          {/* Test Ping Broadcast */}
          <button
            type="button"
            onClick={handleTestPing}
            disabled={!isRunning || actionLoading !== null}
            className="flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed min-h-[44px] ml-auto shadow-sm"
            style={{ background: "var(--color-accent)" }}
          >
            <Zap className={`w-4 h-4 ${actionLoading === "ping" ? "animate-bounce" : ""}`} />
            {actionLoading === "ping" ? "Dispatching..." : "Send Test Broadcast Ping"}
          </button>
        </div>

        {/* Reliability Note */}
        <div className="p-3 rounded-xl bg-blue-50/10 border border-blue-500/20 text-xs text-[var(--color-text-secondary)] flex items-start gap-2.5 mt-2">
          <Terminal className="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
          <div>
            <p className="font-semibold text-[var(--color-text)]">
              ServBay & Windows Daemon Reliability
            </p>
            <p className="mt-0.5">
              The server uses the configured port {status?.port ?? 8080}. The watchdog script is available at{" "}
              <code className="text-xs font-mono font-bold text-[var(--color-accent)]">
                backend/scripts/reverb-daemon.ps1
              </code>{" "}
              and only manages processes verified as this project's Reverb server.
            </p>
          </div>
        </div>
      </div>

      {/* ── Real-Time Broadcast Stream Monitor ── */}
      <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 sm:p-6 space-y-4 shadow-sm">
        <div className="flex items-center justify-between border-b border-[var(--color-border)] pb-3 flex-wrap gap-2">
          <div className="flex items-center gap-2">
            <Activity className="w-5 h-5 text-[var(--color-accent)]" />
            <h2 className="text-base font-extrabold text-[var(--color-text)]">
              Live Broadcast Event Stream
            </h2>
            <span className="px-2 py-0.5 rounded-full text-xs font-extrabold bg-[var(--color-surface-secondary)] text-[var(--color-accent)]">
              {events.length} captured
            </span>
          </div>

          <div className="flex items-center gap-2">
            {events.length > 0 && (
              <button
                type="button"
                onClick={() => setEvents([])}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-danger)] hover:bg-red-50/10 transition-colors"
              >
                <Trash2 className="w-3.5 h-3.5" />
                Clear Stream
              </button>
            )}
          </div>
        </div>

        {events.length === 0 ? (
          <div className="text-center py-12 border border-dashed border-[var(--color-border)] rounded-xl bg-[var(--color-surface-secondary)]/20 space-y-2">
            <Radio className="w-8 h-8 mx-auto text-[var(--color-text-secondary)] opacity-40 animate-pulse" />
            <p className="text-sm font-bold text-[var(--color-text)]">Listening for WebSocket Broadcasts</p>
            <p className="text-xs text-[var(--color-text-secondary)] max-w-sm mx-auto">
              Click &quot;Send Test Broadcast Ping&quot; above or perform an order status transition to observe incoming real-time telemetry events.
            </p>
          </div>
        ) : (
          <div className="space-y-2.5 max-h-[380px] overflow-y-auto pr-1">
            {events.map((ev) => (
              <div
                key={ev.id}
                className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-secondary)]/40 p-3.5 text-xs space-y-2 font-mono"
              >
                <div className="flex items-center justify-between font-sans flex-wrap gap-2">
                  <div className="flex items-center gap-2">
                    <span className="px-2 py-0.5 rounded font-black text-[11px] bg-[var(--color-accent)] text-white">
                      {ev.event}
                    </span>
                    <span className="text-[var(--color-text-secondary)] text-xs font-medium">
                      channel: {ev.channel}
                    </span>
                  </div>

                  <div className="flex items-center gap-2">
                    {ev.latencyMs !== undefined && (
                      <span className="px-2 py-0.5 rounded text-[11px] font-bold bg-green-100 text-green-800 dark:bg-green-950/60 dark:text-green-300">
                        {ev.latencyMs} ms
                      </span>
                    )}
                    <span className="text-[11px] text-[var(--color-text-secondary)] flex items-center gap-1 font-sans">
                      <Clock className="w-3 h-3" />
                      {ev.timestamp}
                    </span>
                  </div>
                </div>

                <div className="p-2 rounded bg-black/5 dark:bg-black/30 overflow-x-auto text-[11px] text-[var(--color-text)]">
                  <pre>{JSON.stringify(ev.data, null, 2)}</pre>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
