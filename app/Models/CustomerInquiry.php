<?php

namespace App\Models;

use Database\Factories\CustomerInquiryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerInquiry extends Model
{
    /** @use HasFactory<CustomerInquiryFactory> */
    use HasFactory;

    protected $fillable = ['submission_key', 'payload_hash', 'name', 'email', 'phone', 'topic', 'variant_id', 'service_name', 'subject', 'message', 'status', 'handled_by', 'handled_at', 'version'];

    protected $hidden = ['submission_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime', 'version' => 'integer'];
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function reference(): string
    {
        return 'INQ-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
