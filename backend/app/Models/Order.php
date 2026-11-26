<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'order_number',
    'customer_id',
    'idempotency_key',
    'order_type',
    'restaurant_table_id',
    'delivery_zone_id',
    'customer_name',
    'customer_phone',
    'customer_email',
    'delivery_address',
    'customer_notes',
    'status',
    'subtotal',
    'delivery_fee',
    'total',
    'grand_total',
    'delivery_fee_payment_method',
    'delivery_fee_payment_status',
    'delivery_fee_confirmed_at',
    'delivery_fee_confirmed_by',
    'payment_method',
    'payment_status',
    'payment_reference',
    'gateway_checkout_url',
    'payment_gateway_id',
    'waiter_id',
    'kitchen_staff_id',
    'delivery_staff_id',
])]
class Order extends Model
{
    use HasFactory;

    protected $hidden = ['customer_id', 'payment_reference', 'gateway_checkout_url', 'latestPaymentTransaction', 'latest_payment_transaction'];

    public const TYPE_DINE_IN = 'dine_in';
    public const TYPE_DELIVERY = 'delivery';
    public const TYPE_PICKUP = 'pickup';

    public const PAYMENT_METHOD_CASH = 'cash';
    public const PAYMENT_METHOD_GATEWAY = 'gateway';

    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const DELIVERY_FEE_PENDING = 'pending';
    public const DELIVERY_FEE_PAID = 'paid';
    public const DELIVERY_FEE_NOT_APPLICABLE = 'not_applicable';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Permitted status transitions per order type.
     */
    public const VALID_TRANSITIONS = [
        self::TYPE_DINE_IN => [
            self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            self::STATUS_CONFIRMED => [self::STATUS_PREPARING, self::STATUS_CANCELLED],
            self::STATUS_PREPARING => [self::STATUS_READY],
            self::STATUS_READY => [self::STATUS_DELIVERED],
            self::STATUS_DELIVERED => [self::STATUS_COMPLETED],
            self::STATUS_COMPLETED => [],
            self::STATUS_CANCELLED => [],
        ],
        self::TYPE_DELIVERY => [
            self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            self::STATUS_CONFIRMED => [self::STATUS_PREPARING, self::STATUS_CANCELLED],
            self::STATUS_PREPARING => [self::STATUS_READY],
            self::STATUS_READY => [self::STATUS_OUT_FOR_DELIVERY],
            self::STATUS_OUT_FOR_DELIVERY => [self::STATUS_DELIVERED],
            self::STATUS_DELIVERED => [self::STATUS_COMPLETED],
            self::STATUS_COMPLETED => [],
            self::STATUS_CANCELLED => [],
        ],
        self::TYPE_PICKUP => [
            self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            self::STATUS_CONFIRMED => [self::STATUS_PREPARING, self::STATUS_CANCELLED],
            self::STATUS_PREPARING => [self::STATUS_READY],
            self::STATUS_READY => [self::STATUS_COMPLETED],
            self::STATUS_COMPLETED => [],
            self::STATUS_CANCELLED => [],
        ],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal'       => 'decimal:2',
            'delivery_fee'   => 'decimal:2',
            'total'          => 'decimal:2',
            'grand_total'    => 'decimal:2',
            'delivery_fee_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Order items snapshot.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /** Customer account associated when an authenticated customer placed the order. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Status transition audit history.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')->orderBy('created_at');
    }

    /**
     * Dine-in table associated with this order.
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    /**
     * Delivery zone associated with this order.
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    /**
     * Waiter assigned to this order.
     */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    /**
     * Kitchen staff assigned to this order.
     */
    public function kitchenStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kitchen_staff_id');
    }

    /**
     * Delivery staff assigned to this order.
     */
    public function deliveryStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_staff_id');
    }

    /**
     * Check if a transition to the new status is valid.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $type = $this->order_type;
        $current = $this->status;

        $allowedTransitions = self::VALID_TRANSITIONS[$type][$current] ?? [];

        return in_array($newStatus, $allowedTransitions, true);
    }

    /**
     * Scope orders by status.
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope orders by order type.
     */
    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('order_type', $type);
    }

    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    public function latestPaymentTransaction(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class)->latestOfMany();
    }

    /** Safe payment summary for administrative and customer tracking responses. */
    public function paymentDetails(): array
    {
        $this->loadMissing(['paymentGateway:id,display_name,provider', 'latestPaymentTransaction:id,order_id,status,currency,verified_at,received_at']);
        $transaction = $this->latestPaymentTransaction;

        return [
            'method' => $this->payment_method,
            'status' => $this->payment_status,
            'gateway_name' => $this->paymentGateway?->display_name,
            'gateway_provider' => $this->paymentGateway?->provider,
            'reference' => $this->payment_reference,
            'amount' => $this->total,
            'food_amount' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            'delivery_fee_payment_method' => $this->delivery_fee_payment_method,
            'delivery_fee_payment_status' => $this->delivery_fee_payment_status,
            'delivery_fee_confirmed_at' => $this->delivery_fee_confirmed_at,
            'grand_total' => $this->grand_total ?? $this->total,
            'transaction_status' => $transaction?->status,
            'currency' => $transaction?->currency,
            'verified_at' => $transaction?->verified_at,
        ];
    }
}
