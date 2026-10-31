@extends('layouts.app')
@section('title','Reports & analytics')
@section('content')
<div class="reports-page">
<header class="page-heading"><div><h1>Reports & analytics</h1><p>Reconcile recorded sales, returned units and paid refunds.</p></div><div class="action-row"><a class="button button-secondary" href="{{ route('sales.index') }}">Sales history</a><x-button variant="secondary" data-dialog-open="export-report">Export report</x-button></div></header>
@if($errors->any())<div class="inline-alert error" role="alert">{{ $errors->first() }}</div>@endif
@if(isset($context))<x-reporting-period :context="$context" mode="reports" :filters="$filters??[]" />@endif
<form class="report-filters" method="get" action="{{ route('reports.index') }}">
<input type="hidden" name="tab" value="{{ $tab }}"><x-input id="start" type="date" label="From · Manila" :value="old('start',$filter->start)" required /><x-input id="end" type="date" label="Through · Manila" :value="old('end',$filter->end)" required />
@if(isset($variants))<div class="field"><label for="variant_id">Product / variant</label><div class="field-perimeter"><select name="variant_id" id="variant_id"><option value="">All variants</option>@foreach($variants as $variant)<option value="{{ $variant->variant_id }}" @selected($filter->variantId===$variant->variant_id)>{{ $variant->product_name }} · {{ $variant->sku }}</option>@endforeach</select></div></div>
<div class="field"><label for="category_id">Original category</label><div class="field-perimeter"><select name="category_id" id="category_id"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->category_id_snapshot }}" @selected($filter->categoryId===$category->category_id_snapshot)>{{ $category->category_name }}</option>@endforeach</select></div></div>@endif
<div class="action-row"><button class="button button-secondary">Apply</button><a class="button button-ghost" href="{{ route('reports.index',['tab'=>$tab]) }}">Reset</a></div></form>
<div class="report-period-shortcuts"><a href="{{ route('reports.index',array_merge($filters,['start'=>now()->timezone('Asia/Manila')->format('Y-m-d'),'end'=>now()->timezone('Asia/Manila')->format('Y-m-d')])) }}">Today</a><a href="{{ route('reports.index',array_merge($filters,['start'=>now()->timezone('Asia/Manila')->subDays(6)->format('Y-m-d'),'end'=>now()->timezone('Asia/Manila')->format('Y-m-d')])) }}">Last 7 days</a><a href="{{ route('reports.index',array_merge($filters,['start'=>now()->timezone('Asia/Manila')->startOfMonth()->format('Y-m-d'),'end'=>now()->timezone('Asia/Manila')->format('Y-m-d')])) }}">This month</a><span>{{ $filter->start }} – {{ $filter->end }} · Asia/Manila</span></div>
<nav class="tabs" aria-label="Report type">@foreach(['overview'=>'Overview','summary'=>'Sales summary','payments'=>'Payments','products'=>'Products','returns'=>'Returns'] as $value=>$label)<a @class(['tab','active'=>$tab===$value]) href="{{ route('reports.index',array_merge($filters,['tab'=>$value])) }}">{{ $label }}</a>@endforeach</nav>
@if($unavailable??false)<section class="panel stack" role="alert"><x-section-heading tag="h2" icon="info" tone="warning">Data unavailable</x-section-heading><p>The report could not be loaded. Your filters are preserved.</p><a class="button button-secondary" href="{{ route('reports.index',$filters) }}">Retry report</a></section>
@else
@php
    $summary=$data['summary'];
@endphp
<section class="report-kpis" aria-label="Report totals">
@foreach([
    ['Net sales','₱'.\App\Support\Money::display($summary['net_sales']),'After discounts and paid refunds','receipt','success'],
    ['Sales recorded',$summary['completed_count'],'Includes subsequently refunded sales','reports','info'],
    ['Net units sold',$summary['net_units'],'Sold less accepted return units','inventory','violet'],
    ['Paid refunds','₱'.\App\Support\Money::display($summary['paid_refunds']),'Recorded paid during this period','receipt','danger']
] as [$label,$value,$note,$icon,$tone])
<x-metric-card :label="$label" :value="$value" :help="$note" :icon="$icon" :tone="$tone" />
@endforeach
</section>
@if($summary['completed_count']===0 && $summary['return_count']===0 && $summary['paid_refunds']===0)<div class="information-notice"><img src="{{ asset('assets/icons/info.svg') }}" alt="" width="20" height="20"><div><strong>No activity in this period</strong><p>There are no completed sales, accepted returns or paid refunds matching these filters.</p></div></div>@endif
@if(in_array($tab,['overview','summary']))
<div class="report-charts"><section class="panel report-panel"><x-section-heading tag="h2" icon="reports" tone="info">Net sales by {{ $filter->start===$filter->end?'hour':'day' }}</x-section-heading><p>Original sales less discounts and paid refunds · Manila</p>
@php
    $timeline=collect($data['timeline']);
    if($filter->start===$filter->end){$active=$timeline->filter(fn($row)=>$row['gross_sales']!==0||$row['paid_refunds']!==0)->keys();$first=max(0,($active->min()??11)-2);$last=min(23,max($first+5,$active->max()??14));$timeline=$timeline->slice($first,$last-$first+1);}
    $maximum=max(1,$timeline->max(fn($row)=>abs($row['net_sales'])));
@endphp
<div class="hourly-chart report-chart" aria-label="Net sales by period">@foreach($timeline as $period)<div class="hour-column"><div class="hour-plot"><span class="hour-value">₱{{ \App\Support\Money::display($period['net_sales']) }}</span><span @class(['hour-bar','negative'=>$period['net_sales']<0,'latest'=>$loop->last]) style="height:{{ round(abs($period['net_sales'])/$maximum*150,2) }}px"></span></div><span>{{ $period['period'] }}</span></div>@endforeach</div><p class="measure-note">Net sales = gross sales − discounts − paid refunds. This measures revenue, not profit.</p></section>
<section class="panel report-panel"><x-section-heading tag="h2" icon="reports" tone="info">Payment methods</x-section-heading><p>Net revenue attributed to the original sale’s payment method.</p>@foreach($data['payments'] as $payment)<div class="payment-share" data-tone="{{ ['cash'=>'success','gcash'=>'info','qrph'=>'violet','debit'=>'warning','credit'=>'danger'][$payment['method']] }}"><div><strong>{{ $payment['label'] }}</strong><span>₱{{ \App\Support\Money::display($payment['net_sales']) }}</span><small>{{ $summary['net_sales']>0 && $payment['net_sales']>=0?number_format($payment['net_sales']/$summary['net_sales']*100,1).'%':'—' }}</small></div><div class="payment-share-track"><span style="width:{{ $summary['net_sales']>0?max(0,min(100,$payment['net_sales']/$summary['net_sales']*100)):0 }}%"></span></div></div>@endforeach<p class="measure-note">Shares are omitted when a period or method has negative net revenue.</p></section></div>
@endif
@if($tab==='overview' || $tab==='products')
<section class="panel report-panel"><header><x-section-heading tag="h2" icon="reports" tone="info">{{ $tab==='overview'?'Top products by net sales':'Product performance' }}</x-section-heading>@if($tab==='overview')<a class="button button-secondary" href="{{ route('reports.index',array_merge($filters,['tab'=>'products'])) }}">View all</a>@else<form method="get">@foreach($filters as $key=>$value)@if($key!=='sort')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach<label for="report-sort" class="sr-only">Rank products</label><select id="report-sort" name="sort" data-report-sort>@foreach(['net_desc'=>'Net sales: high to low','net_asc'=>'Net sales: low to high','units_desc'=>'Net units: high to low'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['sort']??'net_desc')===$value)>{{ $label }}</option>@endforeach</select><noscript><button class="button button-secondary">Sort</button></noscript></form>@endif</header>
@include('reports.products-table',['products'=>$tab==='overview'?array_slice($data['products'],0,3):$data['products']])
</section>
@endif
@if($tab==='summary')<section class="panel report-panel"><x-section-heading tag="h2" icon="reports" tone="info">Daily sales summary</x-section-heading>@include('reports.daily-table')</section>@endif
@if($tab==='payments')<section class="panel report-panel"><x-section-heading tag="h2" icon="reports" tone="info">Payment reconciliation</x-section-heading>@include('reports.payments-table')</section>@endif
@if($tab==='returns')<section class="panel report-panel"><header><x-section-heading tag="h2" icon="reports" tone="info">Accepted returns and paid payouts</x-section-heading><a class="button button-secondary" href="{{ route('returns.index') }}">Open return requests</a></header><p>Accepted item value and paid money are separate events. Pending or failed payouts do not reduce revenue.</p>@include('reports.returns-table')</section>@endif
<section class="panel report-panel reconciliation"><x-section-heading tag="h2" icon="reports" tone="info">Revenue reconciliation</x-section-heading><div>@foreach(['gross_sales'=>'Gross sales','discounts'=>'Discounts','paid_refunds'=>'Paid refunds','net_sales'=>'Net sales'] as $key=>$label)<div data-tone="{{ ['gross_sales'=>'info','discounts'=>'warning','paid_refunds'=>'danger','net_sales'=>'success'][$key] }}"><span>{{ $label }}</span><strong>₱{{ \App\Support\Money::display($summary[$key]) }}</strong></div>@endforeach</div><p class="measure-note">Sale money uses sale date; refunds use payout date; returned units use acceptance date. Product and category filters use the original sale snapshots. Partial payouts are distributed across the remaining approved item values, in payout order, with exact centavo allocation.</p></section>
@endif
<x-dialog id="export-report" title="Export report" description="Export the current dates and product/category filters."><form method="get" action="{{ route('reports.export') }}" class="stack">@foreach($filter->values() as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach<div class="field"><label for="export-type">CSV dataset</label><div class="field-perimeter"><select id="export-type" name="type">@foreach(['summary'=>'Revenue summary','products'=>'Products','payments'=>'Payments','returns'=>'Returns and payouts'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div></div><p>Amounts in CSV are integer centavos. Spreadsheet formula prefixes are escaped.</p><div class="action-row"><button class="button button-primary">Download CSV</button><a data-modal-link class="button button-secondary" href="{{ route('reports.print',$filter->values()) }}">Print / save PDF preview</a></div></form></x-dialog>
</div>
@endsection
