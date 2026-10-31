<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CatalogQuery
{
    private function matchingVariants(User $actor, array $filters): Builder
    {
        abort_unless($actor->active, 403);
        $query = Variant::query()->with(['product.category', 'unit']);
        if (! $actor->isManager()) {
            $query->select(['id', 'product_id', 'unit_id', 'sku', 'barcode', 'name', 'price_centavos', 'stock', 'threshold', 'archived_at', 'version']);
        }
        if ($actor->isManager() && filter_var($filters['archived'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where(fn ($q) => $q->whereNotNull('archived_at')->orWhereHas('product', fn ($p) => $p->whereNotNull('archived_at')));
        } else {
            $query->whereNull('archived_at')->whereHas('product', fn ($p) => $p->whereNull('archived_at'));
        }
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($builder) use ($q): void {
                $builder->where('sku', 'like', '%'.$q.'%')->orWhere('barcode', $q)->orWhere('name', 'like', '%'.$q.'%')
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$q.'%')->orWhere('part_number', 'like', '%'.$q.'%'));
            });
        }
        if (! empty($filters['category_id'])) {
            $query->whereHas('product', fn ($p) => $p->where('category_id', $filters['category_id']));
        }
        foreach (['brand', 'compatibility'] as $field) {
            if (! empty($filters[$field])) {
                $query->whereHas('product', fn ($p) => $p->where($field, 'like', '%'.$filters[$field].'%'));
            }
        }
        match ($filters['availability'] ?? '') {
            'low' => $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'threshold'),
            'out' => $query->where('stock', 0),
            'in' => $query->where('stock', '>', 0),
            default => null,
        };

        return $query;
    }

    public function search(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->matchingVariants($actor, $filters);
        [$column, $direction] = match ($filters['sort'] ?? '') {
            'name_asc' => ['product_name', 'asc'],
            'name_desc' => ['product_name', 'desc'],
            'price_asc' => ['price_centavos', 'asc'],
            'price_desc' => ['price_centavos', 'desc'],
            'stock_asc' => ['stock', 'asc'],
            'stock_desc' => ['stock', 'desc'],
            'sku' => ['sku', 'asc'],
            default => ['product_name', 'asc'],
        };

        if ($column === 'product_name') {
            $column = Product::select('name')->whereColumn('products.id', 'variants.product_id');
        }

        return $query->orderBy($column, $direction)->orderBy('id')->paginate(max(1, min(100, $perPage)))->withQueryString();
    }

    /** @return array{products: LengthAwarePaginator, variant_count: int} */
    public function forPos(User $actor, array $filters, int $perPage = 24): array
    {
        return DB::transaction(fn () => $this->productPage($actor, $filters, $perPage));
    }

    private function productPage(User $actor, array $filters, int $perPage): array
    {
        $matching = $this->matchingVariants($actor, $filters);
        $variantIds = (clone $matching)->select('variants.id');
        $query = Product::query()->whereIn('id', (clone $matching)->select('product_id'))->with(['variants' => function ($variants) use ($variantIds, $actor): void {
            $variants->whereIn('variants.id', clone $variantIds)->with('unit')->orderBy('id');
            if (! $actor->isManager()) {
                $variants->select(['id', 'product_id', 'unit_id', 'sku', 'barcode', 'name', 'price_centavos', 'stock', 'threshold', 'archived_at', 'version']);
            }
        }]);
        [$column, $direction] = match ($filters['sort'] ?? 'name_asc') {
            'name_desc' => ['name', 'desc'],
            'price_asc' => ['price_centavos', 'asc'], 'price_desc' => ['price_centavos', 'desc'],
            'stock_asc' => ['stock', 'asc'], 'stock_desc' => ['stock', 'desc'],
            'sku' => ['sku', 'asc'], default => ['name', 'asc'],
        };
        if ($column === 'name') {
            $query->orderBy('name', $direction);
        } else {
            $query->withMin(['variants as catalog_sort' => fn ($variants) => $variants->whereIn('variants.id', clone $variantIds)], $column)->orderBy('catalog_sort', $direction);
        }

        return ['variant_count' => (clone $matching)->count(), 'products' => $query->orderBy('id')->paginate(max(1, min(100, $perPage)))->withQueryString()];
    }
}
