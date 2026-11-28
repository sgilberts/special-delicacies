import { useEffect, useRef, useState } from "react";
import echo from "@/lib/echo";

export type RealtimeState = "connecting" | "connected" | "disconnected";

interface PrivateChannelOptions {
  /** Private channel name, e.g. "customers" or "organisations". */
  channel: string;
  /** Echo event name (".customer.changed") → handler. */
  events: Record<string, (payload: Record<string, unknown>) => void>;
  /** Called after the socket reconnects so the page can reconcile missed events. */
  onReconnect?: () => void;
  enabled?: boolean;
}

const MAX_SEEN_EVENTS = 500;

/**
 * Subscribe to one private admin channel.
 *
 * - Duplicate deliveries are dropped by the server-issued `event_id`.
 * - A reconnect clears the de-duplication window and asks the page to refetch,
 *   so events missed while the socket was down are not silently lost.
 * - The returned state lets a page show its connection badge and fall back to a
 *   slow refresh while the socket is unavailable.
 */
export function usePrivateChannel({ channel, events, onReconnect, enabled = true }: PrivateChannelOptions) {
  const [state, setState] = useState<RealtimeState>("connecting");
  const [lastEventAt, setLastEventAt] = useState<number | null>(null);
  const handlers = useRef(events);
  handlers.current = events;
  const reconnectHandler = useRef(onReconnect);
  reconnectHandler.current = onReconnect;
  const seen = useRef<Set<string>>(new Set());

  useEffect(() => {
    if (!enabled) return;

    const subscription = echo.private(channel);
    const listeners = Object.entries(handlers.current).map(([name]) => {
      const listener = (payload: Record<string, unknown>) => {
        const id = typeof payload?.event_id === "string" ? payload.event_id : null;
        if (id !== null) {
          if (seen.current.has(id)) return;
          seen.current.add(id);
          if (seen.current.size > MAX_SEEN_EVENTS) {
            const oldest = seen.current.values().next().value;
            if (oldest !== undefined) seen.current.delete(oldest);
          }
        }
        setLastEventAt(Date.now());
        handlers.current[name]?.(payload);
      };
      subscription.listen(name, listener);
      return [name, listener] as const;
    });

    return () => {
      listeners.forEach(([name, listener]) => subscription.stopListening(name, listener));
      echo.leave(channel);
    };
  }, [channel, enabled]);

  useEffect(() => {
    if (!enabled) return;

    const connection = (echo.connector as unknown as {
      pusher?: {
        connection?: {
          state: string;
          bind: (event: string, callback: (state: { previous: string; current: string }) => void) => void;
          unbind: (event: string, callback: (state: { previous: string; current: string }) => void) => void;
        };
      };
    }).pusher?.connection;

    if (!connection) {
      setState("disconnected");
      return;
    }

    setState(connection.state === "connected" ? "connected" : connection.state === "connecting" ? "connecting" : "disconnected");

    const onChange = (next: { previous: string; current: string }) => {
      setState(next.current === "connected" ? "connected" : next.current === "connecting" ? "connecting" : "disconnected");
      if (next.previous === "disconnected" && next.current === "connected") {
        // Events may have been missed while offline: reconcile with the server.
        seen.current.clear();
        reconnectHandler.current?.();
      }
    };

    connection.bind("state_change", onChange);
    return () => connection.unbind("state_change", onChange);
  }, [channel, enabled]);

  return { state, lastEventAt };
}
