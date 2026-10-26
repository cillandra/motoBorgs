<?php

namespace App\Http\Requests;

use App\Models\Variant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isManager();
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;
        $rules = [
            'name' => ['required', 'string', 'max:160'], 'brand' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'category_name' => ['nullable', 'string', 'max:100'],
            'part_number' => ['nullable', 'string', 'max:100'], 'compatibility' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:5000'], 'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'integer', Rule::exists('variants', 'id')->where('product_id', $productId ?? 0)],
            'variants.*.sku' => ['required', 'string', 'max:100', 'distinct'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.unit_id' => ['required', 'integer', 'exists:units,id'],
            'variants.*.price' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,6})(?:\\.[0-9]{1,2})?$/D', 'numeric', 'min:0.01'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100', 'distinct'],
            'variants.*.threshold' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
        $variants = $this->input('variants', []);
        foreach (is_array($variants) ? $variants : [] as $index => $variant) {
            $id = is_array($variant) ? ($variant['id'] ?? null) : null;
            $owned = $id && $productId && Variant::where('id', $id)->where('product_id', $productId)->exists();
            $rules['variants.'.$index.'.sku'][] = Rule::unique('variants', 'sku')->ignore($owned ? $id : null);
            $rules['variants.'.$index.'.barcode'][] = Rule::unique('variants', 'barcode')->ignore($owned ? $id : null);
        }

        return $rules;
    }
}
