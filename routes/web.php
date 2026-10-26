<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::select('SELECT 1');
    } catch (Throwable) {
        return response()->json(['app' => 'MOTO', 'database' => 'unavailable'], 503);
    }

    return response()->json(['app' => 'MOTO', 'database' => 'ok']);
});

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'active', 'session.lifetime'])->group(function (): void {
    Route::get('/dashboard', fn () => view('dashboard.index'))->name('dashboard');
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/{variant}', [InventoryController::class, 'show'])->name('inventory.show');
    Route::get('/products/{product}/image', [ProductController::class, 'image'])->name('products.image');
    Route::middleware('manager')->group(function (): void {
        Route::get('/inventory/{variant}/stock', [StockController::class, 'form'])->name('stock.form');
        Route::post('/inventory/{variant}/stock', [StockController::class, 'store'])->name('stock.store');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/archive', [ProductController::class, 'archive'])->name('products.archive');
        Route::post('/products/{product}/restore', [ProductController::class, 'restore'])->name('products.restore');
    });
});

require __DIR__.'/moto.php';
