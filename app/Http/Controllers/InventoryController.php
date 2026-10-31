<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Variant;
use App\Services\Catalog\CatalogQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InventoryController extends Controller
{
    public function index(Request $request, CatalogQuery $catalog): mixed
    {
        $filters = $request->validate(['q' => 'nullable|string|max:160', 'category_id' => 'nullable|integer|exists:categories,id', 'availability' => 'nullable|in:in,low,out', 'sort' => 'nullable|in:name_asc,name_desc,sku,price_asc,price_desc,stock_asc,stock_desc', 'view' => 'nullable|in:cards,table', 'archived' => 'nullable|boolean', 'brand' => 'nullable|string|max:100', 'compatibility' => 'nullable|string|max:200']);
        $variants = $catalog->search($request->user(), $filters, (int) ($request->user()->preference?->value['rows'] ?? 20));

        return $request->expectsJson() ? response()->json($variants) : view('inventory.index', ['variants' => $variants, 'categories' => Category::orderBy('name')->get(), 'filters' => $filters]);
    }

    public function show(Request $request, Variant $variant): mixed
    {
        abort_unless(Gate::allows('view', $variant), 404);
        $variant->load(['product.category', 'unit']);
        $siblings = $variant->product->variants()->with('unit')->when(! $request->user()->isManager(), fn ($q) => $q->whereNull('archived_at'))->get();

        $movements = $request->user()->isManager() ? $variant->movements()->with('actor')->latest()->paginate(10) : null;

        return view('inventory.show', compact('variant', 'siblings', 'movements'));
    }
}
