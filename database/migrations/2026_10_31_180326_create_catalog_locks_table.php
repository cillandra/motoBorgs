<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_locks', function (Blueprint $table): void {
            $table->string('name', 40)->primary();
        });
        DB::table('catalog_locks')->insert(['name' => 'identifiers']);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_locks');
    }
};
