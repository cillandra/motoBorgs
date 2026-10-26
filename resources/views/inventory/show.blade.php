@extends('layouts.app')
@section('title','Product inventory')
@section('content')
<header class="page-heading"><div><h1>Product details</h1><p>Product information and exact-variant availability.</p></div><a class="button button-secondary" href="{{ route('inventory.index') }}">Back to inventory</a></header>
<div class="product-detail-layout"><section class="panel product-overview">
<h2 class="icon-heading"><img src="{{ asset('assets/icons/inventory.svg') }}" width="24" height="24" alt="">Product details</h2>
<div class="product-media">@if($variant->product->image_path)<img class="product-photo" src="{{ route('products.image',$variant->product) }}" alt="{{ $variant->product->name }}">@else<img src="{{ asset('assets/icons/image-unavailable.svg') }}" width="24" height="24" alt=""><span>Image unavailable</span>@endif</div>
<h2>{{ $variant->product->name }}</h2><p>{{ $variant->sku }}</p><x-stock-badge :variant="$variant" /><strong class="detail-price">₱{{ \App\Support\Money::format($variant->price_centavos) }}</strong><p>Sold per {{ $variant->unit->name }}</p><p>{{ $variant->product->compatibility ?: 'Verify fitment before sale.' }}</p>
@if(auth()->user()->isManager())<hr>
@if(!$variant->product->archived_at)<p class="caption">Name, price, image and product details</p><a data-modal-link class="button button-secondary" href="{{ route('products.edit',$variant->product) }}">Edit product</a><hr><div class="lifecycle"><strong>Product lifecycle</strong><p>Hide from POS; keep transaction history.</p><x-button variant="danger" data-dialog-open="archive-product">Archive product</x-button></div>
@else<form action="{{ route('products.restore',$variant->product) }}" method="post">@csrf<x-button type="submit">Restore product</x-button></form>@endif
@endif
</section>
<div class="detail-right"><section class="panel stock-overview"><h2>Stock overview</h2><div class="stock-metrics"><div><p>Available stock</p><strong>{{ $variant->stock }} {{ $variant->unit->name }}</strong></div><div><p>Low-stock threshold</p><strong>{{ $variant->threshold }} per SKU</strong></div></div>
@if(auth()->user()->isManager() && !$variant->archived_at && Route::has('stock.form'))<hr><strong>Stock actions · Manager only</strong><div class="action-row"><a data-modal-link class="button button-primary" href="{{ route('stock.form',[$variant,'type'=>'receive']) }}">Receive stock</a><a data-modal-link class="button button-secondary" href="{{ route('stock.form',[$variant,'type'=>'adjust']) }}">Adjust stock</a></div>@endif
</section>
<section class="panel"><h2>Variants</h2><div class="table-scroll"><table><thead><tr><th>SKU</th><th>Variant</th><th>Price</th><th>Available</th><th>Status</th></tr></thead><tbody>@foreach($siblings as $sibling)<tr><td><a data-modal-link href="{{ route('inventory.show',$sibling) }}">{{ $sibling->sku }}</a></td><td>{{ $sibling->name }}</td><td>₱{{ \App\Support\Money::format($sibling->price_centavos) }}</td><td>{{ $sibling->stock }} {{ $sibling->unit->code }}</td><td><x-stock-badge :variant="$sibling" /></td></tr>@endforeach</tbody></table></div></section>
@if(auth()->user()->isManager() && View::exists('inventory.movements'))@include('inventory.movements',['movements'=>$variant->movements()->with('actor')->latest()->paginate(10)])@endif
</div></div>
@if(auth()->user()->isManager() && !$variant->product->archived_at)<x-dialog id="archive-product" title="Archive product?"><p>This product will be hidden from new sales. Its stock and history are retained.</p><form action="{{ route('products.archive',$variant->product) }}" method="post" class="stack">@csrf<x-input id="reason" label="Reason" required minlength="3" maxlength="1000" /><div class="action-row"><x-button variant="secondary" data-dialog-close>Cancel</x-button><x-button variant="danger" type="submit">Archive product</x-button></div></form></x-dialog>@endif
@endsection
