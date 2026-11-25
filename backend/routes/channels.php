<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Register broadcasting auth routes with Sanctum API prefix
Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']]);

// User-specific private channel
Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    if (! $user->is_active) {
        return false;
    }

    return (string) $user->id === (string) $id;
});

// Waiter operational channel: waiters and Super Admin only
Broadcast::channel('orders.waiter', function (User $user) {
    if (! $user->is_active) {
        return false;
    }

    return $user->hasRole('waiter-waitress')
        || $user->hasRole('super-admin')
        || $user->hasPermission('orders.confirm')
        || $user->hasPermission('orders.deliver');
});

// Kitchen operational channel: kitchen staff and Super Admin only
Broadcast::channel('orders.kitchen', function (User $user) {
    if (! $user->is_active) {
        return false;
    }

    return $user->hasRole('kitchen-staff')
        || $user->hasRole('super-admin')
        || $user->hasPermission('orders.prepare')
        || $user->hasPermission('orders.ready');
});

// Order-specific channel with strict operational authorization
Broadcast::channel('orders.{orderId}', function (User $user, int $orderId) {
    if (! $user->is_active) {
        return false;
    }

    $order = \App\Models\Order::find($orderId);
    if (! $order) {
        return false;
    }

    // Super Admin has full operational visibility
    if ($user->hasRole('super-admin')) {
        return true;
    }

    // Waiters can only track active dine-in orders or orders assigned to them
    if ($user->hasRole('waiter-waitress') || $user->hasPermission('orders.deliver')) {
        return $order->order_type === \App\Models\Order::TYPE_DINE_IN && (
            in_array($order->status, [
                \App\Models\Order::STATUS_PENDING,
                \App\Models\Order::STATUS_CONFIRMED,
                \App\Models\Order::STATUS_PREPARING,
                \App\Models\Order::STATUS_READY,
                \App\Models\Order::STATUS_DELIVERED,
            ], true) || (int) $order->waiter_id === (int) $user->id
        );
    }

    // Kitchen staff can only track orders in active kitchen preparation workflow
    if ($user->hasRole('kitchen-staff') || $user->hasPermission('orders.prepare')) {
        return in_array($order->status, [
            \App\Models\Order::STATUS_CONFIRMED,
            \App\Models\Order::STATUS_PREPARING,
            \App\Models\Order::STATUS_READY,
        ], true) || (int) $order->kitchen_staff_id === (int) $user->id;
    }

    // Delivery staff can only track delivery orders assigned to them or unassigned ready orders
    if ($user->hasRole('delivery-staff') || $user->hasPermission('delivery.orders.view')) {
        if ($order->order_type !== \App\Models\Order::TYPE_DELIVERY) {
            return false;
        }
        return (int) $order->delivery_staff_id === (int) $user->id
            || (! $order->delivery_staff_id && in_array($order->status, [
                \App\Models\Order::STATUS_READY,
            ], true));
    }

    return false;
});

// Broadcast monitoring channel for Reverb admin dashboard
Broadcast::channel('reverb.monitor', function (User $user) {
    return $user->is_active && ($user->hasRole('super-admin') || $user->hasPermission('reverb.monitor'));
});

Broadcast::channel('financials', function (User $user) {
    return $user->is_active && $user->hasPermission('financials.view');
});

Broadcast::channel('orders', function (User $user) {
    return $user->is_active && $user->hasPermission('orders.view');
});

Broadcast::channel('chat.staff.{userId}', function (User $user, int $userId) {
    return $user->is_active && (int) $user->id === $userId
        && ($user->hasPermission('chat.receive') || $user->hasPermission('chat.view_assigned') || $user->hasPermission('chat.view_all'));
});

Broadcast::channel('chat.conversation.{conversationId}', function (User $user, int $conversationId) {
    if (! $user->is_active || ! ($user->hasPermission('chat.view_assigned') || $user->hasPermission('chat.view_all'))) return false;
    $conversation = \App\Models\ChatConversation::find($conversationId);
    return $conversation && ($user->hasPermission('chat.view_all') || (int) $conversation->assigned_to === (int) $user->id);
});

// Delivery operational channel: delivery staff and Super Admin only
Broadcast::channel('orders.delivery', function (User $user) {
    if (! $user->is_active) {
        return false;
    }

    return $user->hasRole('delivery-staff')
        || $user->hasRole('super-admin')
        || $user->hasPermission('delivery.orders.view')
        || $user->hasPermission('delivery.orders.dispatch');
});
