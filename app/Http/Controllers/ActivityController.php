<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\ActivityQuery;
use App\Services\Reporting\CsvExporter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate(['q' => ['nullable', 'string', 'max:120'], 'employee_id' => ['nullable', 'integer', 'min:1'], 'start' => ['nullable', 'date_format:Y-m-d'], 'end' => ['nullable', 'date_format:Y-m-d', ...($request->filled('start') ? ['after_or_equal:start'] : [])], 'type' => ['nullable', 'string', 'max:100'], 'module' => ['nullable', 'string', 'max:50'], 'outcome' => ['nullable', 'in:success,denied,failed']]);
    }

    public function index(Request $request, ActivityQuery $query): mixed
    {
        $filters = $this->filters($request);
        try {
            $events = $query->search($request->user(), $filters);
            $employees = User::orderBy('name')->get(['id', 'name']);
            $types = AuditEvent::distinct()->orderBy('type')->pluck('type');
            $modules = AuditEvent::distinct()->orderBy('module')->pluck('module');

            return view('activity.index', compact('filters', 'events', 'employees', 'types', 'modules'));
        } catch (QueryException $error) {
            report($error);

            return response()->view('activity.index', compact('filters') + ['unavailable' => true], 503);
        }
    }

    public function show(AuditEvent $event, ActivityQuery $query): mixed
    {
        return view('activity.show', ['detail' => $query->detail($event)]);
    }

    public function export(Request $request, ActivityQuery $query, CsvExporter $csv): mixed
    {
        $filters = $this->filters($request);
        $events = $query->base($request->user(), $filters)->latest('id')->cursor();
        $rows = (function () use ($events, $query) {
            foreach ($events as $event) {
                $detail = $query->detail($event);
                $detail['context'] = json_encode($detail['context'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                yield $detail;
            }
        })();

        return $csv->stream('moto-activity.csv', ['id', 'when_manila', 'employee', 'action', 'record', 'module', 'outcome', 'safe_context'], $rows);
    }
}
