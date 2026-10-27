<?php

namespace App\Services\Reporting;

use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\Sale;
use App\Models\User;
use App\Models\Variant;

class DashboardQuery
{
    public function for(User $actor, string $businessDate): array
    {
        abort_unless($actor->active, 403);
        [$start, $end] = SalesQuery::bounds(['start' => $businessDate, 'end' => $businessDate]);
        $filter = ReportFilter::from(['start' => $businessDate, 'end' => $businessDate]);
        if (! $actor->isManager()) {
            $filter = $filter->forCashier($actor->id);
        }
        $report = app(ReportQuery::class);
        $summary = $report->summary($filter);
        $hourly = array_map(fn ($row) => ['hour' => (int) substr($row['period'], 0, 2), 'sales_centavos' => $row['net_sales']], $report->timeline($filter, true));
        $sales = Sale::when(! $actor->isManager(), fn ($q) => $q->where('cashier_id', $actor->id))->where('created_at', '>=', $start)->where('created_at', '<', $end);
        $alerts = Variant::whereNull('archived_at')->whereHas('product', fn ($p) => $p->whereNull('archived_at'))->whereColumn('stock', '<=', 'threshold');

        return ['net_sales' => $summary['net_sales'],
            'completed_count' => $summary['completed_count'],
            'net_units' => $summary['net_units'],
            'stock_alerts' => ['count' => (clone $alerts)->count(), 'out' => (clone $alerts)->where('stock', 0)->count(), 'items' => (clone $alerts)->with(['product', 'unit'])->orderBy('stock')->orderBy('id')->limit(2)->get()],
            'recent_sales' => (clone $sales)->with('payment')->withSum(['refunds as paid_refunds_centavos' => fn ($r) => $r->where('refund_records.status', 'paid')], 'amount_centavos')->latest('created_at')->latest('id')->limit(3)->get(),
            'hourly_sales' => $hourly,
            'held_count' => Cart::where('cashier_id', $actor->id)->where('status', 'held')->count(),
            'held_carts' => Cart::where('cashier_id', $actor->id)->where('status', 'held')->withCount('lines')->latest()->limit(2)->get(),
            'activity' => $actor->isManager() ? AuditEvent::where('created_at', '>=', $start)->where('created_at', '<', $end)->latest('id')->limit(3)->get() : collect(),
        ];
    }
}
