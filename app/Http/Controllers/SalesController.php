<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\Reporting\SalesQuery;
use App\Services\Settings\PreferenceService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SalesController extends Controller
{
    public function index(Request $request, SalesQuery $query): mixed
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'start' => ['nullable', 'date_format:Y-m-d'], 'end' => ['nullable', 'date_format:Y-m-d', ...($request->filled('start') ? ['after_or_equal:start'] : [])], 'method' => ['nullable', 'in:cash,gcash,qrph,debit,credit'], 'status' => ['nullable', 'in:completed,partial,refunded'], 'cashier_id' => ['nullable', 'integer', 'min:1']]);
        try {
            $sales = $query->search($request->user(), $filters, app(PreferenceService::class)->rows($request->user()));
            $summary = $query->summary($request->user(), $filters);
            $cashiers = $request->user()->isManager() ? Sale::select('cashier_id', 'cashier_name')->distinct()->orderBy('cashier_name')->get() : collect();

            return view('sales.index', compact('sales', 'summary', 'filters', 'cashiers'));
        } catch (QueryException $error) {
            report($error);

            return response()->view('sales.index', ['filters' => $filters, 'unavailable' => true], 503);
        }
    }

    public function show(Request $request, Sale $sale): mixed
    {
        Gate::authorize('view', $sale);
        $sale->load(['lines', 'payment', 'returns.refunds', 'returns.lines']);
        $paid = (int) $sale->refunds()->where('refund_records.status', 'paid')->sum('amount_centavos');

        return view('sales.show', compact('sale', 'paid'));
    }
}
