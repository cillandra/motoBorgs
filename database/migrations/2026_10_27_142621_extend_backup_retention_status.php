<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE backup_manifests MODIFY status ENUM('running','complete','failed','expired') NOT NULL DEFAULT 'running'");
    }

    public function down(): void
    {
        DB::table('backup_manifests')->where('status', 'expired')->update(['status' => 'complete']);
        DB::statement("ALTER TABLE backup_manifests MODIFY status ENUM('running','complete','failed') NOT NULL DEFAULT 'running'");
    }
};
