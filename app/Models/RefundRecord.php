<?php

namespace App\Models;

use Database\Factories\RefundRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundRecord extends Model
{
    /** @use HasFactory<RefundRecordFactory> */
    use HasFactory;

    protected $fillable = ['return_request_id', 'manager_id', 'operation_key', 'amount_centavos', 'status', 'reference', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'amount_centavos' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }
}
