<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Preference;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variant;
use App\Services\Inventory\StockLedger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::connection()->getDatabaseName() !== 'moto_demo') {
            throw new RuntimeException('Demo seeding is restricted to moto_demo.');
        }
        if (User::exists()) {
            $this->command?->info('Demo users already exist; no credentials or records replaced.');

            return;
        }
        $managerPassword = bin2hex(random_bytes(12));
        $staffPassword = bin2hex(random_bytes(12));
        DB::transaction(function () use ($managerPassword, $staffPassword): void {
            foreach ([['manager', 'Alex Reyes', 'manager', $managerPassword], ['staff', 'Maria Santos', 'staff', $staffPassword]] as [$username, $name, $role, $password]) {
                $user = User::create(['username' => $username, 'name' => $name, 'role' => $role, 'active' => true, 'password' => Hash::make($password)]);
                Preference::create(['user_id' => $user->id, 'value' => ['theme' => 'dark', 'density' => 'comfortable', 'rows' => 20, 'reduced_motion' => false, 'sound' => false]]);
            }
            $units = [];
            foreach (['pc' => 'Piece', 'set' => 'Set', 'pair' => 'Pair', 'bottle' => 'Sealed bottle'] as $code => $name) {
                $units[$code] = Unit::create(compact('code', 'name'))->id;
            }
            $rows = [
                ['Brakes', 'Brake pads', 'Honda', 'Honda Click', 'BRK-CLICK-01', 'Standard', 'pc', 35000, 15],
                ['Drivetrain', 'Drive belt', 'Yamaha', 'Yamaha NMAX', 'BELT-NMAX-01', 'Standard', 'pc', 180000, 5],
                ['Drivetrain', 'Drive chain', 'Yamaha', 'Yamaha Sniper', 'CHAIN-SNIPER-01', 'Standard', 'set', 85000, 2],
                ['Accessories', 'Full-face helmet', 'BORG Demo', 'Universal', 'HLM-BLK-M', 'Black / M', 'pc', 280000, 3],
                ['Accessories', 'Full-face helmet', 'BORG Demo', 'Universal', 'HLM-BLK-L', 'Black / L', 'pc', 280000, 2],
                ['Accessories', 'Side mirror', 'BORG Demo', 'Universal', 'MIRROR-PAIR-01', 'Standard', 'pair', 45000, 10],
                ['Accessories', 'Riding gloves', 'BORG Demo', 'Universal', 'GLOVE-BLK-M', 'Black / M', 'pair', 65000, 2],
                ['Engine', 'Spark plug', 'BORG Demo', 'Check fitment before sale', 'SPARK-STD-01', 'Standard', 'pc', 18000, 20],
                ['Lubricants', 'Engine oil 10W-40', 'BORG Demo', 'Sealed 1 L bottle', 'OIL-10W40-1L', '1 L', 'bottle', 45000, 0],
            ];
            foreach ($rows as [$category, $name, $brand, $compatibility, $sku, $variantName, $unit, $price, $stock]) {
                $categoryId = Category::firstOrCreate(['name' => $category])->id;
                $product = Product::firstOrCreate(['name' => $name], ['category_id' => $categoryId, 'brand' => $brand, 'compatibility' => $compatibility, 'description' => 'Academic demonstration product. Verify actual fitment and shop data before real use.']);
                $variant = Variant::create(['product_id' => $product->id, 'unit_id' => $units[$unit], 'sku' => $sku, 'name' => $variantName, 'price_centavos' => $price, 'stock' => 0, 'threshold' => 3]);
                app(StockLedger::class)->post($variant, $stock, 'opening', 'demo-opening:'.$sku, null, 'Approved academic opening count');
            }
            $sections = [
                'general' => ['shop_name' => 'BORG’S Motorcycle Parts and Accessories', 'address' => '', 'contact' => '', 'currency' => 'PHP', 'timezone' => 'Asia/Manila'],
                'sales' => ['methods' => ['cash', 'gcash', 'qrph', 'debit', 'credit'], 'discount_cap' => 10, 'manual_verification' => true],
                'receipts' => ['width' => 80, 'copies' => 1, 'footer' => 'Academic demonstration receipt', 'tax_enabled' => false],
                'inventory' => ['threshold' => 3, 'max_image_mb' => 5, 'allow_negative_stock' => false],
                'notifications' => ['stock' => true, 'requests' => true, 'system' => true],
                'security' => ['idle_minutes' => 15, 'absolute_hours' => 8, 'failed_attempts' => 5, 'cooldown_minutes' => 15, 'password_min' => 15],
                'backup' => ['enabled' => false, 'schedule' => '20:00', 'retain' => 7],
                'system' => ['maintenance' => false],
            ];
            foreach ($sections as $section => $value) {
                Setting::create(compact('section', 'value'));
            }
        });
        file_put_contents(base_path('demo-access.txt'), "MOTO LOCAL DEMONSTRATION ACCESS\nKeep this local file private. No production credentials.\n\nManager username: manager\nManager password: ".$managerPassword."\n\nStaff username: staff\nStaff password: ".$staffPassword."\n");
        $this->command?->info('Demo catalog and users created. Credentials saved privately in demo-access.txt.');
    }
}
