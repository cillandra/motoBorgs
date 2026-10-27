<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Reporting\SalesQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ActivityQuery
{
    public function base(User $manager, array $filters): Builder
    {
        abort_unless($manager->fresh()?->isManager(), 403, 'Manager access is required.');
        [$start,$end] = SalesQuery::bounds($filters);

        return AuditEvent::query()->when($start, fn ($q) => $q->where('created_at', '>=', $start))->when($end, fn ($q) => $q->where('created_at', '<', $end))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('actor_id', $filters['employee_id']))
            ->when(! empty($filters['type']), fn ($q) => $q->where('type', $filters['type']))->when(! empty($filters['module']), fn ($q) => $q->where('module', $filters['module']))->when(! empty($filters['outcome']), fn ($q) => $q->where('outcome', $filters['outcome']))
            ->when(! empty($filters['q']), fn ($q) => $q->where(fn ($search) => $search->where('type', 'like', '%'.$filters['q'].'%')->orWhere('actor_name', 'like', '%'.$filters['q'].'%')->orWhere('subject_id', 'like', '%'.$filters['q'].'%')->orWhere('context->reason', 'like', '%'.$filters['q'].'%')));
    }

    public function search(User $manager, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->base($manager, $filters)->latest('id')->paginate(max(1, min(50, $perPage)))->withQueryString();
    }

    public function detail(AuditEvent $event): array
    {
        return ['id' => $event->id, 'when' => $event->created_at->timezone('Asia/Manila')->format('Y-m-d H:i:s'), 'employee' => $event->actor_name, 'action' => $event->type, 'record' => $event->subject_type.' '.$event->subject_id, 'module' => $event->module, 'outcome' => $event->outcome, 'context' => app(AuditRecorder::class)->safeContext($event->context ?? [])];
    }
}
