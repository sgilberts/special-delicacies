<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Get central restaurant operational dashboard statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $period = $request->query('period', 'today');
        $queryDate = Order::query();

        // Apply date range
        $now = Carbon::now();
        switch ($period) {
            case 'today':
                $queryDate->whereDate('created_at', $now->toDateString());
                $dateRange = ['from' => $now->copy()->startOfDay()->toDateTimeString(), 'to' => $now->copy()->endOfDay()->toDateTimeString()];
                break;
            case 'this_week':
                $startOfWeek = $now->copy()->startOfWeek();
                $queryDate->where('created_at', '>=', $startOfWeek);
                $dateRange = ['from' => $startOfWeek->toDateTimeString(), 'to' => $now->toDateTimeString()];
                break;
            case 'this_month':
                $startOfMonth = $now->copy()->startOfMonth();
                $queryDate->where('created_at', '>=', $startOfMonth);
                $dateRange = ['from' => $startOfMonth->toDateTimeString(), 'to' => $now->toDateTimeString()];
                break;
            case 'custom':
                if ($request->has('from_date') && $request->has('to_date')) {
                    $from = Carbon::parse($request->query('from_date'))->startOfDay();
                    $to = Carbon::parse($request->query('to_date'))->endOfDay();
                    $queryDate->whereBetween('created_at', [$from, $to]);
                    $dateRange = ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()];
                } else {
                    $dateRange = ['from' => null, 'to' => null];
                }
                break;
            case 'all_time':
            default:
                $dateRange = ['from' => null, 'to' => null];
                break;
        }

        // Clone base query for aggregations
        $baseQuery = clone $queryDate;

        // Order counts by status
        $statusCounts = (clone $baseQuery)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalOrders = (clone $baseQuery)->count();
        $pendingOrders = $statusCounts[Order::STATUS_PENDING] ?? 0;
        $confirmedOrders = $statusCounts[Order::STATUS_CONFIRMED] ?? 0;
        $preparingOrders = $statusCounts[Order::STATUS_PREPARING] ?? 0;
        $readyOrders = $statusCounts[Order::STATUS_READY] ?? 0;
        $outForDeliveryOrders = $statusCounts[Order::STATUS_OUT_FOR_DELIVERY] ?? 0;
        $deliveredOrders = $statusCounts[Order::STATUS_DELIVERED] ?? 0;
        $completedOrders = $statusCounts[Order::STATUS_COMPLETED] ?? 0;
        $cancelledOrders = $statusCounts[Order::STATUS_CANCELLED] ?? 0;

        // Total sales: strictly based on orders confirmed as paid, excluding cancelled orders
        $totalSales = (clone $baseQuery)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->where('payment_status', Order::PAYMENT_STATUS_PAID)
            ->sum('total');

        // Cash payments awaiting confirmation (delivered orders with pending payment)
        $cashAwaitingConfirmationOrders = (clone $baseQuery)
            ->where('status', Order::STATUS_DELIVERED)
            ->where('payment_status', Order::PAYMENT_STATUS_PENDING);

        $cashAwaitingConfirmationCount = (clone $cashAwaitingConfirmationOrders)->count();
        $cashAwaitingConfirmationAmount = (clone $cashAwaitingConfirmationOrders)->sum('total');

        // Operational resource counts
        $activeMenuItems = MenuItem::where('is_available', true)->count();
        $totalMenuItems = MenuItem::count();

        $activeTables = RestaurantTable::where('is_active', true)->count();
        $totalTables = RestaurantTable::count();

        $activeStaff = User::where('is_active', true)->count();
        $totalStaff = User::count();

        // Delivery specific metrics
        $deliveryOrdersQuery = (clone $baseQuery)->where('order_type', Order::TYPE_DELIVERY);
        $totalDeliveryOrders = (clone $deliveryOrdersQuery)->count();
        $activeDeliveryOrders = (clone $deliveryOrdersQuery)
            ->whereIn('status', [Order::STATUS_CONFIRMED, Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_OUT_FOR_DELIVERY])
            ->count();

        // Dine-in specific metrics
        $dineInOrdersQuery = (clone $baseQuery)->where('order_type', Order::TYPE_DINE_IN);
        $totalDineInOrders = (clone $dineInOrdersQuery)->count();
        $activeDineInOrders = (clone $dineInOrdersQuery)
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_CONFIRMED, Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_DELIVERED])
            ->count();

        // Recent orders
        $recentOrders = (clone $baseQuery)
            ->with(['table', 'deliveryZone', 'paymentGateway:id,display_name,provider'])
            ->latest('created_at')
            ->limit(10)
            ->get();
        $recentOrders->each(function (Order $order) {
            $order->setAttribute('payment_details', $order->paymentDetails());
        });
        $canViewFinancials = $request->user()?->hasPermission('financials.view') ?? false;
        if (! $canViewFinancials) {
            $recentOrders->each(function (Order $order) {
                $order->setAttribute('payment_details', ['method' => $order->payment_method, 'status' => $order->payment_status]);
                $order->makeHidden(['subtotal', 'delivery_fee', 'total', 'payment_reference', 'gateway_checkout_url']);
            });
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'date_range' => $dateRange,
                'orders' => [
                    'total' => $totalOrders,
                    'pending' => $pendingOrders,
                    'confirmed' => $confirmedOrders,
                    'preparing' => $preparingOrders,
                    'ready' => $readyOrders,
                    'out_for_delivery' => $outForDeliveryOrders,
                    'delivered' => $deliveredOrders,
                    'completed' => $completedOrders,
                    'cancelled' => $cancelledOrders,
                    'active_total' => $pendingOrders + $confirmedOrders + $preparingOrders + $readyOrders + $outForDeliveryOrders + $deliveredOrders,
                ],
                'financials' => $canViewFinancials ? [
                    'total_sales' => $this->money($totalSales),
                    'currency' => 'GHS',
                    'currency_symbol' => 'GH₵',
                    'cash_awaiting_confirmation_count' => $cashAwaitingConfirmationCount,
                    'cash_awaiting_confirmation_amount' => $this->money($cashAwaitingConfirmationAmount),
                ] : null,
                'resources' => [
                    'active_menu_items' => $activeMenuItems,
                    'total_menu_items' => $totalMenuItems,
                    'active_tables' => $activeTables,
                    'total_tables' => $totalTables,
                    'active_staff' => $activeStaff,
                    'total_staff' => $totalStaff,
                ],
                'breakdown' => [
                    'delivery' => [
                        'total' => $totalDeliveryOrders,
                        'active' => $activeDeliveryOrders,
                    ],
                    'dine_in' => [
                        'total' => $totalDineInOrders,
                        'active' => $activeDineInOrders,
                    ],
                ],
                'recent_orders' => $recentOrders,
            ],
        ]);
    }

    private function money(mixed $amount): string
    {
        $value = (string) $amount;
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $parts)) return '0.00';
        return $parts[1].(ltrim($parts[2], '0') ?: '0').'.'.str_pad(substr($parts[3] ?? '', 0, 2), 2, '0');
    }
}
