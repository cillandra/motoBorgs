<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApprovalRequest;
use App\Models\Cart;
use App\Models\User;
use App\Services\Pos\ApprovalService;
use App\Services\Pos\CartService;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class ApprovalController extends Controller
{
    public function store(ApprovalRequest $request, Cart $cart, ApprovalService $approvals, CartService $carts): mixed
    {
        $data = $request->validated();
        $manager = User::where('username', $data['manager_username'])->first();
        if (! $manager) {
            throw ValidationException::withMessages(['manager_username' => 'Manager credentials could not be verified.']);
        }
        try {
            $parameters = $data['action'] === 'discount' ? ['discount_centavos' => Money::parse($data['discount'])] : [];
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['discount' => $error->getMessage()]);
        }

        $approval = $approvals->approve($manager, $data['manager_password'], $cart, (int) $data['version'], $data['action'], $parameters, $data['reason']);
        if ($data['action'] === 'cancel_exception') {
            $carts->cancel($request->user(), $cart, (int) $data['version'], $data['reason'], $approval->id);
        }
        if ($request->expectsJson()) {
            return response()->json(['approval_id' => $approval->id, 'cart_id' => $cart->id, 'next' => route('pos.index', $data['action'] === 'discount' ? ['cart' => $cart->id] : [])]);
        }

        return redirect()->route('pos.index', $data['action'] === 'discount' ? ['cart' => $cart->id] : [])->with('status', $data['action'] === 'discount' ? 'Manager discount approved for this order.' : 'Unpaid cart voided.');
    }
}
