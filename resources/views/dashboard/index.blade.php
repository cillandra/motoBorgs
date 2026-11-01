@extends('layouts.app')
@section('title','Dashboard')
@section('content')
@php
    $selectedToday=$context['is_today']??($date===now('Asia/Manila')->toDateString());
@endphp
<header class="page-heading dashboard-heading">
    <div><h1>{{ auth()->user()->isManager()?'Shop overview':($selectedToday?'Your work today':'Your work on selected date') }}</h1><p>{{ auth()->user()->isManager()?'All shop sales and stock':'Your sales and transactions' }} · {{ \Carbon\CarbonImmutable::parse($date,'Asia/Manila')->format('l, j F Y') }}</p></div>
    <div class="action-row"><form method="get"><label class="sr-only" for="dashboard-date">Sales date</label><input class="form-input" type="date" name="date" id="dashboard-date" value="{{ $date }}" data-dashboard-date><noscript><button class="button button-secondary">Apply</button></noscript></form><a class="button button-primary" href="{{ route('pos.index') }}">New sale</a></div>
</header>
@if($unavailable??false)
<section class="panel stack" role="alert"><h2>Data unavailable</h2><p>The dashboard could not load for {{ $date }}. Totals are unavailable.</p><a class="button button-secondary" href="{{ route('dashboard',['date'=>$date]) }}">Retry</a></section>
@else
@php
    $prefix=auth()->user()->isManager()?'':'My ';
    $metrics=[
        [$prefix.'net sales','₱'.\App\Support\Money::display($data['net_sales']),'Completed sales less paid refunds','receipt','sales'],
        [$prefix.'completed sales',$data['completed_count'],'Selected business date','reports','completed'],
        [$prefix.'units sold',$data['net_units'],'Less units accepted as returns','inventory','units'],
        [auth()->user()->isManager()?'Stock alerts':'My saved orders',auth()->user()->isManager()?$data['stock_alerts']['count']:$data['held_count'],auth()->user()->isManager()?($data['stock_alerts']['count']-$data['stock_alerts']['out']).' low · '.$data['stock_alerts']['out'].' out of stock':'Unpaid · stock not reserved',auth()->user()->isManager()?'activity':'cart','attention'],
    ];
@endphp
@if(isset($context))<x-reporting-period :context="$context" mode="dashboard" :filters="$filters??[]" />@endif
<section class="dashboard-kpis" aria-label="Business date totals">
    @foreach($metrics as [$label,$value,$metricHelp,$icon,$tone])
    <x-metric-card class="dashboard-kpi" data-metric="{{ $tone }}" :label="ucfirst($label)" :value="$value" :help="$metricHelp" :icon="$icon" :tone="['sales'=>'success','completed'=>'info','units'=>'violet','attention'=>'warning'][$tone]" />
    @endforeach
</section>
<div class="dashboard-columns">
    <section class="panel dashboard-panel">
        <header><h2>{{ ucfirst($prefix.'sales').($selectedToday?' today':' on selected date') }}</h2></header><p>Net sales by sale / paid-refund hour · {{ $date }}</p>
        @php
            $activeHours=collect($data['hourly_sales'])->filter(fn($hour)=>$hour['sales_centavos']!==0)->pluck('hour');
            $firstHour=max(0,($activeHours->min()??11)-2); $lastHour=min(23,max($firstHour+5,$activeHours->max()??14));
            $hours=collect($data['hourly_sales'])->filter(fn($hour)=>$hour['hour']>=$firstHour && $hour['hour']<=$lastHour);
            $maximum=max(1,$hours->max(fn($hour)=>abs($hour['sales_centavos'])));
        @endphp
        <div class="hourly-chart" role="img" aria-label="Hourly net sales. Exact amounts are shown for each hour.">
            @foreach($hours as $hour)<div class="hour-column"><div class="hour-plot"><span class="hour-value">₱{{ \App\Support\Money::display($hour['sales_centavos']) }}</span><span @class(['hour-bar','negative'=>$hour['sales_centavos']<0,'latest'=>$loop->last]) style="height:{{ round(abs($hour['sales_centavos'])/$maximum*112,2) }}px"></span></div><span>{{ str_pad($hour['hour'],2,'0',STR_PAD_LEFT) }}:00</span></div>@endforeach
        </div><p class="measure-note">Paid refunds may create negative hours. Returns affect units on their acceptance date.</p>
    </section>
    <section class="panel dashboard-panel dashboard-panel--attention" aria-labelledby="stock-attention-title">
        <header class="dashboard-section-header">
            <div class="dashboard-section-title"><span class="dashboard-heading-icon" aria-hidden="true"><span class="dashboard-icon" style="--dashboard-icon:url('{{ asset('assets/icons/inventory.svg') }}')"></span></span><h2 id="stock-attention-title">Stock attention</h2></div>
            <a class="button button-ghost" href="{{ route('inventory.index',['availability'=>'low']) }}">View stock</a>
        </header>
        <p>{{ $data['stock_alerts']['count'] }} variants need attention · current stock</p>
        <div class="stock-alert-summary" aria-label="Current stock alert breakdown">
            <a class="stock-summary-out" href="{{ route('inventory.index',['availability'=>'out']) }}"><span>Out of stock</span><strong>{{ $data['stock_alerts']['out'] }}</strong></a>
            <a class="stock-summary-low" href="{{ route('inventory.index',['availability'=>'low']) }}"><span>Low stock</span><strong>{{ $data['stock_alerts']['count']-$data['stock_alerts']['out'] }}</strong></a>
        </div>
        <ul class="stock-attention-list">
        @forelse($data['stock_alerts']['items'] as $variant)
            <li>
                <a data-modal-link class="stock-attention-row {{ $variant->stock===0?'stock-attention-row--out':'stock-attention-row--low' }}" href="{{ route('inventory.show',$variant) }}">
                    <span class="stock-attention-copy"><strong>{{ $variant->product->name }}</strong><small>{{ $variant->sku }}</small><x-stock-badge :variant="$variant" /></span>
                    <span class="stock-attention-balance"><strong>{{ $variant->stock }}</strong><small>{{ $variant->unit->code }} left</small></span>
                </a>
            </li>
        @empty
            <li class="dashboard-quiet-state"><span class="dashboard-icon" aria-hidden="true" style="--dashboard-icon:url('{{ asset('assets/icons/inventory.svg') }}')"></span><p>No low or out-of-stock variants.</p></li>
        @endforelse
        </ul>
        <p class="measure-note">{{ auth()->user()->isManager()?'Stock changes are recorded in the activity log.':'Contact your Manager for stock changes.' }}</p>
    </section>
    <section class="panel dashboard-panel">
        <header><h2>{{ auth()->user()->isManager()?'Recent transactions':'My recent transactions' }}</h2><a class="button button-ghost" href="{{ route('sales.index',['start'=>$date,'end'=>$date]) }}">View history</a></header>
        <div class="table-scroll"><table><thead><tr><th>Transaction</th><th>Time</th>@if(auth()->user()->isManager())<th>Cashier</th>@endif<th>Payment</th><th class="numeric">Total</th><th>Status</th></tr></thead><tbody>@forelse($data['recent_sales'] as $sale)<tr><td><a data-modal-link href="{{ route('sales.show',$sale) }}">MOTO-{{ str_pad($sale->id,8,'0',STR_PAD_LEFT) }}</a></td><td>{{ $sale->created_at->timezone('Asia/Manila')->format('H:i') }}</td>@if(auth()->user()->isManager())<td>{{ $sale->cashier_name }}</td>@endif<td>{{ ['cash'=>'Cash','gcash'=>'GCash','qrph'=>'QR Ph','debit'=>'Debit card','credit'=>'Credit card'][$sale->payment->method] }}</td><td class="numeric">₱{{ \App\Support\Money::display($sale->total_centavos) }}</td><td><x-status-badge :status="($sale->paid_refunds_centavos??0)>0?($sale->paid_refunds_centavos>=$sale->total_centavos?'Refunded':'Partially refunded'):'Completed'" /></td></tr>@empty<tr><td colspan="6">No completed sales on this date.</td></tr>@endforelse</tbody></table></div>
    </section>
    <section class="panel dashboard-panel dashboard-panel--activity" aria-labelledby="dashboard-activity-title">
        <header class="dashboard-section-header">
            <div class="dashboard-section-title"><span class="dashboard-heading-icon" aria-hidden="true"><span class="dashboard-icon" style="--dashboard-icon:url('{{ asset('assets/icons/'.(auth()->user()->isManager()?'users':'cart').'.svg') }}')"></span></span><h2 id="dashboard-activity-title">{{ auth()->user()->isManager()?'Employee activity':'My saved orders' }}</h2></div>
            @if(auth()->user()->isManager() && Route::has('activity.index'))<a class="button button-ghost" href="{{ route('activity.index',['start'=>$date,'end'=>$date]) }}">View log</a>@elseif(!auth()->user()->isManager())<a class="button button-ghost" href="{{ route('pos.index',['held'=>1]) }}">View saved orders</a>@endif
        </header>
        @if(auth()->user()->isManager())
            <p>Recent recorded activity · selected date</p>
            <ol class="activity-timeline">
            @forelse($data['activity'] as $event)
                @php
                    $actorName=$event->actor_name??'System';
                    $initials=collect(explode(' ',$actorName))->filter()->take(2)->map(fn($part)=>mb_substr($part,0,1))->join('');
                    $outcomeTone=in_array($event->outcome,['success','denied','failed'],true)?$event->outcome:'neutral';
                @endphp
                <li>
                    <time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Asia/Manila')->format('H:i') }}</time>
                    <a data-modal-link class="activity-entry" href="{{ route('activity.show',$event) }}">
                        <span class="activity-avatar" aria-hidden="true">{{ $initials }}</span>
                        <span class="activity-entry-copy"><strong>{{ $actorName }}</strong><span class="activity-event">{{ str_replace(['.','_'],' ',$event->type) }}</span><span class="activity-entry-meta"><span class="activity-module">{{ ucfirst($event->module) }}</span><span class="activity-outcome" data-outcome="{{ $outcomeTone }}">{{ ucfirst($event->outcome) }}</span></span></span>
                    </a>
                </li>
            @empty
                <li class="dashboard-quiet-state"><span class="dashboard-icon" aria-hidden="true" style="--dashboard-icon:url('{{ asset('assets/icons/activity.svg') }}')"></span><p>No recorded activity on this date.</p></li>
            @endforelse
            </ol>
        @else
            <p>{{ $data['held_count'] }} unpaid carts · your account only</p>
            @forelse($data['held_carts'] as $cart)<a class="dashboard-activity" href="{{ route('pos.index',['held'=>1]) }}"><strong>{{ $cart->label??'Held cart #'.$cart->id }}</strong><small>{{ $cart->lines_count }} {{ $cart->lines_count===1?'product option':'product options' }} · unpaid</small></a>@empty<div class="dashboard-quiet-state"><p>No saved orders.</p></div>@endforelse
            <p class="measure-note">Saved orders do not reserve stock.</p>
        @endif
    </section>
</div>
<footer class="dashboard-footer"><p>Saved unpaid orders and cancelled orders are excluded. Refunds use the date they were paid; returned units use the date the return was approved. Net sales is revenue, not profit.</p><a class="button button-ghost" href="{{ route('dashboard',['date'=>$date]) }}">Refresh</a></footer>
@endif
@endsection
