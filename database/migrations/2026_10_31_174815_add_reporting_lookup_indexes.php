<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_records', function (Blueprint $table): void {
            $table->index(['status', 'paid_at'], 'refund_records_status_paid_at_index');
        });
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index(['module', 'created_at'], 'audit_events_module_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('refund_records', function (Blueprint $table): void {
            $table->dropIndex('refund_records_status_paid_at_index');
        });
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_events_module_created_at_index');
        });
    }
};
