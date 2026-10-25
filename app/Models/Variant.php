<?php

namespace App\Models;

use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'unit_id', 'sku', 'barcode', 'name', 'price_centavos', 'cost_centavos', 'stock', 'threshold', 'archived_at', 'version'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'price_centavos' => 'integer', 'cost_centavos' => 'integer', 'stock' => 'integer', 'threshold' => 'integer', 'version' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
