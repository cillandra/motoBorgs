<?php

namespace App\Services\Customer;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class StorefrontCatalog
{
    public function search(array $filters): LengthAwarePaginator
    {
        $active = fn ($query) => $query->whereNull('archived_at');
        $query = Product::select(['id', 'category_id', 'name', 'brand', 'part_number', 'description', 'compatibility', 'image_path'])
            ->whereNull('archived_at')->whereHas('variants', $active)
            ->with(['category:id,name', 'variants' => fn ($query) => $query->select(['id', 'product_id', 'sku', 'name', 'price_centavos', 'stock'])->whereNull('archived_at')->orderBy('price_centavos')->orderBy('id')])
            ->withMin(['variants as minimum_price' => $active], 'price_centavos')
            ->withExists(['variants as available' => fn ($query) => $query->whereNull('archived_at')->where('stock', '>', 0)])
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category_id', $category))
            ->when(($filters['availability'] ?? 'all') === 'available', fn ($query) => $query->whereHas('variants', fn ($query) => $query->whereNull('archived_at')->where('stock', '>', 0)));
        foreach (preg_split('/\s+/u', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $pattern = '%'.addcslashes($term, '\\%_').'%';
            $query->where(function ($query) use ($pattern): void {
                $query->where('name', 'like', $pattern)->orWhere('brand', 'like', $pattern)->orWhere('part_number', 'like', $pattern)->orWhere('compatibility', 'like', $pattern)->orWhere('description', 'like', $pattern)
                    ->orWhereHas('variants', fn ($query) => $query->whereNull('archived_at')->where(fn ($query) => $query->where('sku', 'like', $pattern)->orWhere('name', 'like', $pattern)));
            });
        }
        match ($filters['sort'] ?? 'available') {
            'price_asc' => $query->orderBy('minimum_price'),
            'price_desc' => $query->orderByDesc('minimum_price'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('available')->orderBy('name'),
        };

        return $query->orderBy('id')->paginate(6)->withQueryString();
    }

    public function categories(): Collection
    {
        return Category::whereIn('id', Product::select('category_id')->whereNull('archived_at')->whereHas('variants', fn ($query) => $query->whereNull('archived_at')))->orderBy('name')->get(['id', 'name']);
    }
}
