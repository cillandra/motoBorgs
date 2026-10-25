<?php

namespace App\Models;

use Database\Factories\ReturnLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnLine extends Model
{
    /** @use HasFactory<ReturnLineFactory> */
    use HasFactory;

    protected $fillable = ['return_request_id', 'sale_line_id', 'quantity', 'restock_quantity', 'condition', 'refundable_centavos'];

    protected function casts(): array
    {
        return [];
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }
}
