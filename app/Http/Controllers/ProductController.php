<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Catalog\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function create(): mixed
    {
        return view('inventory.form', ['product' => new Product, 'categories' => Category::orderBy('name')->get(), 'units' => Unit::all()]);
    }

    public function edit(Product $product): mixed
    {
        return view('inventory.form', ['product' => $product->load('variants.unit'), 'categories' => Category::orderBy('name')->get(), 'units' => Unit::all()]);
    }

    public function store(ProductRequest $request, ProductService $service): mixed
    {
        $product = $service->save($request->user(), null, $request->validated());

        return $request->expectsJson() ? response()->json($product, 201) : redirect()->route('inventory.show', $product->variants->first())->with('status', 'Product created.');
    }

    public function update(ProductRequest $request, Product $product, ProductService $service): mixed
    {
        $product = $service->save($request->user(), $product, $request->validated());

        return $request->expectsJson() ? response()->json($product) : redirect()->route('inventory.show', $product->variants->first())->with('status', 'Product updated.');
    }

    public function archive(Request $request, Product $product, ProductService $service): mixed
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $service->archive($request->user(), $product, $data['reason']);

        return redirect()->route('inventory.index')->with('status', 'Product archived; history retained.');
    }

    public function restore(Request $request, Product $product, ProductService $service): mixed
    {
        $service->restore($request->user(), $product);

        return redirect()->route('inventory.index')->with('status', 'Product restored.');
    }

    public function image(Request $request, Product $product): mixed
    {
        abort_if(! $request->user()->isManager() && $product->archived_at, 404);
        abort_unless($product->image_path && Storage::disk('local')->exists($product->image_path), 404);

        return response()->file(Storage::disk('local')->path($product->image_path), ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300']);
    }
}
