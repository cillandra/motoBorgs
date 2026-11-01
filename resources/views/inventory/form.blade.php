@extends('layouts.app')
@section('title','Product inventory')
@section('content')
@php
$editing = $product->exists;
$rows = old('variants', $editing ? $product->variants->whereNull('archived_at')->map(fn($variant)=>['id'=>$variant->id,'sku'=>$variant->sku,'name'=>$variant->name,'unit_id'=>$variant->unit_id,'price'=>\App\Support\Money::format($variant->price_centavos),'threshold'=>$variant->threshold,'barcode'=>$variant->barcode])->values()->all() : [['sku'=>'','name'=>'Standard','unit_id'=>$units->first()?->id,'price'=>'','threshold'=>$defaultThreshold]]);
$first = $rows[0] ?? ['sku'=>'','name'=>'Standard','price'=>'','threshold'=>$defaultThreshold,'unit_id'=>$units->first()?->id];
@endphp
<header class="page-heading"><div><h1>{{ $editing?'Edit product':'Add product' }}</h1><p>{{ $editing?'Update product information; existing sales retain their original details.':'Create a product with a unique SKU and selling unit.' }}</p></div><a class="button button-secondary" href="{{ route('inventory.index') }}">Back to inventory</a></header>
<form method="post" enctype="multipart/form-data" action="{{ $editing?route('products.update',$product):route('products.store') }}" class="panel product-form" data-product-form data-image-limit="{{ $maxImageMb }}">
@csrf @if($editing)@method('PUT')@endif
<x-section-heading tag="h2" icon="inventory" tone="violet">{{ $editing?'Product information':'New product' }}</x-section-heading>
@if($errors->any())<div class="inline-alert error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="product-form-workspace">
<div class="image-upload"><x-section-heading tag="h3" icon="inventory" tone="violet">Product image (optional)</x-section-heading><label class="image-drop" for="image" data-image-drop><img id="image-preview" src="{{ $product->image_path?route('products.image',$product):asset('assets/icons/image-unavailable.svg') }}" class="{{ $product->image_path?'selected-image':'fallback-image' }}" alt=""><strong>Drop a product image here</strong><p>or select a file from your computer</p></label><input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp" data-image-input class="visually-hidden" aria-label="Product image"><button type="button" class="button button-primary" data-image-choose>Upload image</button><p id="image-feedback" role="status"></p><p>JPG, PNG or WebP · up to {{ $maxImageMb }} MB<br>Recommended: 1024 × 1024 px.<br>Keep the whole product visible.</p></div>
<div class="product-form-fields">
<x-section-heading tag="h3" icon="inventory" tone="violet">General product details</x-section-heading><p class="measure-note">Each product option (variant), such as a size or motorcycle model, needs its own unique product code (SKU).</p>
<div class="form-row"><x-input id="product-name" name="name" label="Product name" :value="old('name',$product->name)" required maxlength="160" /><x-input id="sku-0" name="variants[0][sku]" label="Product code (SKU)" :value="$first['sku']" required maxlength="100" /></div>
@if(!empty($first['id']))<input type="hidden" name="variants[0][id]" value="{{ $first['id'] }}">@endif
<div class="form-row"><div class="field"><label for="category">Category</label><div class="field-perimeter"><select id="category" name="category_id"><option value="">Uncategorized</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id',$product->category_id)==$category->id)>{{ $category->name }}</option>@endforeach</select></div></div><div class="field"><label for="unit-0">Selling unit <span class="required">*</span></label><div class="field-perimeter"><select id="unit-0" name="variants[0][unit_id]" required>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(($first['unit_id']??'')==$unit->id)>{{ $unit->name }}</option>@endforeach</select></div></div></div>
<div class="form-row"><x-input id="variant-0" name="variants[0][name]" label="Variant name" :value="$first['name']??'Standard'" required /><x-input id="compatibility" label="Compatibility" :value="old('compatibility',$product->compatibility)" maxlength="2000" /></div>
<hr><x-section-heading tag="h3" icon="inventory" tone="violet">Pricing and stock alerts</x-section-heading>
<div class="form-row"><x-input id="price-0" name="variants[0][price]" label="Selling price (₱)" :value="$first['price']" inputmode="decimal" required /><x-input id="threshold-0" name="variants[0][threshold]" label="Low-stock alert level" type="number" :value="$first['threshold']" min="0" max="1000000" required /></div>
<p>Stock is changed only through recorded stock movements.</p>
<details class="additional-fields"><summary>Additional product details</summary><div class="form-row"><x-input id="brand" label="Brand" :value="old('brand',$product->brand)" maxlength="100" /><x-input id="part_number" label="Part number" :value="old('part_number',$product->part_number)" maxlength="100" /></div><div class="form-row"><x-input id="barcode-0" name="variants[0][barcode]" label="Barcode (optional)" :value="$first['barcode']??''" maxlength="100" /><x-input id="category_name" label="New category (optional)" :value="old('category_name')" maxlength="100" /></div><label for="description">Description</label><textarea id="description" name="description" maxlength="5000">{{ old('description',$product->description) }}</textarea></details>
</div></div>
<div id="additional-variants" class="additional-variants">@foreach(array_slice($rows,1,null,true) as $index=>$row)@include('inventory.variant-fields',compact('index','row','units'))@endforeach</div>
<x-button variant="secondary" data-add-variant>Add another product option</x-button>
<template id="variant-template">@include('inventory.variant-fields',['index'=>'__INDEX__','row'=>['sku'=>'','name'=>'Standard','price'=>'','threshold'=>$defaultThreshold,'unit_id'=>$units->first()?->id],'units'=>$units])</template>
<footer class="form-actions"><p>Required fields are marked *. Product image is optional.</p><div class="action-row"><a class="button button-secondary" href="{{ route('inventory.index') }}">Cancel</a><x-button type="submit">Save product</x-button></div></footer>
</form>
@endsection
