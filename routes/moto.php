<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\SalesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'session.lifetime'])->group(function (): void {
    Route::post('/checkout/{cart}', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::get('/checkout/status/{key}', [CheckoutController::class, 'status'])->name('checkout.status');
    Route::get('/sales/{sale}/receipt', [ReceiptController::class, 'show'])->name('sales.receipt');
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::get('/pos/lookup', [PosController::class, 'lookup'])->name('pos.lookup');
    Route::get('/pos/carts/{cart}', [CartController::class, 'show'])->name('pos.cart');
    Route::post('/pos/carts/{cart}', [CartController::class, 'mutate'])->name('pos.mutate');
    Route::post('/pos/carts/{cart}/hold', [CartController::class, 'hold'])->name('pos.hold');
    Route::post('/pos/carts/{cart}/resume', [CartController::class, 'resume'])->name('pos.resume');
    Route::post('/pos/carts/{cart}/cancel', [CartController::class, 'cancel'])->name('pos.cancel');
    Route::post('/pos/carts/{cart}/approval', [ApprovalController::class, 'store'])->middleware('throttle:10,15')->name('pos.approval');
});

Route::middleware(['auth', 'active', 'session.lifetime'])->group(function (): void {
    Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('/sales/{sale}/returns', [ReturnController::class, 'create'])->name('returns.create');
    Route::post('/sales/{sale}/returns', [ReturnController::class, 'store'])->name('returns.store');
    Route::get('/returns/{returnRequest}', [ReturnController::class, 'show'])->name('returns.show');
    Route::middleware('manager')->group(function (): void {
        Route::post('/returns/{returnRequest}/review', [ReturnController::class, 'review'])->name('returns.review');
        Route::post('/returns/{returnRequest}/refund', [RefundController::class, 'store'])->name('refunds.store');
    });
});
Route::middleware(['auth', 'active', 'session.lifetime'])->group(function (): void {
    Route::get('/sales', [SalesController::class, 'index'])->name('sales.index');
    Route::get('/sales/{sale}', [SalesController::class, 'show'])->name('sales.show');
});
