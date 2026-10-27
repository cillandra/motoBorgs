<?php

namespace App\Services\Reporting;

use App\Models\RefundRecord;
use App\Models\ReturnLine;
use App\Models\SaleLine;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReportQuery
{
    private function originalLines(Builder $query, ReportFilter $filter): Builder
    {
        return $query->when($filter->variantId, fn ($q) => $q->where('variant_id', $filter->variantId))
            ->when($filter->categoryId, fn ($q) => $q->where('category_id_snapshot', $filter->categoryId))
            ->when($filter->cashierId, fn ($q) => $q->whereHas('sale', fn ($s) => $s->where('cashier_id', $filter->cashierId)));
    }

    private function sales(ReportFilter $filter): Builder
    {
        return $this->originalLines(SaleLine::query(), $filter)->whereHas('sale', fn ($q) => $q->where('created_at', '>=', $filter->startUtc)->where('created_at', '<', $filter->endUtc));
    }

    private function accepted(ReportFilter $filter): Builder
    {
        return ReturnLine::whereHas('request', fn ($r) => $r->where('status', 'accepted')->where('accepted_at', '>=', $filter->startUtc)->where('accepted_at', '<', $filter->endUtc))
            ->whereHas('saleLine', fn ($line) => $this->originalLines($line, $filter));
    }

    /** Partial payouts allocate over remaining approved line values, in immutable paid_at/id order. */
    private function payouts(ReportFilter $filter): array
    {
        $records = RefundRecord::where('status', 'paid')->where('paid_at', '<', $filter->endUtc)
            ->whereHas('request', fn ($r) => $r->where('status', 'accepted')->whereHas('lines.saleLine', fn ($line) => $this->originalLines($line, $filter)))
            ->with(['request.lines.saleLine', 'request.sale.payment'])->orderBy('paid_at')->orderBy('id')->get();
        $remaining = [];
        $rows = [];
        foreach ($records as $record) {
            $request = $record->request;
            if (! isset($remaining[$request->id])) {
                $remaining[$request->id] = $request->lines->mapWithKeys(fn ($line) => [$line->id => $line->refundable_centavos])->all();
            }
            $allocations = Money::allocate($record->amount_centavos, $remaining[$request->id]);
            foreach ($request->lines as $line) {
                $amount = $allocations[$line->id];
                $remaining[$request->id][$line->id] -= $amount;
                $original = $line->saleLine;
                if ($record->paid_at->lt($filter->startUtc) || ($filter->variantId && $original->variant_id !== $filter->variantId) || ($filter->categoryId && (int) $original->category_id_snapshot !== $filter->categoryId) || ($filter->cashierId && $request->sale->cashier_id !== $filter->cashierId) || $amount === 0) {
                    continue;
                }
                $rows[] = ['record_id' => $record->id, 'return_id' => $request->id, 'sale_id' => $request->sale_id, 'line' => $original, 'amount' => $amount, 'paid_at' => $record->paid_at, 'method' => $request->sale->payment->method, 'reference' => $record->reference];
            }
        }

        return $rows;
    }

    public function summary(ReportFilter $filter): array
    {
        $sales = $this->sales($filter);
        $accepted = $this->accepted($filter);
        $gross = (int) (clone $sales)->sum(DB::raw('quantity * price_centavos'));
        $discount = (int) (clone $sales)->sum('discount_centavos');
        $refund = array_sum(array_column($this->payouts($filter), 'amount'));
        $sold = (int) (clone $sales)->sum('quantity');
        $returned = (int) (clone $accepted)->sum('quantity');

        return ['gross_sales' => $gross, 'discounts' => $discount, 'recorded_sales' => $gross - $discount, 'paid_refunds' => $refund, 'net_sales' => $gross - $discount - $refund,
            'completed_count' => (clone $sales)->distinct()->count('sale_id'), 'sold_units' => $sold, 'returned_units' => $returned, 'net_units' => $sold - $returned, 'return_count' => (clone $accepted)->distinct()->count('return_request_id')];
    }

    private function productKey(SaleLine $line): string
    {
        return hash('sha256', json_encode([$line->variant_id, $line->sku, $line->product_name, $line->variant_name, $line->category_id_snapshot, $line->category_name], JSON_THROW_ON_ERROR));
    }

    private function productRow(SaleLine $line): array
    {
        return ['variant_id' => $line->variant_id, 'sku' => $line->sku, 'product_name' => $line->product_name, 'variant_name' => $line->variant_name, 'category_name' => $line->category_name ?? 'Uncategorized',
            'gross_sales' => 0, 'discounts' => 0, 'paid_refunds' => 0, 'net_sales' => 0, 'sold_units' => 0, 'returned_units' => 0, 'net_units' => 0];
    }

    public function products(ReportFilter $filter): array
    {
        $rows = [];
        $group = ['variant_id', 'sku', 'product_name', 'variant_name', 'category_id_snapshot', 'category_name'];
        $sold = $this->sales($filter)->select($group)->selectRaw('SUM(quantity * price_centavos) as gross_amount, SUM(discount_centavos) as discount_amount, SUM(quantity) as sold_quantity')->groupBy($group)->get();
        foreach ($sold as $line) {
            $key = $this->productKey($line);
            $rows[$key] ??= $this->productRow($line);
            $rows[$key]['gross_sales'] += (int) $line->gross_amount;
            $rows[$key]['discounts'] += (int) $line->discount_amount;
            $rows[$key]['sold_units'] += (int) $line->sold_quantity;
        }
        foreach ($this->accepted($filter)->with('saleLine')->get() as $line) {
            $original = $line->saleLine;
            $key = $this->productKey($original);
            $rows[$key] ??= $this->productRow($original);
            $rows[$key]['returned_units'] += $line->quantity;
        }
        foreach ($this->payouts($filter) as $payout) {
            $key = $this->productKey($payout['line']);
            $rows[$key] ??= $this->productRow($payout['line']);
            $rows[$key]['paid_refunds'] += $payout['amount'];
        }
        foreach ($rows as &$row) {
            $row['net_sales'] = $row['gross_sales'] - $row['discounts'] - $row['paid_refunds'];
            $row['net_units'] = $row['sold_units'] - $row['returned_units'];
        } unset($row);
        usort($rows, fn ($a, $b) => ($b['net_sales'] <=> $a['net_sales']) ?: ($a['variant_id'] <=> $b['variant_id']));

        return array_values($rows);
    }

    public function payments(ReportFilter $filter): array
    {
        $rows = [];
        foreach (['cash' => 'Cash', 'gcash' => 'GCash', 'qrph' => 'QR Ph', 'debit' => 'Debit card', 'credit' => 'Credit card'] as $method => $label) {
            $rows[$method] = ['method' => $method, 'label' => $label, 'recorded_sales' => 0, 'paid_refunds' => 0, 'net_sales' => 0, 'completed_count' => 0];
        }
        $grouped = $this->sales($filter)->join('payment_records', 'payment_records.sale_id', '=', 'sale_lines.sale_id')->select('payment_records.method')
            ->selectRaw('SUM(sale_lines.total_centavos) as recorded_amount, COUNT(DISTINCT sale_lines.sale_id) as sale_count')->groupBy('payment_records.method')->get();
        foreach ($grouped as $group) {
            $rows[$group->method]['recorded_sales'] = (int) $group->recorded_amount;
            $rows[$group->method]['completed_count'] = (int) $group->sale_count;
        }
        foreach ($this->payouts($filter) as $payout) {
            $rows[$payout['method']]['paid_refunds'] += $payout['amount'];
        }
        foreach ($rows as $method => &$row) {
            $row['net_sales'] = $row['recorded_sales'] - $row['paid_refunds'];

        } unset($row);

        return array_values($rows);
    }

    public function returns(ReportFilter $filter): array
    {
        $rows = [];
        foreach ($this->accepted($filter)->with(['saleLine', 'request.reviewer'])->orderBy('id')->get() as $line) {
            $request = $line->request;
            $original = $line->saleLine;
            $rows[] = ['event' => 'Return accepted', 'return_id' => $request->id, 'sale_id' => $request->sale_id, 'date' => $request->accepted_at->timezone('Asia/Manila')->format('Y-m-d H:i'),
                'product_name' => $original->product_name, 'sku' => $original->sku, 'quantity' => $line->quantity, 'restock_quantity' => $line->restock_quantity, 'approved_value' => $line->refundable_centavos, 'paid_refunds' => 0, 'condition' => $line->condition, 'reviewer' => $request->reviewer?->name ?? 'Historical Manager', 'reference' => ''];
        }
        foreach ($this->payouts($filter) as $payout) {
            $rows[] = ['event' => 'Refund paid', 'return_id' => $payout['return_id'], 'sale_id' => $payout['sale_id'], 'date' => $payout['paid_at']->timezone('Asia/Manila')->format('Y-m-d H:i'),
                'product_name' => $payout['line']->product_name, 'sku' => $payout['line']->sku, 'quantity' => 0, 'restock_quantity' => 0, 'approved_value' => 0, 'paid_refunds' => $payout['amount'], 'condition' => 'Payout recorded', 'reviewer' => '', 'reference' => $payout['reference']];
        }
        usort($rows, fn ($a, $b) => strcmp($b['date'], $a['date']) ?: ($b['return_id'] <=> $a['return_id']));

        return $rows;
    }

    public function timeline(ReportFilter $filter, bool $hourly = false): array
    {
        $pattern = $hourly ? 'H:00' : 'Y-m-d';
        $sqlPattern = $hourly ? '%H:00' : '%Y-%m-%d';
        $rows = [];
        $cursor = $filter->startUtc->timezone('Asia/Manila');
        $end = $filter->endUtc->timezone('Asia/Manila');
        while ($cursor->lt($end)) {
            $rows[$cursor->format($pattern)] = ['period' => $cursor->format($pattern), 'gross_sales' => 0, 'discounts' => 0, 'paid_refunds' => 0, 'net_sales' => 0, 'completed_count' => 0, 'sold_units' => 0, 'returned_units' => 0, 'net_units' => 0];
            $cursor = $hourly ? $cursor->addHour() : $cursor->addDay();
        }
        $grouped = $this->sales($filter)->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->selectRaw('DATE_FORMAT(DATE_ADD(sales.created_at, INTERVAL 8 HOUR), ?) as period, SUM(sale_lines.quantity * sale_lines.price_centavos) as gross_amount, SUM(sale_lines.discount_centavos) as discount_amount, SUM(sale_lines.quantity) as sold_quantity, COUNT(DISTINCT sale_lines.sale_id) as sale_count', [$sqlPattern])->groupBy('period')->get();
        foreach ($grouped as $group) {
            $period = $group->period;
            $rows[$period]['gross_sales'] = (int) $group->gross_amount;
            $rows[$period]['discounts'] = (int) $group->discount_amount;
            $rows[$period]['sold_units'] = (int) $group->sold_quantity;
            $rows[$period]['completed_count'] = (int) $group->sale_count;
        }
        foreach ($this->accepted($filter)->with('request')->get() as $line) {
            $period = $line->request->accepted_at->timezone('Asia/Manila')->format($pattern);
            $rows[$period]['returned_units'] += $line->quantity;
        }
        foreach ($this->payouts($filter) as $payout) {
            $period = $payout['paid_at']->timezone('Asia/Manila')->format($pattern);
            $rows[$period]['paid_refunds'] += $payout['amount'];
        }
        foreach ($rows as &$row) {
            $row['net_sales'] = $row['gross_sales'] - $row['discounts'] - $row['paid_refunds'];
            $row['net_units'] = $row['sold_units'] - $row['returned_units'];
        } unset($row);

        return array_values($rows);
    }
}
