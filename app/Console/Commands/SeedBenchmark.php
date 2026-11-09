<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SeedBenchmark extends Command
{
    protected $signature = 'moto:benchmark-seed {--variants=10000} {--sales=100000}';

    protected $description = 'Rebuild ONLY moto_benchmark with deterministic synthetic performance data';

    public function handle(): int
    {
        if (DB::connection()->getDatabaseName() !== 'moto_benchmark') {
            $this->error('Benchmark seeding refuses every database except moto_benchmark.');

            return self::FAILURE;
        }
        $variants = filter_var($this->option('variants'), FILTER_VALIDATE_INT);
        $sales = filter_var($this->option('sales'), FILTER_VALIDATE_INT);
        if (! $variants || $variants < 10 || $variants > 50000 || ! $sales || $sales > 200000) {
            $this->error('Use 10–50000 variants and 1–200000 sales.');

            return self::FAILURE;
        }
        $this->call('migrate:fresh', ['--database' => config('database.default'), '--force' => true, '--no-interaction' => true]);
        $stamp = now()->format('Y-m-d H:i:s');
        $hash = Hash::make('Synthetic benchmark passphrase 2026!');
        for ($id = 1; $id <= 6; $id++) {
            DB::table('users')->insert(['id' => $id, 'username' => $id === 1 ? 'bench-manager' : 'bench-staff-'.($id - 1), 'name' => 'Benchmark '.$id, 'password' => $hash, 'role' => $id === 1 ? 'manager' : 'staff', 'active' => true, 'force_password_change' => false, 'version' => 1, 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
        DB::table('units')->insert(['id' => 1, 'code' => 'pc', 'name' => 'Piece', 'created_at' => $stamp, 'updated_at' => $stamp]);
        for ($id = 1; $id <= 20; $id++) {
            DB::table('categories')->insert(['id' => $id, 'name' => 'Benchmark category '.$id, 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
        $opening = max(200, (int) ceil($sales / $variants) + 200);
        for ($offset = 0; $offset < $variants; $offset += 500) {
            $products = [];
            $rows = [];
            $movements = [];
            for ($id = $offset + 1; $id <= min($offset + 500, $variants); $id++) {
                $sold = intdiv($sales, $variants) + ($id <= ($sales % $variants) ? 1 : 0);
                $products[] = ['id' => $id, 'category_id' => ($id % 20) + 1, 'name' => 'Benchmark Motorcycle Part '.str_pad((string) $id, 5, '0', STR_PAD_LEFT), 'brand' => 'Academic', 'compatibility' => 'Demonstration motorcycle', 'created_at' => $stamp, 'updated_at' => $stamp];
                $rows[] = ['id' => $id, 'product_id' => $id, 'unit_id' => 1, 'sku' => 'BENCH-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT), 'name' => 'Standard', 'price_centavos' => 10000, 'stock' => $opening - $sold, 'threshold' => 3, 'version' => 1, 'created_at' => $stamp, 'updated_at' => $stamp];
                $movements[] = ['variant_id' => $id, 'delta' => $opening, 'balance_after' => $opening, 'type' => 'opening', 'operation_key' => 'bench-opening-'.$id, 'reason' => 'Synthetic performance fixture', 'occurred_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp];
            }
            DB::table('products')->insert($products);
            DB::table('variants')->insert($rows);
            DB::table('stock_movements')->insert($movements);
        }
        for ($offset = 0; $offset < $sales; $offset += 500) {
            $carts = [];
            $records = [];
            $lines = [];
            $payments = [];
            $movements = [];
            for ($id = $offset + 1; $id <= min($offset + 500, $sales); $id++) {
                $variant = (($id - 1) % $variants) + 1;
                $cashier = ($id % 5) + 2;
                $date = now()->startOfDay()->subDays($id % 90)->addMinutes($id % 720)->utc()->format('Y-m-d H:i:s');
                $carts[] = ['id' => $id, 'cashier_id' => $cashier, 'status' => 'completed', 'version' => 2, 'created_at' => $date, 'updated_at' => $date];
                $records[] = ['id' => $id, 'cart_id' => $id, 'cashier_id' => $cashier, 'cashier_name' => 'Benchmark '.$cashier, 'request_key' => 'bench-sale-'.$id, 'request_hash' => hash('sha256', 'bench-sale-'.$id), 'subtotal_centavos' => 10000, 'discount_centavos' => 0, 'tax_centavos' => 0, 'total_centavos' => 10000, 'created_at' => $date, 'updated_at' => $date];
                $lines[] = ['sale_id' => $id, 'variant_id' => $variant, 'category_id_snapshot' => ($variant % 20) + 1, 'category_name' => 'Benchmark category '.(($variant % 20) + 1), 'sku' => 'BENCH-'.str_pad((string) $variant, 5, '0', STR_PAD_LEFT), 'product_name' => 'Benchmark Motorcycle Part '.str_pad((string) $variant, 5, '0', STR_PAD_LEFT), 'variant_name' => 'Standard', 'unit' => 'pc', 'quantity' => 1, 'price_centavos' => 10000, 'discount_centavos' => 0, 'total_centavos' => 10000, 'created_at' => $date, 'updated_at' => $date];
                $payments[] = ['sale_id' => $id, 'method' => 'cash', 'amount_centavos' => 10000, 'received_centavos' => 10000, 'change_centavos' => 0, 'verified' => true, 'created_at' => $date, 'updated_at' => $date];
                $movements[] = ['variant_id' => $variant, 'actor_id' => $cashier, 'delta' => -1, 'balance_after' => $opening - intdiv($id - 1, $variants) - 1, 'type' => 'sale', 'operation_key' => 'bench-sale-stock-'.$id, 'reason' => 'Synthetic performance fixture', 'occurred_at' => $date, 'created_at' => $date, 'updated_at' => $date];
            }
            DB::transaction(function () use ($carts, $records, $lines, $payments, $movements): void {
                DB::table('carts')->insert($carts);
                DB::table('sales')->insert($records);
                DB::table('sale_lines')->insert($lines);
                DB::table('payment_records')->insert($payments);
                DB::table('stock_movements')->insert($movements);
            });
        }
        $this->info(json_encode(['database' => 'moto_benchmark', 'variants' => $variants, 'sales' => $sales, 'sessions' => 5]));

        return self::SUCCESS;
    }
}
