<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending')->index();
            $table->text('reason');
            $table->text('review_reason')->nullable();
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_line_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('restock_quantity')->default(0);
            $table->string('condition', 40)->nullable();
            $table->unsignedBigInteger('refundable_centavos')->default(0);
            $table->timestamps();
            $table->unique(['return_request_id', 'sale_line_id']);
        });
        Schema::create('refund_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('manager_id')->constrained('users')->restrictOnDelete();
            $table->string('operation_key', 191)->unique();
            $table->unsignedBigInteger('amount_centavos');
            $table->enum('status', ['pending', 'failed', 'paid'])->default('pending')->index();
            $table->string('reference', 100);
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_name', 160)->nullable();
            $table->string('type', 80)->index();
            $table->string('module', 40)->index();
            $table->string('subject_type', 80);
            $table->string('subject_id', 100);
            $table->string('outcome', 30)->index();
            $table->json('context');
            $table->timestamp('created_at')->index();
            $table->index(['actor_id', 'created_at']);
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('section', 60)->unique();
            $table->json('value');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->json('value');
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role', 20)->nullable();
            $table->string('event_key', 191)->unique();
            $table->string('type', 80);
            $table->json('context');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('backup_manifests', function (Blueprint $table): void {
            $table->id();
            $table->string('identifier', 100)->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('status', ['running', 'complete', 'failed'])->default('running');
            $table->json('manifest')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['backup_manifests', 'notifications', 'preferences', 'settings', 'audit_events', 'refund_records', 'return_lines', 'return_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
