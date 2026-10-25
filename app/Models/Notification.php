<?php

namespace App\Models;

use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'role', 'event_key', 'type', 'context', 'read_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'read_at' => 'datetime'];
    }
}
