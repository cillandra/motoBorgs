<?php

namespace App\Models;

use Database\Factories\BackupManifestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupManifest extends Model
{
    /** @use HasFactory<BackupManifestFactory> */
    use HasFactory;

    protected $fillable = ['identifier', 'actor_id', 'status', 'manifest', 'failure', 'completed_at'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'completed_at' => 'datetime'];
    }
}
