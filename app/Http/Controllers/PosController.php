<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Variant;
use App\Services\Catalog\CatalogQuery;
use App\Services\Pos\CartService;
use App\Services\System\OperationGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function index(Request $request, CartService $service, CatalogQuery $catalog): mixed
    {
        $data = $request->validate(['cart' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer'], 'availability' => ['nullable', 'in:in,low,out'],
            'sort' => ['nullable', 'in:name_asc,name_desc,price_asc,price_desc,sku,stock_asc,stock_desc'],
            'brand' => ['nullable', 'string', 'max:100'], 'compatibility' => ['nullable', 'string', 'max:200']]);
        $cart = ! empty($data['cart']) ? Cart::findOrFail($data['cart']) : Cart::where('cashier_id', $request->user()->id)->where('status', 'draft')->latest('id')->first();
        $cart ??= app(OperationGuard::class)->write(fn () => Cart::create(['cashier_id' => $request->user()->id])->refresh());
        $service->owned($request->user(), $cart);
        if ($cart->status === 'completed') {
            return redirect()->route('sales.receipt', Sale::where('cart_id', $cart->id)->firstOrFail());
        }
        abort_unless($cart->status === 'draft', 409, 'Resume a held cart, or start a new sale.');
        $quote = $service->quote($request->user(), $cart);
        $filters = array_diff_key($data, ['cart' => true]);
        $catalogPage = $catalog->forPos($request->user(), $filters, 24);
        $products = $catalogPage['products'];
        $variantCount = $catalogPage['variant_count'];
        $categories = Category::orderBy('name')->get();
        $discountCap = max(0, min(10, (int) (Setting::where('section', 'sales')->first()?->value['discount_cap'] ?? 10)));
        $heldCarts = Cart::where('cashier_id', $request->user()->id)->where('status', 'held')->withCount('lines')->latest()->get();

        return view('pos.index', compact('cart', 'quote', 'filters', 'products', 'variantCount', 'categories', 'heldCarts', 'discountCap'));
    }

    public function lookup(Request $request): mixed
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $variant = Variant::where(fn ($q) => $q->where('sku', $data['code'])->orWhere('barcode', $data['code']))
            ->whereNull('archived_at')->whereHas('product', fn ($q) => $q->whereNull('archived_at'))->limit(2)->get(['id', 'sku', 'stock']);
        if ($variant->count() > 1) {
            throw ValidationException::withMessages(['code' => 'This code matches more than one variant. Ask your Manager to correct the SKU/barcode before adding an item.']);
        }
        abort_if($variant->isEmpty(), 404);
        $variant = $variant->first();

        return response()->json(['variant_id' => $variant->id, 'sku' => $variant->sku, 'stock' => $variant->stock]);
    }
}
