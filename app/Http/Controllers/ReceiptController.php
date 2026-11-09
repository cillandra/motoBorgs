<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Setting;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReceiptController extends Controller
{
    public function invoice(Request $request, Sale $sale): mixed
    {
        Gate::authorize('view', $sale);
        $sale->load(['lines', 'payment']);
        $shop = Setting::where('section', 'general')->first()?->value ?? [];
        app(AuditRecorder::class)->record($request->user(), 'Invoice preview opened', 'sales', 'sale', (string) $sale->id, 'success');

        return response()->view('sales.invoice', compact('sale', 'shop'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Sale $sale): mixed
    {
        Gate::authorize('view', $sale);
        $data = $request->validate(['width' => ['nullable', 'integer', 'in:58,80'], 'copy' => ['nullable', 'boolean']]);
        $receiptSettings = Setting::where('section', 'receipts')->first()?->value ?? [];
        $width = (int) ($data['width'] ?? $receiptSettings['width'] ?? 80);
        $original = $request->session()->pull('moto.receipt-original.'.$sale->id, false);
        $copy = ! $original || $request->boolean('copy');
        $sale->load(['lines', 'payment']);
        $shop = Setting::where('section', 'general')->first()?->value ?? ['shop_name' => 'BORG’S Motorcycle Parts and Accessories'];
        app(AuditRecorder::class)->record($request->user(), $copy ? 'Receipt copy opened' : 'Receipt preview opened', 'sales', 'sale', (string) $sale->id, 'success');

        return response()->view('sales.receipt', compact('sale', 'width', 'copy', 'shop', 'receiptSettings'))->header('Cache-Control', 'private, no-store');
    }
}
