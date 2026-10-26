<?php

namespace App\Http\Controllers;

use App\Http\Requests\CartMutationRequest;
use App\Models\Cart;
use App\Services\Pos\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    private function result(Request $request, Cart $cart, CartService $service): mixed
    {
        return $request->expectsJson()
            ? response()->json(['cart_id' => $cart->id, 'quote' => $service->quote($request->user(), $cart)])
            : redirect()->route('pos.index', ['cart' => $cart->id]);
    }

    public function show(Request $request, Cart $cart, CartService $service): mixed
    {
        return response()->json($service->quote($request->user(), $cart));
    }

    public function mutate(CartMutationRequest $request, Cart $cart, CartService $service): mixed
    {
        $data = $request->validated();
        if (isset($data['quantity'])) {
            $data['quantity'] = (int) $data['quantity'];
        }
        $cart = $service->mutate($request->user(), $cart, (int) $data['version'], $data);

        return $this->result($request, $cart, $service);
    }

    public function hold(Request $request, Cart $cart, CartService $service): mixed
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->hold($request->user(), $cart, (int) $data['version']);

        return $request->expectsJson() ? response()->json(['next' => route('pos.index')]) : redirect()->route('pos.index')->with('status', 'Cart held. Stock is not reserved.');
    }

    public function resume(Request $request, Cart $cart, CartService $service): mixed
    {
        return $this->result($request, $service->resume($request->user(), $cart), $service);
    }

    public function cancel(Request $request, Cart $cart, CartService $service): mixed
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $service->cancel($request->user(), $cart, (int) $data['version'], $data['reason'], null);

        return $request->expectsJson() ? response()->json(['next' => route('pos.index')]) : redirect()->route('pos.index')->with('status', 'Draft cancelled.');
    }
}
