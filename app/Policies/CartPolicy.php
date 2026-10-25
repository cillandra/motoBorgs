<?php

namespace App\Policies;

use App\Models\Cart;
use App\Models\User;

class CartPolicy
{
    public function view(User $user, Cart $cart): bool
    {
        return $user->active && $cart->cashier_id === $user->id;
    }

    public function update(User $user, Cart $cart): bool
    {
        return $this->view($user, $cart) && in_array($cart->status, ['draft', 'held'], true);
    }
}
