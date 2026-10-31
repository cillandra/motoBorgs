@props(['context','mode'=>'sales','filters'=>[]])
@php
$latest=$context['latest_date'];
$latestUrl=$latest ? ($mode==='dashboard' ? route('dashboard',['date'=>$latest]) : route($mode==='reports'?'reports.index':'sales.index',array_merge($filters,['start'=>$latest,'end'=>$latest]))) : null;
$canJump=$latest && ($context['start']!==null || $context['end']!==null) && ($context['start']!==$latest || $context['end']!==$latest);
@endphp
<section class="reporting-context" aria-label="Reporting period">
<div><strong>{{ $context['label'] }}</strong><p>@if($latest)Latest recorded sale matching this view: {{ $latest }}.@else No recorded sales match this view.@endif @if($mode==='sales')Refund totals follow the selected sale records.@else Totals follow the selected dates; stock indicators show current stock.@endif</p></div>
@if($canJump)<a class="button button-secondary" href="{{ $latestUrl }}">View latest sales date</a>@endif
</section>