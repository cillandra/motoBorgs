<?php

namespace App\Services\Reporting;

use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\RefundRecord;
use App\Models\ReturnLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Models\Variant;

class DashboardQuery
{
    public function for(User $actor, string $businessDate): array
    {
        abort_unless($actor->active, 403);
        [$start, $end] = SalesQuery::bounds(['start' => $businessDate, 'end' => $businessDate]);
        $sales = Sale::when(! $actor->isManager(), fn ($q) => $q->where('cashier_id', $actor->id))->where('created_at', '>=', $start)->where('created_at', '<', $end);
        $refunds = RefundRecord::where('status', 'paid')->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->when(! $actor->isManager(), fn ($q) => $q->whereHas('request.sale', fn ($sale) => $sale->where('cashier_id', $actor->id)));
        $returned = ReturnLine::whereHas('request', fn ($q) => $q->where('status', 'accepted')->where('accepted_at', '>=', $start)->where('accepted_at', '<', $end)
            ->when(! $actor->isManager(), fn ($q) => $q->whereHas('sale', fn ($s) => $s->where('cashier_id', $actor->id))));
        $hourly = array_fill(0, 24, 0);
        foreach ((clone $sales)->selectRaw('HOUR(DATE_ADD(created_at, INTERVAL 8 HOUR)) as hour, SUM(total_centavos) as amount')->groupBy('hour')->get() as $row) {
            $hourly[(int) $row->hour] += (int) $row->amount;
        }
        foreach ((clone $refunds)->selectRaw('HOUR(DATE_ADD(paid_at, INTERVAL 8 HOUR)) as hour, SUM(amount_centavos) as amount')->groupBy('hour')->get() as $row) {
            $hourly[(int) $row->hour] -= (int) $row->amount;
        }
        $alerts = Variant::whereNull('archived_at')->whereHas('product', fn ($p) => $p->whereNull('archived_at'))->whereColumn('stock', '<=', 'threshold');

        return ['net_sales' => (int) (clone $sales)->sum('total_centavos') - (int) (clone $refunds)->sum('amount_centavos'),
            'completed_count' => (clone $sales)->count(),
            'net_units' => (int) SaleLine::whereIn('sale_id', (clone $sales)->select('id'))->sum('quantity') - (int) $returned->sum('quantity'),
            'stock_alerts' => ['count' => (clone $alerts)->count(), 'out' => (clone $alerts)->where('stock', 0)->count(), 'items' => (clone $alerts)->with(['product', 'unit'])->orderBy('stock')->orderBy('id')->limit(2)->get()],
            'recent_sales' => (clone $sales)->with('payment')->withSum(['refunds as paid_refunds_centavos' => fn ($r) => $r->where('refund_records.status', 'paid')], 'amount_centavos')->latest('created_at')->latest('id')->limit(3)->get(),
            'hourly_sales' => array_map(fn ($hour, $amount) => ['hour' => $hour, 'sales_centavos' => $amount], array_keys($hourly), $hourly),
            'held_count' => Cart::where('cashier_id', $actor->id)->where('status', 'held')->count(),
            'held_carts' => Cart::where('cashier_id', $actor->id)->where('status', 'held')->withCount('lines')->latest()->limit(2)->get(),
            'activity' => $actor->isManager() ? AuditEvent::where('created_at', '>=', $start)->where('created_at', '<', $end)->latest('id')->limit(3)->get() : collect(),
        ];
    }
}
