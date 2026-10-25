<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['draft', 'held', 'completed', 'cancelled'])->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('discount_centavos')->default(0);
            $table->string('label', 120)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['cashier_id', 'status']);
        });
        Schema::create('cart_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('price_centavos');
            $table->timestamps();
            $table->unique(['cart_id', 'variant_id']);
        });
        Schema::create('approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('cart_version');
            $table->string('action', 40);
            $table->json('parameters');
            $table->text('reason');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('cashier_name', 160);
            $table->string('request_key', 80)->unique();
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('subtotal_centavos');
            $table->unsignedBigInteger('discount_centavos')->default(0);
            $table->unsignedBigInteger('tax_centavos')->default(0);
            $table->string('tax_mode', 40)->default('demo_unconfigured');
            $table->unsignedBigInteger('total_centavos');
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->restrictOnDelete();
            $table->timestamps();
            $table->index(['cashier_id', 'created_at']);
            $table->index('created_at');
        });
        Schema::create('sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('category_id_snapshot')->nullable()->index();
            $table->string('category_name', 100)->nullable();
            $table->string('sku', 100)->index();
            $table->string('product_name', 160);
            $table->string('variant_name', 120);
            $table->string('unit', 20);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('price_centavos');
            $table->unsignedBigInteger('discount_centavos')->default(0);
            $table->unsignedBigInteger('total_centavos');
            $table->timestamps();
        });
        Schema::create('payment_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $table->enum('method', ['cash', 'gcash', 'qrph', 'debit', 'credit'])->index();
            $table->unsignedBigInteger('amount_centavos');
            $table->unsignedBigInteger('received_centavos');
            $table->unsignedBigInteger('change_centavos')->default(0);
            $table->boolean('verified');
            $table->string('reference', 100)->nullable();
            $table->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->bigInteger('delta');
            $table->unsignedInteger('balance_after');
            $table->enum('type', ['opening', 'receive', 'count_adjustment', 'sale', 'return']);
            $table->string('operation_key', 191)->unique();
            $table->string('reference', 100)->nullable();
            $table->text('reason');
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['variant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'payment_records', 'sale_lines', 'sales', 'approvals', 'cart_lines', 'carts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
