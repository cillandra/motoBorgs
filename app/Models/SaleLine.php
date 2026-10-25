<?php

namespace App\Models;

use Database\Factories\SaleLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleLine extends Model
{
    /** @use HasFactory<SaleLineFactory> */
    use HasFactory;

    protected $fillable = ['sale_id', 'variant_id', 'category_id_snapshot', 'category_name', 'sku', 'product_name', 'variant_name', 'unit', 'quantity', 'price_centavos', 'discount_centavos', 'total_centavos'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_centavos' => 'integer', 'discount_centavos' => 'integer', 'total_centavos' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function returnLines(): HasMany
    {
        return $this->hasMany(ReturnLine::class);
    }
}
