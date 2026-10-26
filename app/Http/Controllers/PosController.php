<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Variant;
use App\Services\Catalog\CatalogQuery;
use App\Services\Pos\CartService;
use Illuminate\Http\Request;

class PosController extends Controller
{
    public function index(Request $request, CartService $service, CatalogQuery $catalog): mixed
    {
        $data = $request->validate(['cart' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer'], 'availability' => ['nullable', 'in:in,low,out'],
            'sort' => ['nullable', 'in:name_asc,name_desc,price_asc,price_desc,sku,stock_asc,stock_desc'],
            'brand' => ['nullable', 'string', 'max:100'], 'compatibility' => ['nullable', 'string', 'max:200']]);
        $cart = ! empty($data['cart']) ? Cart::findOrFail($data['cart']) : Cart::where('cashier_id', $request->user()->id)->where('status', 'draft')->latest('id')->first();
        $cart ??= Cart::create(['cashier_id' => $request->user()->id])->refresh();
        $service->owned($request->user(), $cart);
        abort_unless($cart->status === 'draft', 409, 'Resume a held cart, or start a new sale.');
        $quote = $service->quote($request->user(), $cart);
        $filters = array_diff_key($data, ['cart' => true]);
        $variants = $catalog->search($request->user(), $filters, 24);
        $categories = Category::orderBy('name')->get();
        $heldCarts = Cart::where('cashier_id', $request->user()->id)->where('status', 'held')->withCount('lines')->latest()->get();

        return view('pos.index', compact('cart', 'quote', 'filters', 'variants', 'categories', 'heldCarts'));
    }

    public function lookup(Request $request): mixed
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $variant = Variant::where(fn ($q) => $q->where('sku', $data['code'])->orWhere('barcode', $data['code']))
            ->whereNull('archived_at')->whereHas('product', fn ($q) => $q->whereNull('archived_at'))->firstOrFail();

        return response()->json(['variant_id' => $variant->id, 'sku' => $variant->sku, 'stock' => $variant->stock]);
    }
}
