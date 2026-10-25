<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 80)->unique();
            $table->string('email')->nullable()->change();
            $table->enum('role', ['manager', 'staff'])->default('staff');
            $table->boolean('active')->default(true);
            $table->boolean('force_password_change')->default(false);
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 160)->index();
            $table->string('brand', 100)->nullable()->index();
            $table->string('part_number', 100)->nullable();
            $table->text('compatibility')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('name', 120);
            $table->unsignedBigInteger('price_centavos');
            $table->unsignedBigInteger('cost_centavos')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('threshold')->default(3);
            $table->timestamp('archived_at')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['username', 'role', 'active', 'force_password_change', 'version']);
        });
    }
};
