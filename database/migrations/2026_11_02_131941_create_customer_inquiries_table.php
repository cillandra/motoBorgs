<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('submission_key')->unique();
            $table->char('payload_hash', 64);
            $table->string('name', 100);
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->enum('topic', ['general', 'product', 'service']);
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_name', 200)->nullable();
            $table->string('subject', 500);
            $table->text('message');
            $table->enum('status', ['new', 'handled'])->default('new');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_inquiries');
    }
};
