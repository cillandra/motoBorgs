<?php

namespace App\Models;

use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $fillable = ['cart_id', 'cashier_id', 'cashier_name', 'request_key', 'request_hash', 'subtotal_centavos', 'discount_centavos', 'tax_centavos', 'tax_mode', 'total_centavos', 'approval_id'];

    protected function casts(): array
    {
        return ['subtotal_centavos' => 'integer', 'discount_centavos' => 'integer', 'total_centavos' => 'integer'];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(PaymentRecord::class);
    }

    public function refunds(): HasManyThrough
    {
        return $this->hasManyThrough(RefundRecord::class, ReturnRequest::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }
}
