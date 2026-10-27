<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReturnRequest as ReturnSubmission;
use App\Models\ReturnRequest;
use App\Models\Sale;
use App\Services\Auth\Reauthentication;
use App\Services\Sales\ReturnService;
use App\Services\Settings\PreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReturnController extends Controller
{
    public function index(Request $request): mixed
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,accepted,rejected']]);
        $returns = ReturnRequest::with(['sale', 'requester', 'refunds', 'lines.saleLine' => fn ($line) => $line->withSum(['returnLines as accepted_return_quantity' => fn ($returned) => $returned->whereHas('request', fn ($request) => $request->where('status', 'accepted'))], 'quantity')])->withCount('lines')
            ->when(! $request->user()->isManager(), fn ($q) => $q->whereHas('sale', fn ($sale) => $sale->where('cashier_id', $request->user()->id)))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))->latest('id')->paginate(app(PreferenceService::class)->rows($request->user()))->withQueryString();

        return view('sales.returns', compact('returns', 'filters'));
    }

    public function create(Request $request, Sale $sale, ReturnService $service): mixed
    {
        Gate::authorize('view', $sale);
        $sale->load(['lines', 'payment']);
        $quantities = [];
        foreach ($sale->lines as $line) {
            $quantities[$line->id] = $service->quantities($line);
        }

        return view('sales.return-form', compact('sale', 'quantities'));
    }

    public function store(ReturnSubmission $request, Sale $sale, ReturnService $service): mixed
    {
        $data = $request->validated();
        $quantities = array_filter(array_map(fn ($quantity) => (int) $quantity, $data['lines']), fn ($quantity) => $quantity > 0);
        $return = $service->request($request->user(), $sale, $quantities, $data['reason']);

        return $request->expectsJson() ? response()->json(['return_id' => $return->id, 'modal_url' => route('returns.show', $return), 'message' => 'Return request recorded. Manager review is pending.'], 201)
            : redirect()->route('returns.show', $return)->with('status', 'Return request recorded.');
    }

    public function show(Request $request, ReturnRequest $returnRequest): mixed
    {
        Gate::authorize('view', $returnRequest);
        $returnRequest->load(['sale.payment', 'lines.saleLine', 'refunds', 'requester', 'reviewer']);
        $eligible = (int) $returnRequest->lines->sum('refundable_centavos');
        $paid = (int) $returnRequest->refunds->where('status', 'paid')->sum('amount_centavos');
        $pending = (int) $returnRequest->refunds->where('status', 'pending')->sum('amount_centavos');

        return view('sales.return-detail', ['return' => $returnRequest, 'eligible' => $eligible, 'paid' => $paid, 'pending' => $pending, 'remaining' => max(0, $eligible - $paid - $pending)]);
    }

    public function review(Request $request, ReturnRequest $returnRequest, ReturnService $service, Reauthentication $reauthentication): mixed
    {
        Gate::authorize('review', $returnRequest);
        $data = $request->validate(['accept' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'password' => ['required', 'string', 'max:1024'],
            'conditions' => ['nullable', 'array', 'max:100'], 'conditions.*.restock_quantity' => ['required', 'integer', 'min:0', 'max:1000000']]);
        $reauthentication->confirm($request->user(), $data['password'], 'Return review');
        $conditions = [];
        foreach ($data['conditions'] ?? [] as $id => $condition) {
            $conditions[$id] = ['restock_quantity' => (int) $condition['restock_quantity']];
        }
        $return = $service->review($request->user(), $returnRequest, $conditions, $request->boolean('accept'), $data['reason']);

        return $request->expectsJson() ? response()->json(['return_id' => $return->id, 'modal_url' => route('returns.show', $return), 'message' => $return->status === 'accepted' ? 'Return approved. Record the payout outcome separately.' : 'Return request rejected.'])
            : redirect()->route('returns.show', $return)->with('status', 'Return decision recorded.');
    }
}
