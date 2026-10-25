<?php

namespace App\Models;

use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    protected $fillable = ['actor_id', 'actor_name', 'type', 'module', 'subject_type', 'subject_id', 'outcome', 'context', 'created_at'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
