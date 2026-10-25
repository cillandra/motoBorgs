<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variant;

trait MotoFixtures
{
    protected function manager(): User
    {
        return User::factory()->create(['role' => 'manager']);
    }

    protected function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    protected function variant(int $stock = 3, int $price = 10000): Variant
    {
        $variant = Variant::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'unit_id' => Unit::firstOrCreate(['code' => 'pc'], ['name' => 'Piece'])->id,
            'stock' => $stock,
            'price_centavos' => $price,
        ]);
        StockMovement::create(['variant_id' => $variant->id, 'actor_id' => null, 'delta' => $stock, 'balance_after' => $stock, 'type' => 'opening', 'operation_key' => 'fixture:'.$variant->id, 'reason' => 'Approved fixture opening count']);

        return $variant;
    }

    /** @param array<int, int> $variantQuantities */
    protected function cart(User $cashier, array $variantQuantities): Cart
    {
        $cart = Cart::create(['cashier_id' => $cashier->id]);
        foreach ($variantQuantities as $id => $quantity) {
            $variant = Variant::findOrFail($id);
            $cart->lines()->create(['variant_id' => $id, 'quantity' => $quantity, 'price_centavos' => $variant->price_centavos]);
        }

        return $cart;
    }
}
