<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AuditService;
use App\Services\OrderService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected AuditService $auditService
    ) {}

    /**
     * Comprehensive administrative order listing with rich filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with([
            'items',
            'statusHistories.user:id,name',
            'table',
            'deliveryZone',
            'waiter:id,name',
            'kitchenStaff:id,name',
            'deliveryStaff:id,name',
            'paymentGateway:id,display_name,provider',
            'latestPaymentTransaction:id,order_id,status,currency,verified_at,received_at',
        ]);

        // Search filter
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhereHas('table', function ($tq) use ($search) {
                      $tq->where('table_number', 'like', "%{$search}%");
                  });
            });
        }

        // Order Type filter
        if ($request->filled('order_type')) {
            $query->where('order_type', $request->input('order_type'));
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } elseif ($request->filled('statuses')) {
            $statuses = array_filter(explode(',', (string) $request->input('statuses')));
            if (! empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        // Payment status filter
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->input('payment_status'));
        }

        // Awaiting cash confirmation filter
        if ($request->boolean('awaiting_payment')) {
            $query->where('status', Order::STATUS_DELIVERED)
                  ->where('payment_status', Order::PAYMENT_STATUS_PENDING);
        }

        // Date range filters
        if ($request->filled('from_date')) {
            $from = Carbon::parse($request->input('from_date'))->startOfDay();
            $query->where('created_at', '>=', $from);
        }

        if ($request->filled('to_date')) {
            $to = Carbon::parse($request->input('to_date'))->endOfDay();
            $query->where('created_at', '<=', $to);
        }

        $orders = $query->latest('created_at')->paginate($request->integer('per_page', 20));
        $orders->getCollection()->each(function (Order $order) {
            $order->setAttribute('payment_details', $order->paymentDetails());
        });
        $this->redactFinancialData($orders->getCollection(), $request);

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
     * Show detailed order information.
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::with([
            'items',
            'statusHistories.user:id,name',
            'table',
            'deliveryZone',
            'waiter:id,name',
            'kitchenStaff:id,name',
            'deliveryStaff:id,name',
            'paymentGateway:id,display_name,provider',
            'latestPaymentTransaction:id,order_id,status,currency,verified_at,received_at',
        ])->find($id);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $order->setAttribute('payment_details', $order->paymentDetails());
        $this->redactFinancialData(collect([$order]), request());

        return response()->json([
            'success' => true,
            'data'    => $order,
        ]);
    }

    /**
     * Administratively confirm cash payment for an order.
     */
    public function confirmPayment(int $id, Request $request): JsonResponse
    {
        $order = Order::find($id);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        try {
            $currentUser = $request->user();
            $note = $request->input('note', 'Cash payment confirmed by Super Admin.');

            $updated = $this->orderService->confirmPayment($order, $currentUser, $note);

            $this->auditService->log(
                $currentUser,
                'order.confirm_payment',
                "Admin confirmed cash payment for order {$order->order_number} (GH₵ {$order->total}).",
                $order,
                ['payment_status' => Order::PAYMENT_STATUS_PENDING],
                ['payment_status' => Order::PAYMENT_STATUS_PAID],
                $request
            );

            return response()->json([
                'success' => true,
                'message' => "Payment for order {$order->order_number} confirmed successfully.",
                'data'    => $updated,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Update order status following the approved state machine.
     */
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $order = Order::find($id);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $currentUser = $request->user();
            $previousStatus = $order->status;

            $updated = $this->orderService->updateStatus(
                $order,
                $validated['status'],
                $currentUser,
                $validated['note'] ?? null
            );

            $this->auditService->log(
                $currentUser,
                'order.status_transition',
                "Admin transitioned order {$order->order_number} from {$previousStatus} to {$validated['status']}.",
                $order,
                ['status' => $previousStatus],
                ['status' => $validated['status']],
                $request
            );

            return response()->json([
                'success' => true,
                'message' => "Order {$order->order_number} status updated to {$validated['status']}.",
                'data'    => $updated,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function redactFinancialData(\Illuminate\Support\Collection $orders, Request $request): void
    {
        if ($request->user()?->hasPermission('financials.transactions.view')) return;
        $orders->each(function (Order $order): void {
            $order->setAttribute('payment_details', ['method' => $order->payment_method, 'status' => $order->payment_status]);
            $order->makeHidden(['subtotal', 'delivery_fee', 'total', 'payment_reference', 'gateway_checkout_url']);
            $order->items->each(fn ($item) => $item->makeHidden(['unit_price', 'line_total']));
        });
    }
}
