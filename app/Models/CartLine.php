<?php

namespace App\Models;

use Database\Factories\CartLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartLine extends Model
{
    /** @use HasFactory<CartLineFactory> */
    use HasFactory;

    protected $fillable = ['cart_id', 'variant_id', 'quantity', 'price_centavos'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_centavos' => 'integer'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
