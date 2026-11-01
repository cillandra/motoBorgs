@extends('layouts.app')
@section('title','Return requests')
@section('content')
<div class="return-queue">
<header class="page-heading"><div><h1>Return requests</h1><p>{{ auth()->user()->isManager()?'Review employee requests and keep approval decisions on record.':'Track return requests for your sales.' }}</p></div>@if(Route::has('sales.index'))<a class="button button-secondary" href="{{ route('sales.index') }}">Back to sales history</a>@endif</header>
<nav class="tabs" aria-label="Return status">@foreach([''=>'All requests','pending'=>'Pending','accepted'=>'Approved','rejected'=>'Rejected'] as $value=>$label)<a @class(['tab','active'=>($filters['status']??'')===$value]) href="{{ route('returns.index',['status'=>$value]) }}">{{ $label }}</a>@endforeach</nav>
<div class="return-request-list">
@forelse($returns as $return)
@php
    $paid=(int)$return->refunds->where('status','paid')->sum('amount_centavos');
    $eligible=(int)$return->lines->sum('refundable_centavos');
@endphp
<article class="panel return-request-card" data-return-state="{{ $return->status }}">
<header><h2>RET-{{ str_pad($return->id,6,'0',STR_PAD_LEFT) }} · MOTO-{{ str_pad($return->sale_id,8,'0',STR_PAD_LEFT) }}</h2><x-status-badge :status="$return->status" :label="['pending'=>'Pending','accepted'=>'Approved','rejected'=>'Rejected'][$return->status]" /></header>
<dl>
<div><dt>Requested by</dt><dd>{{ $return->requester->name }} · {{ $return->created_at->timezone('Asia/Manila')->format('d M Y, H:i') }}</dd></div>
<div><dt>Item / refund</dt><dd>@foreach($return->lines as $line)
@php
    $saleLine=$line->saleLine;
    $available=max(0,$saleLine->quantity-(int)$saleLine->accepted_return_quantity);
    $estimate=$return->status==='pending' && $available >= $line->quantity ? \App\Support\Money::refundForUnits($saleLine->quantity,$saleLine->price_centavos,$saleLine->discount_centavos,(int)$saleLine->accepted_return_quantity,$line->quantity) : null;
@endphp
<span>{{ $saleLine->product_name }} · {{ $saleLine->variant_name }} × {{ $line->quantity }} / {{ $return->status==='accepted' ? '₱'.\App\Support\Money::display($line->refundable_centavos) : ($estimate!==null ? '₱'.\App\Support\Money::display($estimate).' estimated' : 'No approved refund') }}</span>
@endforeach</dd></div>
<div><dt>Reason</dt><dd>{{ $return->reason }}</dd></div>
<div><dt>Condition</dt><dd>{{ $return->status==='pending'?'Awaiting inspection':($return->status==='rejected'?'Not accepted':$return->lines->map(fn($line)=>ucfirst($line->condition))->unique()->join(', ')) }}</dd></div>
</dl>
<div class="information-notice" data-tone="{{ ['pending'=>'warning','accepted'=>'success','rejected'=>'danger'][$return->status] }}"><img src="{{ asset('assets/icons/info.svg') }}" width="20" height="20" alt=""><div><strong>{{ ['pending'=>'Awaiting Manager decision','accepted'=>'Return approved','rejected'=>'Request rejected'][$return->status] }}</strong><p>{{ $return->status==='pending'?'No refund or stock change has been applied.':($return->status==='rejected'?$return->review_reason:'Eligible refund: ₱'.\App\Support\Money::display($eligible).' · Recorded paid: ₱'.\App\Support\Money::display($paid).'. Stock reflects sellable units only.') }}</p></div></div>
<footer><a data-modal-link class="button {{ $return->status==='pending' && auth()->user()->isManager()?'button-primary':'button-secondary' }}" href="{{ route('returns.show',$return) }}">{{ $return->status==='pending' && auth()->user()->isManager()?'Review request':'View request' }}</a></footer>
</article>
@empty
<x-empty-state title="No return requests" message="Requests from sales will appear here." />
@endforelse
</div>{{ $returns->links('components.pagination') }}
</div>
@endsection
