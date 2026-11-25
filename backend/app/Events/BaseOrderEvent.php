<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class BaseOrderEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public ?string $previousStatus = null,
        public ?string $note = null
    ) {
        $this->order->loadMissing(['items', 'table', 'deliveryZone']);
    }

    /**
     * Payload for authorized dashboard recipients.
     * Never exposes QR hashes, tokens, passwords or secrets.
     */
    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id'                  => $this->order->id,
                'order_number'        => $this->order->order_number,
                'order_type'          => $this->order->order_type,
                'status'              => $this->order->status,
                'table_number'        => $this->order->table?->table_number ?? 'N/A',
                'restaurant_table_id' => $this->order->restaurant_table_id,
                'delivery_zone_id'    => $this->order->delivery_zone_id,
                'delivery_zone_name'  => $this->order->deliveryZone?->name,
                // Privacy protection: do not broadcast full street addresses or delivery customer phone over shared channels
                'delivery_address'    => null,
                'customer_name'       => $this->order->customer_name,
                'customer_phone'      => $this->order->order_type === Order::TYPE_DINE_IN ? $this->order->customer_phone : null,
                'customer_notes'      => $this->order->customer_notes,
                'subtotal'            => (string) $this->order->subtotal,
                'delivery_fee'        => (string) $this->order->delivery_fee,
                'total'               => (string) $this->order->total,
                'payment_method'      => $this->order->payment_method,
                'payment_status'      => $this->order->payment_status,
                'waiter_id'           => $this->order->waiter_id,
                'kitchen_staff_id'    => $this->order->kitchen_staff_id,
                'delivery_staff_id'   => $this->order->delivery_staff_id,
                'created_at'          => $this->order->created_at?->toISOString(),
                'updated_at'          => $this->order->updated_at?->toISOString(),
                'items'               => $this->order->items->map(fn ($item) => [
                    'id'                  => $item->id,
                    'menu_item_id'        => $item->menu_item_id,
                    'item_name'           => $item->item_name,
                    'unit_price'          => (string) $item->unit_price,
                    'quantity'            => $item->quantity,
                    'special_instructions'=> $item->special_instructions,
                    'line_total'          => (string) $item->line_total,
                ])->values()->all(),
            ],
            'previous_status' => $this->previousStatus,
            'new_status'      => $this->order->status,
            'note'            => $this->note,
            'timestamp'       => now()->toISOString(),
        ];
    }
}
