@php($single=$product->variants->count()===1?$product->variants->first():null)
<article class="shop-product" data-product-card>
    <div class="shop-product-visual">
        @if($product->image_path)<img class="shop-product-image" src="{{ route('storefront.image',$product) }}" alt="{{ $product->name }}" width="480" height="320" loading="lazy" data-catalog-image>@endif
        <div class="shop-product-placeholder" @if($product->image_path) hidden @endif>@include('storefront.category-icon')<small>Photo pending</small></div>
    </div>
    <div class="shop-product-body">
        <p class="shop-product-brand">{{ $product->brand?:($product->category?->name??'Motorcycle parts') }}</p>
        <h3>{{ $product->name }}</h3>
        <p class="shop-product-option">{{ $single?$single->name:$product->variants->count().' options available' }}</p>
        <p class="shop-compatibility">{{ $product->compatibility?\Illuminate\Support\Str::limit($product->compatibility,85):'Ask the shop to confirm compatibility.' }}</p>
        <div class="shop-product-bottom"><p class="shop-price">@if(!$single)<small>From</small> @endif₱{{ \App\Support\Money::display((int)$product->minimum_price) }}</p><p @class(['shop-availability','is-available'=>$product->available])>{{ $product->available?'Available':'Check availability' }}</p></div>
        <div class="shop-card-actions">
            <button class="button button-dark" type="button" data-product-open="{{ $product->id }}">{{ $single?'View details':'Choose option' }}</button>
            @if($single)<button class="shop-card-inquire" type="button" data-inquire data-topic="product" data-variant="{{ $single->id }}" data-subject="{{ $product->name }} · {{ $single->name }}">Ask the shop @include('storefront.arrow')</button>@endif
        </div>
    </div>
</article>
<template id="shop-product-{{ $product->id }}">
    <div class="shop-product-detail">
        <p class="shop-product-brand">{{ $product->brand }} · {{ $product->category?->name }}</p>
        <h3>{{ $product->name }}</h3>
        @if($product->description)<p class="shop-detail-description">{{ $product->description }}</p>@endif
        <dl><div><dt>Compatibility</dt><dd>{{ $product->compatibility?:'Contact the shop to confirm the correct fit.' }}</dd></div>@if($product->part_number)<div><dt>Part number</dt><dd>{{ $product->part_number }}</dd></div>@endif</dl>
        <div class="field"><label for="detail-option-{{ $product->id }}">Choose a product option</label><select id="detail-option-{{ $product->id }}" data-product-option><option value="">Select an option</option>@foreach($product->variants as $option)<option value="{{ $option->id }}" data-subject="{{ $product->name }} · {{ $option->name }}" data-sku="{{ $option->sku }}" data-availability="{{ $option->stock>0?'Available · confirm with shop':'Currently unavailable · ask the shop' }}">{{ $option->name }} · ₱{{ \App\Support\Money::display($option->price_centavos) }}</option>@endforeach</select></div>
        <p class="shop-option-info" data-product-option-info>Choose an option to see its availability and ask a question.</p>
        <p class="shop-small-note">Include your motorcycle model and year when asking about compatibility.</p>
        <button type="button" class="button button-primary" data-selected-inquiry disabled>Ask about selected option</button>
    </div>
</template>
