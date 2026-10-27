<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductService
{
    public function save(User $actor, ?Product $product, array $validated): Product
    {
        abort_unless($actor->isManager(), 403);
        $newImage = null;
        try {
            return DB::transaction(function () use ($actor, $product, $validated, &$newImage): Product {
                $data = Arr::only($validated, ['name', 'brand', 'category_id', 'part_number', 'compatibility', 'description']);
                if (! empty($validated['category_name'])) {
                    $data['category_id'] = Category::firstOrCreate(['name' => trim($validated['category_name'])])->id;
                }
                $isNew = $product === null;
                $product = $product ? Product::whereKey($product->id)->lockForUpdate()->firstOrFail() : new Product;
                $product->fill($data)->save();
                $ids = [];
                foreach ($validated['variants'] as $index => $row) {
                    $variant = ! empty($row['id']) ? $product->variants()->whereKey($row['id'])->lockForUpdate()->first() : $product->variants()->make();
                    if (! $variant) {
                        throw ValidationException::withMessages(['variants.'.$index.'.id' => 'This variant does not belong to the product.']);
                    }
                    $variant->threshold = $row['threshold'] ?? ($variant->threshold ?? (int) (Setting::where('section', 'inventory')->first()?->value['threshold'] ?? 3));
                    $variant->fill(Arr::only($row, ['sku', 'name', 'unit_id', 'threshold']));
                    $variant->barcode = $row['barcode'] ?? null;
                    $variant->price_centavos = Money::parse($row['price']);
                    $variant->version = ($variant->version ?? 0) + 1;
                    $variant->save();
                    $ids[] = $variant->id;
                }
                $product->variants()->whereNotIn('id', $ids)->whereNull('archived_at')->update(['archived_at' => now()]);
                if (! empty($validated['image'])) {
                    $newImage = app(ProductImageService::class)->replace($product, $validated['image']);
                }
                app(AuditRecorder::class)->record($actor, $isNew ? 'Product created' : 'Product updated', 'inventory', 'product', (string) $product->id, 'success', ['changes' => $data]);

                return $product->fresh(['variants.unit', 'category']);
            }, 3);
        } catch (Throwable $error) {
            if ($newImage) {
                Storage::disk('local')->delete($newImage);
            }
            throw $error;
        }
    }

    public function archive(User $actor, Product $product, string $reason = 'Manager archive confirmation'): void
    {
        abort_unless($actor->isManager(), 403);
        DB::transaction(function () use ($actor, $product, $reason): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($product->archived_at) {
                return;
            }
            $time = now()->startOfSecond();
            $product->update(['archived_at' => $time]);
            $product->variants()->whereNull('archived_at')->update(['archived_at' => $time]);
            app(AuditRecorder::class)->record($actor, 'Product archived', 'inventory', 'product', (string) $product->id, 'success', ['reason' => $reason]);
        }, 3);
    }

    public function restore(User $actor, Product $product): void
    {
        abort_unless($actor->isManager(), 403);
        DB::transaction(function () use ($actor, $product): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (! $product->archived_at) {
                return;
            }
            $product->variants()->where('archived_at', $product->archived_at)->update(['archived_at' => null]);
            $product->update(['archived_at' => null]);
            app(AuditRecorder::class)->record($actor, 'Product restored', 'inventory', 'product', (string) $product->id, 'success');
        }, 3);
    }
}
