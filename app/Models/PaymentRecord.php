<?php

namespace App\Models;

use Database\Factories\PaymentRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRecord extends Model
{
    /** @use HasFactory<PaymentRecordFactory> */
    use HasFactory;

    protected $fillable = ['sale_id', 'method', 'amount_centavos', 'received_centavos', 'change_centavos', 'verified', 'reference'];

    protected function casts(): array
    {
        return ['verified' => 'boolean', 'amount_centavos' => 'integer', 'received_centavos' => 'integer', 'change_centavos' => 'integer'];
    }
}
