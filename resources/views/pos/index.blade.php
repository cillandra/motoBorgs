@extends('layouts.app')
@section('title','Sales / POS')
@section('content')
<div class="pos-page" data-pos data-open-held="{{ request()->boolean('held')?'1':'0' }}" data-cart="{{ $cart->id }}" data-version="{{ $cart->version }}" data-mutate="{{ route('pos.mutate',$cart) }}" data-lookup="{{ route('pos.lookup') }}">
<header class="page-heading"><div><h1>Sales / POS</h1><p>{{ auth()->user()->name }} · {{ ucfirst(auth()->user()->role) }} access</p></div><x-button variant="secondary" data-dialog-open="held-carts">Held carts ({{ $heldCarts->count() }})</x-button></header>
<div class="inline-alert error" data-pos-error role="alert" hidden></div>
@if($errors->any())<div class="inline-alert error" role="alert">{{ $errors->first() }}</div>@endif
<div class="pos-columns"><section class="pos-catalog" aria-label="Product catalog">
<form action="{{ route('pos.index') }}" method="get" class="pos-filters" data-filter-form>
<input type="hidden" name="cart" value="{{ $cart->id }}">
<div class="pos-search-row"><x-input id="q" label="Search products" :value="$filters['q']??''" placeholder="Name, SKU or part number" class="search-input" /><x-button variant="secondary" data-dialog-open="pos-more-filters"><img src="{{ asset('assets/icons/list-filter.svg') }}" alt="">More filters</x-button></div>
<div class="pos-filter-row">
<div class="field"><label for="category_id">Category</label><div class="field-perimeter"><select id="category_id" name="category_id" data-auto-filter><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category_id']??'')==$category->id)>{{ $category->name }}</option>@endforeach</select></div></div>
<div class="field"><label for="availability">Availability</label><div class="field-perimeter"><select id="availability" name="availability" data-auto-filter><option value="">All stock</option>@foreach(['in'=>'In stock','low'=>'Low stock','out'=>'Out of stock'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['availability']??'')===$value)>{{ $label }}</option>@endforeach</select></div></div>
<div class="field"><label for="sort">Sort by</label><div class="field-perimeter"><select id="sort" name="sort" data-auto-filter>@foreach(['name_asc'=>'Name A–Z','price_asc'=>'Price: low to high','price_desc'=>'Price: high to low'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['sort']??'name_asc')===$value)>{{ $label }}</option>@endforeach</select></div></div>
</div><input type="hidden" name="brand" value="{{ $filters['brand']??'' }}"><input type="hidden" name="compatibility" value="{{ $filters['compatibility']??'' }}">
</form>
<div class="pos-result-row"><span>{{ $variants->total() }} variants <small>PHP · Photos pending</small></span><a class="button button-secondary" href="{{ route('pos.index',['cart'=>$cart->id]) }}">Clear filters</a></div>
<details class="barcode-entry"><summary>Scan / enter exact SKU or barcode</summary><form data-barcode-form><label for="barcode">Barcode or SKU</label><div class="action-row"><input class="form-input" id="barcode" autocomplete="off" required maxlength="100"><x-button type="submit" variant="secondary">Add exact SKU</x-button></div></form></details>
<div class="pos-products">
@forelse($variants->groupBy('product_id') as $group)
@php($variant=$group->first())
<article class="pos-product" data-stock="{{ $group->count()>1?'mixed':($variant->stock===0?'out':($variant->stock<=$variant->threshold?'low':'in')) }}">
<div class="product-media">@if($variant->product->image_path)<img class="product-photo" src="{{ route('products.image',$variant->product) }}" alt="{{ $variant->product->name }}">@else<img src="{{ asset('assets/icons/image-unavailable.svg') }}" alt=""><span>Image unavailable</span>@endif</div>
<div class="pos-product-info"><div class="product-card-copy"><h2>{{ $variant->product->name }}</h2><p>{{ $group->count()>1 ? $group->count().' variants' : 'SKU: '.$variant->sku }}</p><p>{{ $group->count()>1 ? 'Choose exact model / size' : $variant->name.' · sold per '.$variant->unit->code }}</p><p>{{ $variant->product->compatibility ?: 'Verify fitment before sale' }}</p></div><div class="product-card-summary"><strong class="pos-price">{{ $group->count()>1?'From ':'' }}₱{{ \App\Support\Money::format($group->min('price_centavos')) }}</strong>@if($group->count()>1)<span class="status-badge">Stock varies by variant</span>@else<div class="pos-availability"><x-stock-badge :variant="$variant" /><span>{{ $variant->stock }} {{ $variant->unit->code }}</span></div>@endif</div></div>
<div class="pos-product-action">
@if($group->count()>1)<x-button data-dialog-open="variants-{{ $variant->product_id }}" :disabled="$group->sum('stock')===0">Choose variant</x-button>
@else<form method="post" action="{{ route('pos.mutate',$cart) }}" data-pos-form>@csrf<input type="hidden" name="version" value="{{ $cart->version }}"><input type="hidden" name="action" value="add"><input type="hidden" name="variant_id" value="{{ $variant->id }}"><input type="hidden" name="quantity" value="1"><x-button type="submit" :disabled="$variant->stock===0">{{ $variant->stock===0?'Unavailable':'Add to cart' }}</x-button></form>@endif
</div></article>
@if($group->count()>1)<x-dialog id="variants-{{ $variant->product_id }}" title="Choose variant"><p>{{ $variant->product->name }} · select the exact model and size.</p><div class="stack variant-options">@foreach($group as $option)<form class="variant-option" method="post" action="{{ route('pos.mutate',$cart) }}" data-pos-form>@csrf<input type="hidden" name="version" value="{{ $cart->version }}"><input type="hidden" name="action" value="add"><input type="hidden" name="variant_id" value="{{ $option->id }}"><input type="hidden" name="quantity" value="1"><div><strong>{{ $option->name }}</strong><p>{{ $option->sku }} · ₱{{ \App\Support\Money::format($option->price_centavos) }} · {{ $option->stock }} {{ $option->unit->code }}</p></div><x-button type="submit" :disabled="$option->stock===0">{{ $option->stock===0?'Unavailable':'Add variant' }}</x-button></form>@endforeach</div></x-dialog>@endif
@empty<x-empty-state title="No products match filters" message="Try another name, SKU or filter." />@endforelse
</div>{{ $variants->links('components.pagination') }}
</section>
@include('pos.partials.cart')
</div>
@include('pos.partials.held-carts')
@include('pos.partials.approval')
@include('pos.partials.payment')
<x-dialog id="pos-more-filters" title="More filters"><form method="get" action="{{ route('pos.index') }}" class="stack"><input type="hidden" name="cart" value="{{ $cart->id }}">@foreach(['q','category_id','availability','sort'] as $key)<input type="hidden" name="{{ $key }}" value="{{ $filters[$key]??'' }}">@endforeach<x-input id="brand" label="Brand" :value="$filters['brand']??''" maxlength="100" /><x-input id="compatibility" label="Motorcycle model / compatibility" :value="$filters['compatibility']??''" maxlength="200" /><div class="action-row"><x-button variant="secondary" data-dialog-close>Back</x-button><x-button type="submit">Apply filters</x-button></div></form></x-dialog>
</div>
@endsection
