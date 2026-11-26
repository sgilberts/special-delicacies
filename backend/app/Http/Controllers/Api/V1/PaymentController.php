<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\GatewayPaymentService;
use App\Events\OrderTrackingUpdated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private GatewayPaymentService $payments) {}

    public function options(): JsonResponse
    {
        $gateway = $this->payments->configuredGateway();
        return response()->json(['success' => true, 'data' => [
            'online_payment_enabled' => (bool) $gateway,
            'gateway_name' => $gateway?->display_name,
            'gateway_provider' => $gateway?->provider,
        ]]);
    }

    public function verify(string $reference): JsonResponse
    {
        $order = $this->payments->verify($reference);
        if (! $order) return response()->json(['success' => false, 'message' => 'Payment reference not found.'], 404);
        $order->loadMissing('paymentGateway:id,display_name,provider');
        return response()->json(['success' => true, 'data' => [
            'order_number' => $order->order_number,
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
            'provider_payment_status' => $order->getAttribute('provider_payment_status'),
            'payment_details' => $order->paymentDetails(),
        ]]);
    }

    /** Public, limited order progress lookup using the non-sequential order number. */
    public function track(string $orderNumber): JsonResponse
    {
        $order = Order::with(['items', 'table:id,table_number', 'deliveryZone:id,name', 'statusHistories'])
            ->where('order_number', $orderNumber)->first();
        if (! $order) return response()->json(['success' => false, 'message' => 'Order number not found.'], 404);

        return response()->json(['success' => true, 'data' => [
            'order_number' => $order->order_number,
            'tracking_channel' => OrderTrackingUpdated::channelName($order->order_number),
            'order_type' => $order->order_type,
            'status' => $order->status,
            'payment_details' => $order->paymentDetails(),
            'total' => $order->total,
            'food_amount' => $order->subtotal,
            'delivery_fee' => $order->delivery_fee,
            'grand_total' => $order->grand_total ?? $order->total,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            'items' => $order->items->map(fn ($item) => [
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
            ]),
            'table' => $order->table,
            'delivery_zone' => $order->deliveryZone,
            'status_history' => $order->statusHistories->map(fn ($history) => [
                'status' => $history->new_status,
                'created_at' => $history->created_at,
            ])->values(),
        ]]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $accepted = $this->payments->consumeWebhook(
            'paystack',
            $request->getContent(),
            (string) $request->header('x-paystack-signature', ''),
            (string) $request->header('paystack-signature', '')
        );
        return response()->json(['success' => $accepted], $accepted ? 200 : 401);
    }

    public function providerWebhook(string $provider, Request $request): JsonResponse
    {
        abort_unless(in_array($provider, ['hubtel', 'korapay'], true), 404);
        $header = $provider === 'korapay' ? 'x-korapay-signature' : '';
        $accepted = $this->payments->consumeWebhook($provider, $request->getContent(), (string) $request->header($header, ''));
        return response()->json(['success' => $accepted], $accepted ? 200 : 401);
    }
}
