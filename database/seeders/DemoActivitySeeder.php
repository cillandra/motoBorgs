<?php

namespace Database\Seeders;

use App\Models\Approval;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variant;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\CartService;
use App\Services\Pos\CheckoutService;
use App\Services\Sales\RefundService;
use App\Services\Sales\ReturnService;
use App\Services\System\OperationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoActivitySeeder extends Seeder
{
    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();
        if ($database !== 'moto_demo' && ! (app()->runningUnitTests() && $database === 'moto_test')) {
            throw new RuntimeException('Sample activity is restricted to the MOTO demonstration database.');
        }
        $previousClock = Carbon::getTestNow();
        $anchor = CarbonImmutable::now('Asia/Manila');
        try {
            app(OperationGuard::class)->exclusive(function () use ($anchor): void {
                abort_if(app(OperationGuard::class)->busy(), 503, 'Finish maintenance before adding sample activity.');
                DB::transaction(function () use ($anchor): void {
                    if (Setting::where('section', 'demo_activity')->exists()) {
                        $this->command?->info('Sample activity already exists; no records duplicated or replaced.');

                        return;
                    }
                    $manager = User::where('active', true)->where('role', 'manager')->orderBy('id')->firstOrFail();
                    $staff = User::where('active', true)->where('role', 'staff')->orderBy('id')->firstOrFail();
                    Carbon::setTestNow($anchor->subDays(29)->startOfDay()->addHours(8)->utc());
                    $variants = $this->catalog($manager);
                    $methods = array_values(array_intersect(['cash', 'gcash', 'qrph', 'debit', 'credit'], Setting::where('section', 'sales')->first()?->value['methods'] ?? ['cash', 'gcash', 'qrph', 'debit', 'credit']));
                    if ($methods === []) {
                        throw new RuntimeException('Enable a payment method before creating sample sales.');
                    }
                    $saleCount = 0;
                    $returnCount = 0;
                    for ($day = 29; $day >= 0; $day--) {
                        $count = $day === 0 ? 12 : 3 + $day % 4;
                        for ($index = 0; $index < $count; $index++) {
                            $at = $anchor->subDays($day)->startOfDay()->addHours(9)->addMinutes($index * ($day === 0 ? 45 : 90));
                            if ($at->gt($anchor)) {
                                $at = $anchor->subSeconds($count - $index + 10);
                            }
                            Carbon::setTestNow($at->utc());
                            $cashier = $saleCount % 3 === 0 ? $manager : $staff;
                            $cart = Cart::create(['cashier_id' => $cashier->id, 'label' => 'DEMO · school sample sale']);
                            $chosen = [$saleCount % count($variants) => 1 + $saleCount % 2];
                            if ($saleCount % 3 !== 0) {
                                $chosen[($saleCount + 5) % count($variants)] = 1;
                            }
                            foreach ($chosen as $variantIndex => $quantity) {
                                $cart = app(CartService::class)->mutate($cashier, $cart->refresh(), $cart->version, ['action' => 'add', 'variant_id' => $variants[$variantIndex]->id, 'quantity' => $quantity]);
                            }
                            $quote = app(CartService::class)->quote($cashier, $cart);
                            if ($saleCount % 7 === 0) {
                                $discount = intdiv($quote['subtotal'], 20);
                                $cart->update(['discount_centavos' => $discount, 'version' => $cart->version + 1]);
                                Approval::create(['cart_id' => $cart->id, 'requester_id' => $cashier->id, 'approver_id' => $manager->id, 'cart_version' => $cart->version, 'action' => 'discount', 'parameters' => ['discount_centavos' => $discount], 'reason' => 'School demo: synthetic promotional discount, no real transaction', 'expires_at' => now()->addMinutes(5)]);
                                app(AuditRecorder::class)->record($manager, 'Demo discount approved', 'pos', 'cart', (string) $cart->id, 'success', ['reason' => 'School demonstration sample', 'amount' => $discount]);
                            }
                            $quote = app(CartService::class)->quote($cashier, $cart->refresh());
                            $method = $methods[$saleCount % count($methods)];
                            $sale = app(CheckoutService::class)->complete($cashier, $cart, $cart->version, 'school-demo-v1-sale-'.$saleCount, ['method' => $method, 'received_centavos' => $method === 'cash' ? (int) (ceil($quote['total'] / 10000) * 10000) : $quote['total'], 'verified' => true, 'reference' => 'DEMO-PAY-'.str_pad((string) $saleCount, 4, '0', STR_PAD_LEFT)])->sale;
                            if ($saleCount % 17 === 0 || ($day === 0 && $index % 3 === 0)) {
                                Carbon::setTestNow($at->addSeconds(30)->min($anchor)->utc());
                                $line = $sale->lines->first();
                                $request = app(ReturnService::class)->request($cashier, $sale, [$line->id => 1], 'School demo: sample fitment / condition return');
                                $mode = ['paid', 'pending', 'failed', 'rejected', 'awaiting'][$returnCount % 5];
                                if ($mode !== 'awaiting') {
                                    $conditions = [$request->lines->first()->id => ['restock_quantity' => $returnCount % 3 === 0 ? 0 : 1]];
                                    $request = app(ReturnService::class)->review($manager, $request, $mode === 'rejected' ? [] : $conditions, $mode !== 'rejected', 'School demo: synthetic inspection decision');
                                    if ($mode !== 'rejected') {
                                        app(RefundService::class)->recordOutcome($manager, $request, 'school-demo-v1-refund-'.$returnCount, (int) $request->lines()->sum('refundable_centavos'), $mode, 'DEMO-REFUND-'.$returnCount);
                                    }
                                }
                                $returnCount++;
                            }
                            $saleCount++;
                        }
                    }
                    Carbon::setTestNow($anchor->utc());
                    foreach ($variants as $index => $variant) {
                        app(StockLedger::class)->adjustToCount($manager, $variant->refresh(), [18, 2, 0, 24, 9, 3, 16, 7, 2, 11, 32, 6][$index], 'School demo: sample closing stock count', 'school-demo-v1-count-'.$variant->id);
                    }
                    foreach ([$manager, $staff] as $cashier) {
                        $cart = Cart::create(['cashier_id' => $cashier->id, 'label' => 'DEMO · sample held order']);
                        $cart = app(CartService::class)->mutate($cashier, $cart->refresh(), $cart->refresh()->version, ['action' => 'add', 'variant_id' => $variants[0]->id, 'quantity' => 1]);
                        app(CartService::class)->hold($cashier, $cart, $cart->version);
                    }
                    Setting::create(['section' => 'demo_activity', 'value' => ['version' => 1, 'label' => 'Synthetic school demonstration records; no real payments', 'from' => $anchor->subDays(29)->toDateString(), 'through' => $anchor->toDateString(), 'sales_added' => $saleCount, 'returns_added' => $returnCount, 'variants_added' => count($variants)]]);
                    app(AuditRecorder::class)->record($manager, 'School demo data added', 'system', 'demo', 'school-demo-v1', 'success', ['reason' => 'Synthetic samples for graphs and tables; existing records retained']);
                    $this->command?->info(json_encode(['sales_added' => $saleCount, 'return_examples' => $returnCount, 'variants_added' => count($variants), 'from' => $anchor->subDays(29)->toDateString(), 'through' => $anchor->toDateString()]));
                });
            });
        } finally {
            Carbon::setTestNow($previousClock);
        }
    }

    /** @return list<Variant> */
    private function catalog(User $manager): array
    {
        $rows = [
            ['Engine', 'Air filter', 'Honda Click 125', 'AIR-01', 'Standard', 'pc', 24000],
            ['Brakes', 'Brake fluid DOT 4', 'Check manufacturer specification', 'FLUID-01', '250 mL', 'bottle', 18000],
            ['Controls', 'Throttle cable', 'Yamaha Mio', 'CABLE-01', 'Standard', 'pc', 21000],
            ['Electrical', 'Headlight bulb', '12 V · verify socket', 'BULB-01', '12 V / 35 W', 'pc', 9500],
            ['Lubricants', 'Gear oil', 'Scooter final drive', 'GEAR-01', '120 mL', 'bottle', 13000],
            ['Brakes', 'Disc rotor', 'Honda Click · verify year', 'ROTOR-01', 'Front', 'pc', 95000],
            ['Drivetrain', 'Clutch spring kit', 'Yamaha NMAX', 'CLUTCH-01', 'Standard', 'set', 45000],
            ['Electrical', 'Motorcycle battery', '12 V · verify fitment', 'BATTERY-01', 'Maintenance free', 'pc', 180000],
            ['Tires', 'Tubeless tire', 'Verify rim size and load rating', 'TIRE-F', '80/90-14 · front', 'pc', 135000],
            ['Tires', 'Tubeless tire', 'Verify rim size and load rating', 'TIRE-R', '90/90-14 · rear', 'pc', 160000],
            ['Accessories', 'Rain suit', 'Universal', 'RAIN-01', 'Black / L', 'set', 78000],
            ['Lubricants', 'Chain lubricant', 'Motorcycle chains', 'LUBE-01', '400 mL', 'bottle', 32000],
        ];
        $variants = [];
        foreach ($rows as [$category, $name, $compatibility, $sku, $variantName, $unit, $price]) {
            if (Variant::where('sku', 'DEMO-'.$sku)->exists()) {
                throw new RuntimeException('A reserved DEMO SKU already exists; no sample records were added.');
            }
            $categoryId = Category::firstOrCreate(['name' => $category])->id;
            $product = Product::firstOrCreate(['name' => $name, 'brand' => 'School demo'], ['category_id' => $categoryId, 'compatibility' => $compatibility, 'description' => 'Synthetic school demonstration sample. Not an actual shop product or transaction.']);
            $unitId = Unit::firstOrCreate(['code' => $unit], ['name' => ['pc' => 'Piece', 'set' => 'Set', 'bottle' => 'Sealed bottle'][$unit]])->id;
            $variant = Variant::create(['product_id' => $product->id, 'unit_id' => $unitId, 'sku' => 'DEMO-'.$sku, 'name' => $variantName, 'price_centavos' => $price, 'stock' => 0, 'threshold' => 3]);
            app(StockLedger::class)->receive($manager, $variant, 60, 'DEMO-DELIVERY-'.$sku, 'School demo: synthetic stock delivery', 'school-demo-v1-delivery-'.$variant->id);
            $variants[] = $variant->refresh();
        }

        return $variants;
    }
}
