<?php

namespace App\Models;

use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasFactory;

    protected $fillable = ['cart_id', 'requester_id', 'approver_id', 'cart_version', 'action', 'parameters', 'reason', 'expires_at', 'consumed_at', 'invalidated_at'];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'expires_at' => 'datetime', 'consumed_at' => 'datetime', 'invalidated_at' => 'datetime'];
    }
}
