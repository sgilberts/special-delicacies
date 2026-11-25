<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\GatewayPaymentService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected GatewayPaymentService $paymentService
    ) {}

    /**
     * List orders with optional filtering by order_type, status, statuses, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['items', 'statusHistories', 'table', 'waiter', 'kitchenStaff', 'paymentGateway:id,display_name,provider']);

        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin')) {
            if ($user->hasRole('delivery-staff')) {
                // Delivery staff can only access delivery orders assigned to them or unassigned ready tickets
                $query->where('order_type', Order::TYPE_DELIVERY)
                      ->where(function ($q) use ($user) {
                          $q->where('delivery_staff_id', $user->id)
                            ->orWhere(function ($q2) {
                                $q2->whereNull('delivery_staff_id')
                                   ->where('status', Order::STATUS_READY);
                            });
                      });
            }
        }

        if ($request->has('order_type')) {
            $query->where('order_type', $request->input('order_type'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        } elseif ($request->has('statuses')) {
            $statuses = array_filter(explode(',', (string) $request->input('statuses')));
            if (! empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($request->has('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhereHas('table', function ($tq) use ($search) {
                      $tq->where('table_number', 'like', "%{$search}%");
                  });
            });
        }

        $orders = $query->latest('created_at')->paginate($request->integer('per_page', 50));
        $orders->getCollection()->each(function (Order $order) {
            $order->setAttribute('payment_details', $order->paymentDetails());
        });

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Store a newly created order.
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        try {
            if ($request->validated('payment_method') === Order::PAYMENT_METHOD_GATEWAY
                && ! $this->paymentService->configuredGateway()) {
                return response()->json(['success' => false, 'message' => 'Online payment is currently unavailable. Please choose cash on delivery.'], 422);
            }

            $order = $this->orderService->createOrder(
                $request->validated(),
                $request->user()
            );

            $order->load(['items', 'table', 'statusHistories']);
            $checkoutUrl = null;
            if ($order->payment_method === Order::PAYMENT_METHOD_GATEWAY && $order->payment_status !== Order::PAYMENT_STATUS_PAID) {
                $checkoutUrl = $this->paymentService->initialize($order);
            }

            $status = $order->wasRecentlyCreated ? 201 : 200;
            $message = $order->wasRecentlyCreated
                ? 'Order placed successfully.'
                : 'Order already processed (idempotent submission).';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => array_merge($order->toArray(), $checkoutUrl ? ['payment_checkout_url' => $checkoutUrl] : []),
            ], $status);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Display the specified order.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $order = Order::with(['items', 'statusHistories', 'table', 'deliveryZone', 'paymentGateway:id,display_name,provider'])->find($id);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin')) {
            if ($user->hasRole('delivery-staff')) {
                // Cannot view non-delivery orders
                if ($order->order_type !== Order::TYPE_DELIVERY) {
                    return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
                }
                // Cannot view other rider's assigned orders
                if ($order->delivery_staff_id && $order->delivery_staff_id !== $user->id) {
                    return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
                }
                // Unassigned orders can only be viewed if ready for pickup
                if (! $order->delivery_staff_id && $order->status !== Order::STATUS_READY) {
                    return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
                }
            }
        }

        $order->setAttribute('payment_details', $order->paymentDetails());
        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Update the operational status of an order.
     */
    public function updateStatus(int $id, UpdateOrderStatusRequest $request): JsonResponse
    {
        $order = Order::find($id);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        try {
            $updated = $this->orderService->updateStatus(
                $order,
                $request->validated('status'),
                $request->user(),
                $request->validated('note')
            );

            return response()->json([
                'success' => true,
                'message' => "Order status updated to '{$updated->status}'.",
                'data' => $updated,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
