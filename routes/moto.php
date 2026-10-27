<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RecoveryController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SettingsController;
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
Route::middleware(['auth', 'active', 'session.lifetime', 'manager'])->group(function (): void {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    Route::get('/reports/print', [ReportController::class, 'print'])->name('reports.print');
});
Route::middleware(['auth', 'active', 'session.lifetime', 'manager'])->group(function (): void {
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{employee}/deactivate', [EmployeeController::class, 'status'])->name('employees.deactivate');
    Route::post('/employees/{employee}/reactivate', [EmployeeController::class, 'status'])->name('employees.reactivate');
    Route::post('/employees/{employee}/recovery', [RecoveryController::class, 'issue'])->name('employees.recovery');
    Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');
    Route::get('/activity/export', [ActivityController::class, 'export'])->name('activity.export');
    Route::get('/activity/{event}', [ActivityController::class, 'show'])->name('activity.show');
});
Route::get('/access/reset', [RecoveryController::class, 'form'])->name('recovery.form');
Route::post('/access/reset', [RecoveryController::class, 'complete'])->middleware('throttle:10,15')->name('recovery.complete');
Route::middleware(['auth', 'active', 'session.lifetime'])->group(function (): void {
    Route::get('/access/change', [RecoveryController::class, 'change'])->name('password.change');
    Route::post('/access/change', [RecoveryController::class, 'update'])->middleware('throttle:5,15')->name('password.update');
});
Route::middleware(['auth', 'active', 'session.lifetime'])->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/preferences', [PreferenceController::class, 'update'])->name('preferences.update');
    Route::post('/settings/account/password', [AccountController::class, 'password'])->middleware('throttle:5,15')->name('account.password');
    Route::post('/settings/account/sessions', [AccountController::class, 'sessions'])->middleware('throttle:5,15')->name('account.sessions');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'all'])->name('notifications.all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification')->name('notifications.read');
    Route::middleware('manager')->group(function (): void {
        Route::get('/settings/receipt-preview', [SettingsController::class, 'receiptPreview'])->name('settings.receipt-preview');
        Route::put('/settings/{section}', [SettingsController::class, 'update'])->name('settings.update');
    });
});

Route::middleware(['auth', 'active', 'session.lifetime', 'manager'])->group(function (): void {
    Route::get('/backups/create', [BackupController::class, 'create'])->name('backups.create');
    Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
    Route::get('/backups/{backup}', [BackupController::class, 'show'])->whereNumber('backup')->name('backups.show');
    Route::get('/backups/{backup}/download', [BackupController::class, 'download'])->whereNumber('backup')->name('backups.download');
    Route::post('/backups/{backup}/restore', [BackupController::class, 'restore'])->whereNumber('backup')->middleware('throttle:5,15')->name('backups.restore');
    Route::get('/maintenance', [MaintenanceController::class, 'edit'])->name('maintenance.edit');
    Route::post('/maintenance', [MaintenanceController::class, 'update'])->middleware('throttle:5,15')->name('maintenance.update');
});
