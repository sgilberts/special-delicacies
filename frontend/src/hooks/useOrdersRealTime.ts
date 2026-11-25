import { useEffect, useRef } from 'react';
import echo from '@/lib/echo';
import { invalidateCachedGets } from '@/lib/api';
import type { DineInOrder } from '@/types/api';

type OrderEventPayload = {
  order: DineInOrder;
};

type EventHandler = (order: DineInOrder) => void;

interface UseOrdersRealTimeOptions {
  channel: 'orders.waiter' | 'orders.kitchen';
  onOrderPlaced?: EventHandler;
  onOrderConfirmed?: EventHandler;
  onOrderPreparing?: EventHandler;
  onOrderReady?: EventHandler;
  onOrderDelivered?: EventHandler;
  onOrderCompleted?: EventHandler;
  onOrderCancelled?: EventHandler;
}

export function useOrdersRealTime({
  channel,
  onOrderPlaced,
  onOrderConfirmed,
  onOrderPreparing,
  onOrderReady,
  onOrderDelivered,
  onOrderCompleted,
  onOrderCancelled,
}: UseOrdersRealTimeOptions) {
  // Keep stable refs so we don't re-subscribe on every render
  const handlers = useRef({
    onOrderPlaced,
    onOrderConfirmed,
    onOrderPreparing,
    onOrderReady,
    onOrderDelivered,
    onOrderCompleted,
    onOrderCancelled,
  });
  handlers.current = {
    onOrderPlaced,
    onOrderConfirmed,
    onOrderPreparing,
    onOrderReady,
    onOrderDelivered,
    onOrderCompleted,
    onOrderCancelled,
  };

  useEffect(() => {
    const ch = echo.private(channel);

    const wrap = (fn: keyof typeof handlers.current) => (payload: OrderEventPayload) => {
      invalidateCachedGets();
      handlers.current[fn]?.(payload.order);
    };

    ch.listen('.order.placed', wrap('onOrderPlaced'));
    ch.listen('.order.confirmed', wrap('onOrderConfirmed'));
    ch.listen('.order.preparing', wrap('onOrderPreparing'));
    ch.listen('.order.ready', wrap('onOrderReady'));
    ch.listen('.order.delivered', wrap('onOrderDelivered'));
    ch.listen('.order.completed', wrap('onOrderCompleted'));
    ch.listen('.order.cancelled', wrap('onOrderCancelled'));

    return () => {
      echo.leave(channel);
    };
  // channel is intentionally the only dep — handlers are via ref
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel]);
}
