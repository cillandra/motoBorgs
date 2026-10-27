<?php

namespace App\Services\Reporting;

use App\Models\RefundRecord;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SalesQuery
{
    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    public static function bounds(array $filters): array
    {
        return [empty($filters['start']) ? null : CarbonImmutable::parse($filters['start'], 'Asia/Manila')->startOfDay()->utc(),
            empty($filters['end']) ? null : CarbonImmutable::parse($filters['end'], 'Asia/Manila')->startOfDay()->addDay()->utc()];
    }

    public function base(User $actor, array $filters): Builder
    {
        abort_unless($actor->active, 403);
        [$start, $end] = self::bounds($filters);
        $query = Sale::query()->when(! $actor->isManager(), fn ($q) => $q->where('cashier_id', $actor->id))
            ->when($actor->isManager() && ! empty($filters['cashier_id']), fn ($q) => $q->where('cashier_id', $filters['cashier_id']))
            ->when($start, fn ($q) => $q->where('sales.created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('sales.created_at', '<', $end))
            ->when(! empty($filters['method']), fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('method', $filters['method'])));
        if (! empty($filters['q'])) {
            $term = $filters['q'];
            $id = preg_match('/^(?:MOTO-|SALE-)?0*([1-9][0-9]*)$/i', $term, $match) ? $match[1] : null;
            $query->where(function ($q) use ($term, $id): void {
                $q->where('cashier_name', 'like', '%'.$term.'%')
                    ->orWhereHas('lines', fn ($line) => $line->where('sku', 'like', '%'.$term.'%')->orWhere('product_name', 'like', '%'.$term.'%'))
                    ->orWhereHas('payment', fn ($p) => $p->where('reference', 'like', '%'.$term.'%'));
                if ($id) {
                    $q->orWhere('sales.id', $id);
                }
            });
        }
        $paid = '(SELECT COALESCE(SUM(refund_records.amount_centavos),0) FROM refund_records INNER JOIN return_requests ON return_requests.id=refund_records.return_request_id WHERE return_requests.sale_id=sales.id AND refund_records.status=?)';

        return match ($filters['status'] ?? '') {
            'completed' => $query->whereRaw($paid.' = 0', ['paid']),
            'partial' => $query->whereRaw($paid.' > 0 AND '.$paid.' < sales.total_centavos', ['paid', 'paid']),
            'refunded' => $query->whereRaw($paid.' >= sales.total_centavos AND '.$paid.' > 0', ['paid', 'paid']),
            default => $query,
        };
    }

    public function search(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->base($actor, $filters)->with(['payment', 'lines'])->withSum(['refunds as paid_refunds_centavos' => fn ($r) => $r->where('refund_records.status', 'paid')], 'amount_centavos')
            ->orderByDesc('sales.created_at')->orderByDesc('sales.id')->paginate(max(1, min(50, $perPage)))->withQueryString();
    }

    /** Historical sale cohort: refunds linked to the selected sales, regardless of payout date. */
    public function summary(User $actor, array $filters): array
    {
        $sales = $this->base($actor, $filters);
        $recorded = (int) (clone $sales)->sum('total_centavos');
        $paid = (int) RefundRecord::where('status', 'paid')->whereHas('request', fn ($r) => $r->whereIn('sale_id', (clone $sales)->select('sales.id')))->sum('amount_centavos');

        return ['recorded' => $recorded, 'refunded' => $paid, 'net' => $recorded - $paid, 'count' => (clone $sales)->count()];
    }
}
