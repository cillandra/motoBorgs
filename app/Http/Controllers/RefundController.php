<?php

namespace App\Http\Controllers;

use App\Http\Requests\RefundRequest;
use App\Models\ReturnRequest;
use App\Services\Auth\Reauthentication;
use App\Services\Sales\RefundService;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    public function store(RefundRequest $request, ReturnRequest $returnRequest, RefundService $service, Reauthentication $reauthentication): mixed
    {
        $data = $request->validated();
        $reauthentication->confirm($request->user(), $data['password'], 'Refund payout outcome');
        try {
            $amount = Money::parse($data['amount']);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['amount' => $error->getMessage()]);
        }
        $refund = $service->recordOutcome($request->user(), $returnRequest, $data['operation_key'], $amount, $data['status'], $data['reference']);

        return $request->expectsJson() ? response()->json(['refund_id' => $refund->id, 'status' => $refund->status, 'modal_url' => route('returns.show', $returnRequest), 'message' => 'Payout outcome recorded: '.$refund->status.'.'])
            : redirect()->route('returns.show', $returnRequest)->with('status', 'Payout outcome recorded.');
    }
}
