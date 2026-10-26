@props(['variant'])
<article class="product-card">
<div class="product-media">@if($variant->product->image_path)<img class="product-photo" src="{{ route('products.image',$variant->product) }}" alt="{{ $variant->product->name }}">@else<img src="{{ asset('assets/icons/image-unavailable.svg') }}" width="24" height="24" alt=""><span>Image unavailable</span>@endif</div>
<div class="product-card-details"><h2>{{ $variant->product->name }}</h2><p class="caption">SKU: {{ $variant->sku }}</p><p>{{ $variant->name }} · {{ $variant->unit->code }}</p><p class="compatibility">{{ $variant->product->compatibility ?: 'Verify fitment before sale' }}</p><strong class="product-price">₱{{ \App\Support\Money::format($variant->price_centavos) }}</strong><div class="stock-indicator"><x-stock-badge :variant="$variant" /><span class="caption">{{ $variant->stock }} {{ $variant->unit->name }}</span></div></div>
<footer><a class="button button-secondary" href="{{ route('inventory.show',$variant) }}">View product</a></footer>
</article>
