@extends('layouts.app')
@section('title','Product inventory')
@section('content')
<div class="inventory-page">
<header class="page-heading"><div><h1>Product inventory</h1><p>{{ auth()->user()->isManager() ? 'Manage products, variants and stock availability.' : 'Browse product details, prices and availability.' }}</p></div></header>
<div class="inventory-tabs"><nav class="tabs" aria-label="Inventory groups">
<a @class(['tab','active'=>empty($filters['archived']) && empty($filters['availability'])]) href="{{ route('inventory.index',array_merge($filters,['archived'=>0,'availability'=>'','page'=>1])) }}">All products</a>
<a @class(['tab','active'=>($filters['availability']??'')==='low']) href="{{ route('inventory.index',array_merge($filters,['archived'=>0,'availability'=>'low','page'=>1])) }}">Low stock</a>
@if(auth()->user()->isManager())<a @class(['tab','active'=>!empty($filters['archived'])]) href="{{ route('inventory.index',array_merge($filters,['archived'=>1,'availability'=>'','page'=>1])) }}">Archived</a>@endif
</nav><div class="view-switch"><a @class(['active'=>($filters['view']??'cards')==='cards']) href="{{ route('inventory.index',array_merge($filters,['view'=>'cards'])) }}">Cards</a><a @class(['active'=>($filters['view']??'')==='table']) href="{{ route('inventory.index',array_merge($filters,['view'=>'table'])) }}">Table</a></div></div>
<form class="inventory-filters" method="get" action="{{ route('inventory.index') }}" data-filter-form>
<input type="hidden" name="view" value="{{ $filters['view']??'cards' }}">@if(!empty($filters['archived']))<input type="hidden" name="archived" value="1">@endif
<x-input id="q" label="Search products" :value="$filters['q']??''" placeholder="Search name or SKU" class="search-input" />
<div class="field"><label for="category_id">Category</label><div class="field-perimeter"><select id="category_id" name="category_id" data-auto-filter><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category_id']??'')==$category->id)>{{ $category->name }}</option>@endforeach</select></div></div>
<div class="field"><label for="availability">Availability</label><div class="field-perimeter"><select id="availability" name="availability" data-auto-filter><option value="">All stock</option>@foreach(['in'=>'In stock','low'=>'Low stock','out'=>'Out of stock'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['availability']??'')===$value)>{{ $label }}</option>@endforeach</select></div></div>
<div class="field"><label for="sort">Sort</label><div class="field-perimeter"><select id="sort" name="sort" data-auto-filter>@foreach(['name_asc'=>'Name A–Z','name_desc'=>'Name Z–A','sku'=>'SKU A–Z','price_asc'=>'Price: low to high','price_desc'=>'Price: high to low','stock_asc'=>'Stock: low to high','stock_desc'=>'Stock: high to low'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['sort']??'name_asc')===$value)>{{ $label }}</option>@endforeach</select></div></div>
<a class="button button-secondary clear-filters" href="{{ route('inventory.index',['view'=>$filters['view']??'cards']) }}">Clear</a>
</form>
@if($errors->any())<div class="inline-alert error" role="alert">{{ $errors->first() }}</div>@endif
<p class="result-summary">{{ $variants->total() }} sellable variants found</p>
@if(($filters['view']??'cards')==='table')
<section class="panel table-panel"><table><thead><tr><th>Product / SKU</th><th>Variant</th><th>Category</th><th>Price</th><th>Available</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($variants as $variant)<tr><td><strong>{{ $variant->product->name }}</strong><small>{{ $variant->sku }}</small></td><td>{{ $variant->name }}</td><td>{{ $variant->product->category?->name??'Uncategorized' }}</td><td>₱{{ \App\Support\Money::display($variant->price_centavos) }}</td><td>{{ $variant->stock }} {{ $variant->unit->code }}</td><td><x-stock-badge :variant="$variant" /></td><td><a data-modal-link class="button button-secondary" href="{{ route('inventory.show',$variant) }}">View</a></td></tr>@empty<tr><td colspan="7"><x-empty-state /></td></tr>@endforelse
</tbody></table>@if(auth()->user()->isManager())<a data-modal-link class="button button-primary table-add-product" href="{{ route('products.create') }}">Add product</a>@endif</section>
@else
<div class="inventory-grid">@if(auth()->user()->isManager() && empty($filters['archived']))<a data-modal-link class="add-product-tile" href="{{ route('products.create') }}"><span aria-hidden="true">+</span><strong>Add product</strong><p>Create a new product and variant</p></a>@endif
@foreach($variants as $variant)<x-product-card :variant="$variant" />@endforeach</div>
@if($variants->isEmpty())<x-empty-state title="No matching products" message="Try another search or clear your filters." />@endif
@endif
{{ $variants->links('components.pagination') }}
</div>
@endsection
