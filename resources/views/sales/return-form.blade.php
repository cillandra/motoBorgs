@extends('layouts.app')
@section('title','Sales history')
@section('content')
<header class="page-heading"><div><h1>Return items</h1><p>Sale MOTO-{{ str_pad($sale->id,8,'0',STR_PAD_LEFT) }} · {{ $sale->cashier_name }}</p></div><a data-modal-link class="button button-secondary" href="{{ Route::has('sales.show')?route('sales.show',$sale):route('sales.receipt',[$sale,'copy'=>1]) }}">Back to transaction</a></header>
<div class="information-notice"><img src="{{ asset('assets/icons/info.svg') }}" alt=""><div><strong>Manager approval required</strong><p>Check the items, quantities and reason. Keep the original sale record. Sellable stock and refund payments are reviewed separately.</p></div></div>
<form action="{{ route('returns.store',$sale) }}" method="post" class="return-workspace" data-return-request-form>@csrf
<section class="panel return-information stack"><x-section-heading tag="h2" icon="receipt" tone="success">Items being returned</x-section-heading>
@foreach($sale->lines as $line)
@php($counts=$quantities[$line->id])
@php($available=$counts['pending']>0?0:max(0,$line->quantity-$counts['accepted']))
<article class="return-item"><div class="return-item-title"><strong>{{ $line->product_name }} · {{ $line->variant_name }}</strong><span>{{ $line->sku }}</span></div><p>Purchased: {{ $line->quantity }} {{ $line->unit }} · Already returned: {{ $counts['accepted'] }} · Pending: {{ $counts['pending'] }}</p>
<x-input id="return-quantity-{{ $line->id }}" name="lines[{{ $line->id }}]" label="Return quantity" type="number" min="0" :max="$available" :value="old('lines.'.$line->id,$sale->lines->count()===1 && $available>0?1:0)" step="1" data-return-quantity :data-price="$line->price_centavos" :data-discount="$line->discount_centavos" :data-sold="$line->quantity" :data-accepted="$counts['accepted']" />
<p class="caption">Available to request: {{ $available }} {{ $line->unit }}. Use zero for items you are keeping.</p></article>
@endforeach
<div class="field"><label for="return-reason">Return reason <span class="required">*</span></label><div class="field-perimeter"><textarea id="return-reason" name="reason" required minlength="3" maxlength="1000">{{ old('reason') }}</textarea></div></div>
</section>
<aside class="stack"><section class="panel return-summary stack"><x-section-heading tag="h2" icon="receipt" tone="success">Refund summary</x-section-heading><div><span>Selected units</span><strong data-return-units>—</strong></div><div><span>Estimated refund</span><strong data-return-estimate>—</strong></div><div><span>Original payment</span><strong>{{ ['cash'=>'Cash','gcash'=>'GCash','qrph'=>'QR Ph','debit'=>'Debit card','credit'=>'Credit card'][$sale->payment->method] }}</strong></div><p>Discounts on the original sale are included. The final eligible amount is recorded when the Manager approves the return.</p></section><section class="panel stack"><x-section-heading tag="h2" icon="receipt" tone="success">Return request</x-section-heading><x-button type="submit" data-return-submit>Submit for review</x-button><p>Returned quantities cannot exceed the original sale. Pending requests block duplicate requests for those units.</p></section></aside>
</form>
@endsection
