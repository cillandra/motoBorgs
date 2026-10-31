@extends('layouts.app')
@section('title','Sales history')
@section('content')
<div class="sales-history">
<header class="page-heading"><div><h1>Sales history</h1><p>{{ auth()->user()->isManager()?'Review all shop transactions and recorded refunds.':'Review your completed transactions and return requests.' }}</p></div><a class="button button-secondary" href="{{ route('returns.index') }}">Return requests</a></header>
@if($unavailable??false)
<section class="panel stack" role="alert"><h2>Data unavailable</h2><p>Sales could not be loaded. Your selected filters are preserved.</p><a class="button button-secondary" href="{{ route('sales.index',$filters) }}">Retry</a></section>
@else
@if(isset($context))<x-reporting-period :context="$context" mode="sales" :filters="$filters??[]" />@endif
<section class="sales-summary" aria-label="Filtered sale totals">
<x-metric-card :label="'Sales recorded · '.$summary['count'].' transactions'" :value="'₱'.\App\Support\Money::display($summary['recorded'])" icon="reports" tone="info" />
<x-metric-card label="Paid refunds linked to these sales" :value="'₱'.\App\Support\Money::display($summary['refunded'])" icon="receipt" tone="danger" />
<x-metric-card label="Net sales · selected sale records" :value="'₱'.\App\Support\Money::display($summary['net'])" icon="receipt" tone="success" />
</section>
@endif
<form class="sales-filters" method="get" action="{{ route('sales.index') }}">
<x-input id="q" label="Search sales" :value="$filters['q']??''" placeholder="Transaction, SKU, cashier or reference" />
<x-input id="start" type="date" label="From · Manila" :value="$filters['start']??''" />
<x-input id="end" type="date" label="Through · Manila" :value="$filters['end']??''" />
<div class="field"><label for="method">Payment</label><div class="field-perimeter"><select name="method" id="method"><option value="">All methods</option>@foreach(['cash'=>'Cash','gcash'=>'GCash','qrph'=>'QR Ph','debit'=>'Debit card','credit'=>'Credit card'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['method']??'')===$value)>{{ $label }}</option>@endforeach</select></div></div>
<div class="field"><label for="status">Status</label><div class="field-perimeter"><select name="status" id="status"><option value="">All statuses</option>@foreach(['completed'=>'Completed','partial'=>'Partially refunded','refunded'=>'Refunded'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['status']??'')===$value)>{{ $label }}</option>@endforeach</select></div></div>
@if(auth()->user()->isManager() && isset($cashiers))<div class="field"><label for="cashier_id">Cashier</label><div class="field-perimeter"><select name="cashier_id" id="cashier_id"><option value="">All cashiers</option>@foreach($cashiers as $cashier)<option value="{{ $cashier->cashier_id }}" @selected(($filters['cashier_id']??'')==$cashier->cashier_id)>{{ $cashier->cashier_name }}</option>@endforeach</select></div></div>@endif
<div class="action-row"><button class="button button-secondary">Apply</button><a class="button button-ghost" href="{{ route('sales.index') }}">Clear</a></div>
</form>
@if($errors->any())<div class="inline-alert error" role="alert">{{ $errors->first() }}</div>@endif
@if(isset($sales))
<section class="panel table-panel"><div class="table-scroll"><table><thead><tr><th>Transaction</th><th>Date and time · Manila</th>@if(auth()->user()->isManager())<th>Cashier</th>@endif<th>Payment</th><th class="numeric">Total paid</th><th>Status</th><th>Actions</th></tr></thead><tbody>
@forelse($sales as $sale)<tr><td><strong>MOTO-{{ str_pad($sale->id,8,'0',STR_PAD_LEFT) }}</strong><small>{{ $sale->lines->pluck('sku')->join(', ') }}</small></td><td>{{ $sale->created_at->timezone('Asia/Manila')->format('d M Y, H:i') }}</td>@if(auth()->user()->isManager())<td>{{ $sale->cashier_name }}</td>@endif<td>{{ ['cash'=>'Cash','gcash'=>'GCash','qrph'=>'QR Ph','debit'=>'Debit card','credit'=>'Credit card'][$sale->payment->method] }}</td><td class="numeric">₱{{ \App\Support\Money::display($sale->total_centavos) }}</td><td><x-status-badge :status="($sale->paid_refunds_centavos??0)>0?($sale->paid_refunds_centavos>=$sale->total_centavos?'Refunded':'Partially refunded'):'Completed'" /></td><td><div class="action-row"><a data-modal-link class="button button-ghost" href="{{ route('sales.show',$sale) }}">View</a><a data-modal-link class="button button-ghost" href="{{ route('sales.receipt',[$sale,'copy'=>1]) }}">Receipt</a></div></td></tr>@empty<tr><td colspan="7"><x-empty-state title="No matching sales" message="Try another search or clear your filters." /></td></tr>@endforelse
</tbody></table></div>{{ $sales->links('components.pagination') }}</section>
<p class="measure-note">This history summarizes sales by sale date, with all paid refunds linked to those records. Date-based reports account for refunds on their payout dates. Held and cancelled carts are excluded.</p>
@endif
</div>
@endsection
