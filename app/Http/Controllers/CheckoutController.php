<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\Cart;
use App\Services\Pos\CheckoutService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request, Cart $cart, CheckoutService $service): mixed
    {
        $data = $request->validated();
        try {
            $received = Money::parse($data['received']);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['received' => $error->getMessage()]);
        }
        $result = $service->complete($request->user(), $cart, (int) $data['version'], $data['request_key'],
            ['method' => $data['method'], 'received_centavos' => $received, 'reference' => $data['reference'] ?? '', 'verified' => $request->boolean('verified')]);
        if (! $result->replayed) {
            $request->session()->put('moto.receipt-original.'.$result->sale->id, true);
        }
        $url = route('sales.receipt', $result->sale);

        return $request->expectsJson() ? response()->json(['sale_id' => $result->sale->id, 'replayed' => $result->replayed, 'receipt_url' => $url], $result->replayed ? 200 : 201) : redirect($url);
    }

    public function status(Request $request, string $key, CheckoutService $service): mixed
    {
        abort_if(strlen($key) > 80, 404);
        $sale = $service->status($request->user(), $key);

        return response()->json(['status' => $sale ? 'completed' : 'not_found', 'sale_id' => $sale?->id, 'receipt_url' => $sale ? route('sales.receipt', $sale) : null]);
    }
}
