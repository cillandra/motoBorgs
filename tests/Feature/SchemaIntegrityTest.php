<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_tables_exist_on_the_actual_mariadb_engine(): void
    {
        foreach (['variants', 'stock_movements', 'carts', 'sales', 'sale_lines', 'payment_records', 'return_requests', 'refund_records', 'audit_events', 'settings', 'backup_manifests'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing business table: $table");
        }
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_archived_sku_cannot_be_reused(): void
    {
        $product = DB::table('products')->insertGetId(['name' => 'Brake pad']);
        $unit = DB::table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece']);
        DB::table('variants')->insert(['product_id' => $product, 'unit_id' => $unit, 'sku' => 'BRK-01', 'name' => 'Standard', 'price_centavos' => 12500, 'archived_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('variants')->insert(['product_id' => $product, 'unit_id' => $unit, 'sku' => 'BRK-01', 'name' => 'Replacement', 'price_centavos' => 12500]);
    }

    public function test_negative_stock_is_rejected_by_database_constraint(): void
    {
        $product = DB::table('products')->insertGetId(['name' => 'Brake pad']);
        $unit = DB::table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece']);
        $this->expectException(QueryException::class);
        DB::table('variants')->insert(['product_id' => $product, 'unit_id' => $unit, 'sku' => 'BRK-01', 'name' => 'Standard', 'price_centavos' => 12500, 'stock' => -1]);
    }

    public function test_missing_variant_parent_is_rejected(): void
    {
        try {
            DB::table('variants')->insert(['product_id' => 999, 'unit_id' => 999, 'sku' => 'BRK-01', 'name' => 'Standard', 'price_centavos' => 12500]);
            $this->fail('A variant without valid parent records was inserted.');
        } catch (QueryException $exception) {
            $this->assertSame(1452, $exception->errorInfo[1]);
        }
    }
}
