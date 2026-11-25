<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AuditService;
use App\Services\OrderService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryOrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected AuditService $auditService
    ) {}

    /**
     * List delivery orders for the authenticated delivery staff member.
     * Shows orders that are assigned to them or are unassigned and ready.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $query = Order::with(['items', 'deliveryZone', 'statusHistories', 'paymentGateway:id,display_name,provider'])
            ->where('order_type', Order::TYPE_DELIVERY);

        // Filter by what delivery staff can see
        if ($user->hasRole('super-admin')) {
            // Admins see all delivery orders
        } elseif ($user->hasRole('delivery-staff') || $user->hasPermission('delivery.orders.view')) {
            // Delivery staff see: orders assigned to them OR unassigned ready orders
            $query->where(function ($q) use ($user) {
                $q->where('delivery_staff_id', $user->id)
                  ->orWhere(function ($q2) {
                      $q2->whereNull('delivery_staff_id')
                         ->where('status', Order::STATUS_READY);
                  });
            });
        } else {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        // Filter by status if requested
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        } elseif ($request->has('statuses')) {
            $statuses = array_filter(explode(',', (string) $request->input('statuses')));
            if (! empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        $orders = $query->latest('updated_at')->paginate($request->integer('per_page', 50));
        $orders->getCollection()->each(function (Order $order) {
            $order->setAttribute('payment_details', $order->paymentDetails());
        });

        return response()->json([
            'success' => true,
            'data'    => $orders->items(),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    /**
     * Self-assign a ready delivery order to the authenticated delivery staff member.
     * Uses a row-level lock to prevent concurrent assignment races.
     */
    public function assign(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if (! $user->hasRole('super-admin') && ! $user->hasPermission('delivery.orders.assign')) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        try {
            $order = DB::transaction(function () use ($id, $user) {
                // Lock row to prevent concurrent assignment
                $order = Order::lockForUpdate()
                    ->where('id', $id)
                    ->where('order_type', Order::TYPE_DELIVERY)
                    ->first();

                if (! $order) {
                    throw new DomainException('Delivery order not found.');
                }

                if (! in_array($order->status, [Order::STATUS_READY, Order::STATUS_CONFIRMED, Order::STATUS_PREPARING], true)) {
                    throw new DomainException("Order cannot be assigned in its current status '{$order->status}'.");
                }

                if ($order->delivery_staff_id && $order->delivery_staff_id !== $user->id) {
                    throw new DomainException('This order has already been assigned to another delivery staff member.');
                }

                $order->update(['delivery_staff_id' => $user->id]);

                return $order->fresh(['items', 'deliveryZone', 'statusHistories']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Order assigned to you successfully.',
                'data'    => $order,
            ]);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Update the status of a delivery order (dispatch / deliver / cancel).
     */
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'status'         => 'required|string|in:out_for_delivery,delivered,completed,cancelled',
            'cash_collected' => 'nullable|boolean',
            'note'           => 'nullable|string|max:500',
        ]);

        $order = Order::where('id', $id)
            ->where('order_type', Order::TYPE_DELIVERY)
            ->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Delivery order not found.'], 404);
        }

        // Delivery staff can only act on orders assigned to them (unless admin)
        if (! $user->hasRole('super-admin')) {
            if ($order->delivery_staff_id && $order->delivery_staff_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'You can only update orders assigned to you.'], 403);
            }
        }

        try {
            $updated = $this->orderService->updateStatus(
                $order,
                $validated['status'],
                $user,
                $validated['note'] ?? null
            );

            // If cash collection was explicitly confirmed during delivery transition, record it
            if (! empty($validated['cash_collected'])) {
                $wasPending = $updated->payment_status !== Order::PAYMENT_STATUS_PAID;
                $updated = $this->orderService->confirmPayment($updated, $user, $validated['note'] ?? null);
                if ($wasPending) $this->auditCashConfirmation($updated, $user, $request);
            }

            return response()->json([
                'success' => true,
                'message' => "Order status updated to '{$updated->status}'.",
                'data'    => $updated,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Explicitly confirm cash payment collected for a delivery order.
     */
    public function confirmPayment(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $order = Order::where('id', $id)
            ->where('order_type', Order::TYPE_DELIVERY)
            ->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Delivery order not found.'], 404);
        }

        if (! $user->hasRole('super-admin') && $order->delivery_staff_id && $order->delivery_staff_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'You can only confirm payment for orders assigned to you.'], 403);
        }

        try {
            $wasPending = $order->payment_status !== Order::PAYMENT_STATUS_PAID;
            $updated = $this->orderService->confirmPayment(
                $order,
                $user,
                $request->input('note')
            );
            if ($wasPending) $this->auditCashConfirmation($updated, $user, $request);

            return response()->json([
                'success' => true,
                'message' => 'Cash payment confirmed collected successfully.',
                'data'    => $updated,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function auditCashConfirmation(Order $order, \App\Models\User $user, Request $request): void
    {
        $this->auditService->log(
            $user,
            'financial.cash_payment_confirmed',
            "Cash payment collected for order {$order->order_number}.",
            $order,
            ['payment_status' => Order::PAYMENT_STATUS_PENDING],
            ['payment_status' => Order::PAYMENT_STATUS_PAID, 'amount' => (string) $order->total, 'currency' => 'GHS'],
            $request
        );
    }

    /**
     * Show a single delivery order.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $order = Order::with(['items', 'deliveryZone', 'statusHistories', 'deliveryStaff', 'paymentGateway:id,display_name,provider'])
            ->where('id', $id)
            ->where('order_type', Order::TYPE_DELIVERY)
            ->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Delivery order not found.'], 404);
        }

        // Delivery staff can only view their own orders or unassigned ready orders
        if (! $user->hasRole('super-admin')) {
            if ($order->delivery_staff_id && $order->delivery_staff_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
            }
            if (! $order->delivery_staff_id && $order->status !== Order::STATUS_READY) {
                return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
            }
        }

        $order->setAttribute('payment_details', $order->paymentDetails());
        return response()->json(['success' => true, 'data' => $order]);
    }
}
