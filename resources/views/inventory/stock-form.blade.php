@extends('layouts.app')
@section('title','Product inventory')
@section('content')
<header class="page-heading"><div><h1>{{ $type==='adjust'?'Adjust stock':'Receive stock' }}</h1><p>Record the reason for every stock change.</p></div><a class="button button-secondary" href="{{ route('inventory.show',$variant) }}">Back to product</a></header>
<form method="post" action="{{ route('stock.store',$variant) }}" class="panel stock-form" data-stock-form data-balance="{{ $variant->stock }}">
@csrf<input type="hidden" name="base_stock" value="{{ $variant->stock }}">
<input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="operation_key" value="{{ old('operation_key',(string) \Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="expected_version" value="{{ $variant->version }}">
<h2>{{ $variant->product->name }} · {{ $variant->sku }}</h2><p class="stock-current">Current stock: {{ $variant->stock }} {{ $variant->unit->name }}</p>
@if($errors->any())<div class="inline-alert error" role="alert">{{ $errors->first() }}</div>@endif
@if($type==='adjust')<div class="field"><label for="adjustment_mode">Adjustment type</label><div class="field-perimeter"><select id="adjustment_mode" name="adjustment_mode"><option value="count" @selected(old('adjustment_mode','count')==='count')>Set to counted stock</option><option value="increase" @selected(old('adjustment_mode')==='increase')>Increase stock</option><option value="decrease" @selected(old('adjustment_mode')==='decrease')>Decrease stock</option></select></div></div>@endif
<div class="stock-entry-row"><div><x-input id="quantity" label="{{ $type==='adjust'?'Count or quantity to change':'Quantity received' }}" type="number" :value="old('quantity')" min="{{ $type==='receive'?1:0 }}" max="1000000" step="1" required /><p class="caption">{{ $variant->unit->name }} · whole quantities only</p></div>
@if($type==='receive')<x-input id="reference" label="Delivery reference" :value="old('reference')" required maxlength="100" /><x-input id="date" label="Received date" type="date" :value="old('date',now('Asia/Manila')->toDateString())" max="{{ now('Asia/Manila')->toDateString() }}" />@endif</div>
<div class="field"><label for="reason">Reason <span class="required">*</span></label><div class="field-perimeter"><textarea id="reason" name="reason" minlength="3" maxlength="1000" required placeholder="Reason">{{ old('reason') }}</textarea></div><p class="caption">This reason is saved in Employee activity logs.</p></div>
<strong data-stock-preview>Enter a quantity to preview the resulting balance.</strong>
<div class="action-row"><a class="button button-secondary" href="{{ route('inventory.show',$variant) }}">Cancel</a><x-button type="submit">Save stock change</x-button></div>
</form>
@endsection
