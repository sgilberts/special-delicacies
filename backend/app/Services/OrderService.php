<?php

namespace App\Services;

use App\Events\OrderTrackingUpdated;
use App\Models\DeliveryZone;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\RestaurantTable;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderService
{
    /**
     * Generate an unpredictable, unique order number.
     */
    public static function generateOrderNumber(): string
    {
        return sprintf(
            'SD-%s-%s',
            date('Ymd'),
            strtoupper(bin2hex(random_bytes(8)))
        );
    }

    /**
     * Create an order with transactional database integrity, server-side price validation,
     * historical data snapshots, and duplicate-order protection.
     *
     * @param  array{
     *     idempotency_key?: ?string,
     *     order_type: string,
     *     customer_name: string,
     *     customer_phone: string,
     *     restaurant_table_id?: ?int,
     *     delivery_zone_id?: ?int,
     *     delivery_address?: ?string,
     *     customer_notes?: ?string,
     *     items: array<int, array{menu_item_id: int, quantity: int, special_instructions?: ?string}>
     * }  $data
     * @throws DomainException|InvalidArgumentException
     */
    public function createOrder(array $data, ?User $creator = null): Order
    {
        $orderType = $data['order_type'] ?? null;
        if (! in_array($orderType, [Order::TYPE_DINE_IN, Order::TYPE_DELIVERY], true)) {
            throw new InvalidArgumentException("Invalid order type: {$orderType}");
        }

        if (empty($data['items']) || ! is_array($data['items'])) {
            throw new InvalidArgumentException('An order must contain at least one item.');
        }

        // Validate table for dine-in
        $tableId = null;
        if ($orderType === Order::TYPE_DINE_IN) {
            if (! empty($data['qr_token'])) {
                $table = app(QrCodeService::class)->resolveTableFromToken($data['qr_token']);
                if (! $table || ! $table->is_active) {
                    throw new DomainException('The provided QR code token is invalid, revoked, or the table is inactive.');
                }
                $tableId = $table->id;
                $data['restaurant_table_id'] = $table->id;
            } else {
                $tableId = $data['restaurant_table_id'] ?? null;
                if (! $tableId) {
                    throw new InvalidArgumentException('Dine-in orders require a valid restaurant table.');
                }
                $table = RestaurantTable::find($tableId);
                if (! $table || ! $table->is_active) {
                    throw new DomainException('The selected restaurant table is not active or does not exist.');
                }
            }
        }

        // Validate delivery zone & address for delivery
        $zoneId = null;
        $deliveryFee = '0.00';
        if ($orderType === Order::TYPE_DELIVERY) {
            $zoneId = $data['delivery_zone_id'] ?? null;
            if (! $zoneId) {
                throw new InvalidArgumentException('Delivery orders require a delivery zone.');
            }
            $zone = DeliveryZone::find($zoneId);
            if (! $zone || ! $zone->is_active) {
                throw new DomainException('The selected delivery zone is not active or does not exist.');
            }
            if (empty($data['delivery_address'])) {
                throw new InvalidArgumentException('Delivery orders require a delivery address.');
            }
            $deliveryFee = number_format((float) $zone->delivery_fee, 2, '.', '');
        }

        // 1. Resolve explicit idempotency key
        $explicitKey = ! empty($data['idempotency_key']) ? trim($data['idempotency_key']) : null;

        // If an explicit key is provided, it is authoritative for network retries and deduplication
        if ($explicitKey) {
            $existingOrder = Order::where('idempotency_key', $explicitKey)
                ->with(['items', 'statusHistories', 'table', 'deliveryZone'])
                ->first();

            if ($existingOrder) {
                return $existingOrder;
            }

            $lockKey = 'order_idemp_' . hash('sha256', $explicitKey);
        } else {
            // Fallback for clients without explicit key:
            // Use short-lived fingerprint lock to prevent double-click race conditions
            $lockKey = 'order_lock_' . hash('sha256', implode('|', [
                $orderType,
                trim($data['customer_phone']),
                $tableId ?? $zoneId,
                json_encode($data['items']),
            ]));
        }

        // 2. Prevent concurrent duplicate submissions via atomic mutex lock
        $lock = Cache::lock($lockKey, 10);

        try {
            $lock->block(3);

            // Re-check explicit key after acquiring lock in case concurrent request completed
            if ($explicitKey) {
                $existingOrder = Order::where('idempotency_key', $explicitKey)
                    ->with(['items', 'statusHistories', 'table', 'deliveryZone'])
                    ->first();

                if ($existingOrder) {
                    return $existingOrder;
                }
            } else {
                // Fallback check for clients WITHOUT an explicit key:
                // Deduplicate only rapid double-click submissions (within 15 seconds)
                // to prevent merging legitimate subsequent identical orders
                $recentDuplicate = Order::where('customer_phone', $data['customer_phone'])
                    ->where('order_type', $orderType)
                    ->where(function ($q) use ($tableId, $zoneId) {
                        if ($tableId) {
                            $q->where('restaurant_table_id', $tableId);
                        }
                        if ($zoneId) {
                            $q->where('delivery_zone_id', $zoneId);
                        }
                    })
                    ->where('status', Order::STATUS_PENDING)
                    ->where('created_at', '>=', now()->subSeconds(15))
                    ->with(['items', 'statusHistories', 'table', 'deliveryZone'])
                    ->first();

                if ($recentDuplicate && $this->itemsMatch($recentDuplicate, $data['items'])) {
                    return $recentDuplicate;
                }
            }

            // 3. Fetch and validate menu items from database - NEVER trust client prices
            $itemIds = array_column($data['items'], 'menu_item_id');
            $menuItems = MenuItem::whereIn('id', $itemIds)->get()->keyBy('id');

            $processedItems = [];
            $subtotal = 0.00;

            foreach ($data['items'] as $itemData) {
                $menuItemId = $itemData['menu_item_id'] ?? null;
                $quantity = (int) ($itemData['quantity'] ?? 0);

                if ($quantity <= 0) {
                    throw new InvalidArgumentException('Item quantity must be greater than zero.');
                }

                /** @var ?MenuItem $menuItem */
                $menuItem = $menuItems->get($menuItemId);

                if (! $menuItem) {
                    throw new DomainException("Menu item with ID {$menuItemId} not found.");
                }

                if (! $menuItem->is_available) {
                    throw new DomainException("Menu item '{$menuItem->name}' is currently unavailable.");
                }

                // Trust only the database price
                $unitPrice = (float) $menuItem->price;
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $processedItems[] = [
                    'menu_item_id' => $menuItem->id,
                    'item_name' => $menuItem->name,
                    'unit_price' => number_format($unitPrice, 2, '.', ''),
                    'quantity' => $quantity,
                    'special_instructions' => $itemData['special_instructions'] ?? null,
                    'line_total' => number_format($lineTotal, 2, '.', ''),
                ];
            }

            $formattedSubtotal = number_format($subtotal, 2, '.', '');
            $includeDeliveryFee = $orderType !== Order::TYPE_DELIVERY
                || filter_var($data['include_delivery_fee'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $total = number_format($subtotal + ($includeDeliveryFee ? (float) $deliveryFee : 0), 2, '.', '');

            $createdOrder = DB::transaction(function () use (
                $data,
                $explicitKey,
                $orderType,
                $tableId,
                $zoneId,
                $formattedSubtotal,
                $deliveryFee,
                $total,
                $processedItems,
                $creator
            ) {
                $order = Order::create([
                    'order_number'       => self::generateOrderNumber(),
                    'idempotency_key'    => $explicitKey,
                    'order_type'         => $orderType,
                    'restaurant_table_id'=> $tableId,
                    'delivery_zone_id'   => $zoneId,
                    'customer_name'      => $data['customer_name'],
                    'customer_phone'     => $data['customer_phone'],
                    'customer_email'     => $data['customer_email'] ?? null,
                    'delivery_address'   => $data['delivery_address'] ?? null,
                    'customer_notes'     => $data['customer_notes'] ?? null,
                    'status'             => Order::STATUS_PENDING,
                    'subtotal'           => $formattedSubtotal,
                    'delivery_fee'       => $deliveryFee,
                    'total'              => $total,
                    'payment_method'     => ($data['payment_method'] ?? Order::PAYMENT_METHOD_CASH) === Order::PAYMENT_METHOD_GATEWAY
                        ? Order::PAYMENT_METHOD_GATEWAY
                        : Order::PAYMENT_METHOD_CASH,
                    'payment_status'     => Order::PAYMENT_STATUS_PENDING,
                ]);

                foreach ($processedItems as $item) {
                    $order->items()->create($item);
                }

                $order->statusHistories()->create([
                    'previous_status' => null,
                    'new_status' => Order::STATUS_PENDING,
                    'changed_by' => $creator?->id,
                    'note' => 'Order placed successfully.',
                ]);

                if ($order->payment_method === Order::PAYMENT_METHOD_CASH) {
                    app(PaymentTransactionService::class)->recordPending($order);
                }

                return $order->load(['items', 'statusHistories', 'table']);
            });

            // Broadcast real-time order placed event for authorized staff
            try {
                event(new \App\Events\OrderPlaced($createdOrder));
            } catch (\Throwable $e) {
                report($e);
            }

            return $createdOrder;
        } catch (QueryException $e) {
            // If duplicate key error caught on race condition with explicit key, gracefully return the existing order
            if ($explicitKey && (str_contains($e->getMessage(), 'idempotency_key') || $e->getCode() == 23000)) {
                $existing = Order::where('idempotency_key', $explicitKey)
                    ->with(['items', 'statusHistories', 'table', 'deliveryZone'])
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Check if an existing order's items match submitted items.
     */
    protected function itemsMatch(Order $order, array $submittedItems): bool
    {
        if ($order->items->count() !== count($submittedItems)) {
            return false;
        }

        $existing = $order->items->map(fn ($i) => ['id' => $i->menu_item_id, 'qty' => $i->quantity])
            ->sortBy('id')->values()->all();

        $submitted = collect($submittedItems)->map(fn ($i) => ['id' => (int) $i['menu_item_id'], 'qty' => (int) $i['quantity']])
            ->sortBy('id')->values()->all();

        return $existing === $submitted;
    }

    /**
     * Transition an order to a new operational status with validation, concurrency locking, and history tracking.
     *
     * @throws DomainException|\Illuminate\Auth\Access\AuthorizationException
     */
    public function updateStatus(Order $order, string $newStatus, ?User $user = null, ?string $note = null): Order
    {
        // 1. Authorize transition based on granular operational permissions
        $this->authorizeStatusTransition($newStatus, $user);

        $updated = DB::transaction(function () use ($order, $newStatus, $user, $note) {
            // Lock order row for update to prevent concurrent conflicting transitions
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->with(['items', 'table'])->findOrFail($order->id);

            if (! $lockedOrder->canTransitionTo($newStatus)) {
                throw new DomainException(
                    "Invalid status transition from '{$lockedOrder->status}' to '{$newStatus}' for {$lockedOrder->order_type} order."
                );
            }

            if ($lockedOrder->payment_method === Order::PAYMENT_METHOD_GATEWAY
                && $lockedOrder->payment_status !== Order::PAYMENT_STATUS_PAID
                && $newStatus !== Order::STATUS_CANCELLED) {
                throw new DomainException('This order must be paid online before it can be prepared or dispatched.');
            }

            $previousStatus = $lockedOrder->status;
            $updatePayload = ['status' => $newStatus];

            // Prevent completing delivery order if cash has not been collected
            if ($newStatus === Order::STATUS_COMPLETED && $lockedOrder->order_type === Order::TYPE_DELIVERY) {
                if ($lockedOrder->payment_status !== Order::PAYMENT_STATUS_PAID) {
                    throw new DomainException(
                        "Cannot complete delivery order #{$lockedOrder->order_number} before cash payment collection is confirmed."
                    );
                }
            }

            // Assign staff member based on transition
            if ($newStatus === Order::STATUS_CONFIRMED && ! $lockedOrder->waiter_id && $user) {
                $updatePayload['waiter_id'] = $user->id;
            } elseif ($newStatus === Order::STATUS_PREPARING && ! $lockedOrder->kitchen_staff_id && $user) {
                $updatePayload['kitchen_staff_id'] = $user->id;
            } elseif ($newStatus === Order::STATUS_OUT_FOR_DELIVERY && $lockedOrder->order_type === Order::TYPE_DELIVERY && $user) {
                // Assign delivery staff when dispatching
                if (! $lockedOrder->delivery_staff_id) {
                    $updatePayload['delivery_staff_id'] = $user->id;
                }
            } elseif ($newStatus === Order::STATUS_DELIVERED && $lockedOrder->order_type === Order::TYPE_DINE_IN && ! $lockedOrder->waiter_id && $user) {
                $updatePayload['waiter_id'] = $user->id;
            }
            // Note: For delivery orders, transitioning to 'delivered' does NOT automatically mark
            // payment as paid. Payment collection must be explicitly confirmed to prevent false revenue reporting.

            $lockedOrder->update($updatePayload);

            $lockedOrder->statusHistories()->create([
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'changed_by' => $user?->id,
                'note' => $note,
            ]);

            $refreshed = $lockedOrder->fresh(['items', 'statusHistories', 'table', 'deliveryZone', 'waiter', 'kitchenStaff', 'deliveryStaff']);

            // Broadcast real-time operational event based on new status
            $this->broadcastStatusEvent($refreshed, $previousStatus, $note);

            return $refreshed;
        });
        if ($newStatus === Order::STATUS_CANCELLED) {
            app(PaymentTransactionService::class)->cancelPending($updated);
        }
        event(new OrderTrackingUpdated($updated));
        return $updated;
    }

    /**
     * Authorize whether the user has permission to transition to the requested status.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorizeStatusTransition(string $newStatus, ?User $user): void
    {
        if (! $user) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Authentication required to perform status transition.');
        }

        if (! $user->is_active) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Inactive users cannot perform operational transitions.');
        }

        // Super Admin has full operational authority.
        if ($user->hasRole('super-admin')) {
            return;
        }

        // General orders.status.update permission grants access to all transitions (waiter/kitchen fallback)
        if ($user->hasPermission('orders.status.update')) {
            return;
        }

        if ($newStatus === Order::STATUS_DELIVERED) {
            if (! $user->hasPermission('orders.deliver') && ! $user->hasPermission('delivery.orders.deliver')) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    "You do not have permission to transition order to '{$newStatus}'."
                );
            }
            return;
        }

        // Check granular per-transition permission
        $requiredPermission = match ($newStatus) {
            Order::STATUS_CONFIRMED        => 'orders.confirm',
            Order::STATUS_PREPARING        => 'orders.prepare',
            Order::STATUS_READY            => 'orders.ready',
            Order::STATUS_OUT_FOR_DELIVERY => 'delivery.orders.dispatch',
            Order::STATUS_COMPLETED        => 'orders.complete',
            Order::STATUS_CANCELLED        => 'orders.cancel',
            default                        => null,
        };

        if ($requiredPermission === null || ! $user->hasPermission($requiredPermission)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                "You do not have permission to transition order to '{$newStatus}'."
            );
        }
    }

    /**
     * Broadcast the appropriate real-time event for an order status transition.
     */
    protected function broadcastStatusEvent(Order $order, string $previousStatus, ?string $note): void
    {
        try {
            $event = match ($order->status) {
                Order::STATUS_CONFIRMED        => new \App\Events\OrderConfirmed($order, $previousStatus, $note),
                Order::STATUS_PREPARING        => new \App\Events\OrderPreparing($order, $previousStatus, $note),
                Order::STATUS_READY            => new \App\Events\OrderReady($order, $previousStatus, $note),
                Order::STATUS_OUT_FOR_DELIVERY => new \App\Events\OrderOutForDelivery($order, $previousStatus, $note),
                Order::STATUS_DELIVERED        => new \App\Events\OrderDelivered($order, $previousStatus, $note),
                Order::STATUS_COMPLETED        => new \App\Events\OrderCompleted($order, $previousStatus, $note),
                Order::STATUS_CANCELLED        => new \App\Events\OrderCancelled($order, $previousStatus, $note),
                default                        => null,
            };

            if ($event) {
                event($event);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Explicitly confirm cash payment collection for an order.
     * Idempotent: Does not create duplicate records if already paid.
     * Audited: Adds a verified audit entry in order_status_histories.
     *
     * @throws DomainException|\Illuminate\Auth\Access\AuthorizationException
     */
    public function confirmPayment(Order $order, User $user, ?string $note = null): Order
    {
        if (! in_array($order->payment_method, [Order::PAYMENT_METHOD_CASH, 'pay_on_delivery'], true)) {
            throw new DomainException('Only a cash payment can be confirmed as cash collected.');
        }
        if (! $user->is_active) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Inactive users cannot confirm payment collection.');
        }

        // Authorize: Super Admin, assigned delivery staff, or staff with deliver/complete permission.
        $canConfirm = $user->hasRole('super-admin')
            || $user->hasPermission('delivery.orders.deliver')
            || $user->hasPermission('orders.complete')
            || $user->hasPermission('financials.payments.confirm_cash');

        if (! $canConfirm) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You do not have permission to confirm cash payment collection.');
        }

        // Delivery staff can only confirm cash for their own assigned order
        if (! $user->hasRole('super-admin') && $order->delivery_staff_id && $order->delivery_staff_id !== $user->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You can only confirm payment for orders assigned to you.');
        }

        $updated = DB::transaction(function () use ($order, $user, $note) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

            // Idempotent guard: if already paid, return without creating duplicate records
            if ($lockedOrder->payment_status === Order::PAYMENT_STATUS_PAID) {
                return $lockedOrder;
            }

            $lockedOrder->update(['payment_status' => Order::PAYMENT_STATUS_PAID]);

            $auditNote = $note ?? sprintf(
                'Cash payment of GH₵ %s confirmed collected by %s.',
                $lockedOrder->total,
                $user->name
            );

            $lockedOrder->statusHistories()->create([
                'previous_status' => $lockedOrder->status,
                'new_status'      => $lockedOrder->status,
                'changed_by'      => $user->id,
                'note'            => $auditNote,
            ]);

            $refreshed = $lockedOrder->fresh(['items', 'statusHistories', 'table', 'deliveryZone', 'waiter', 'kitchenStaff', 'deliveryStaff']);

            // Broadcast update
            $this->broadcastStatusEvent($refreshed, $lockedOrder->status, $auditNote);

            return $refreshed;
        });

        app(PaymentTransactionService::class)->confirmCash($updated, $user);

        return $updated->fresh(['items', 'statusHistories', 'table', 'deliveryZone', 'waiter', 'kitchenStaff', 'deliveryStaff']);
    }
}
