<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockRequest;
use App\Models\Variant;
use App\Services\Inventory\StockLedger;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function form(Request $request, Variant $variant): mixed
    {
        abort_if($variant->archived_at || $variant->product->archived_at, 409, 'Restore the product before changing stock.');
        $type = $request->query('type') === 'adjust' ? 'adjust' : 'receive';

        return view('inventory.stock-form', compact('variant', 'type'));
    }

    public function store(StockRequest $request, Variant $variant, StockLedger $ledger): mixed
    {
        $data = $request->validated();
        $count = match ($data['adjustment_mode'] ?? 'count') {
            'increase' => (int) $data['base_stock'] + (int) $data['quantity'],
            'decrease' => (int) $data['base_stock'] - (int) $data['quantity'],
            default => (int) $data['quantity'],
        };
        $movement = $data['type'] === 'receive'
            ? $ledger->receive($request->user(), $variant, (int) $data['quantity'], $data['reference'], $data['reason'], $data['operation_key'], $data['date'] ?? null)
            : $ledger->adjustToCount($request->user(), $variant, $count, $data['reason'], $data['operation_key'], (int) $data['expected_version']);

        return $request->expectsJson() ? response()->json(['movement_id' => $movement->id, 'stock' => $movement->balance_after]) : redirect()->route('inventory.show', $variant)->with('status', 'Stock movement recorded.');
    }
}
