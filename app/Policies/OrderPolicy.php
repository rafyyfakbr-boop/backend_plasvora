<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return (int) $order->buyer_id === (int) $user->id
            || (int) $order->seller_id === (int) $user->id;
    }

    public function cancel(User $user, Order $order): bool
    {
        return (int) $order->buyer_id === (int) $user->id;
    }

    public function retryPayment(User $user, Order $order): bool
    {
        return (int) $order->buyer_id === (int) $user->id;
    }

    public function refreshPayment(User $user, Order $order): bool
    {
        return (int) $order->buyer_id === (int) $user->id;
    }

    public function ship(User $user, Order $order): bool
    {
        return (int) $order->seller_id === (int) $user->id;
    }

    public function confirmDelivery(User $user, Order $order): bool
    {
        return (int) $order->buyer_id === (int) $user->id;
    }
}
