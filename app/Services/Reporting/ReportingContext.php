<?php

namespace App\Services\Reporting;

use App\Models\User;
use Carbon\CarbonImmutable;

class ReportingContext
{
    public function __construct(private SalesQuery $sales) {}

    /** @return array{start:?string,end:?string,label:string,is_today:bool,latest_date:?string,has_sales:bool} */
    public function for(User $actor, array $filters): array
    {
        $scope = $filters;
        unset($scope['start'], $scope['end']);
        $query = $this->sales->base($actor, $scope);
        if (! empty($scope['variant_id']) || ! empty($scope['category_id'])) {
            $query->whereHas('lines', fn ($lines) => $lines->when(! empty($scope['variant_id']), fn ($q) => $q->where('variant_id', $scope['variant_id']))->when(! empty($scope['category_id']), fn ($q) => $q->where('category_id_snapshot', $scope['category_id'])));
        }
        $latest = $query->orderByDesc('sales.created_at')->value('sales.created_at');
        $start = $filters['start'] ?? null;
        $end = $filters['end'] ?? null;
        $today = now('Asia/Manila')->toDateString();
        $isToday = $start === $today && $end === $today;
        $label = match (true) {
            $isToday => 'Showing today · '.$today,
            $start !== null && $start === $end => 'Showing '.$start,
            $start !== null && $end !== null => 'Showing '.$start.' to '.$end,
            $start !== null => 'Showing on or after '.$start,
            $end !== null => 'Showing on or before '.$end,
            default => 'Showing all dates',
        };

        return ['start' => $start, 'end' => $end, 'label' => $label, 'is_today' => $isToday, 'latest_date' => $latest ? CarbonImmutable::parse($latest, 'UTC')->timezone('Asia/Manila')->toDateString() : null, 'has_sales' => $latest !== null];
    }
}
