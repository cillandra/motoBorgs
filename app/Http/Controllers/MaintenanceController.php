<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditRecorder;
use App\Services\Auth\Reauthentication;
use App\Services\System\OperationGuard;
use App\Services\System\RecoveryFiles;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function edit(): mixed
    {
        return view('settings.maintenance', ['enabled' => is_file(app(OperationGuard::class)->directory().'/maintenance.json')]);
    }

    public function update(Request $request, OperationGuard $guard, RecoveryFiles $files, Reauthentication $reauthentication): mixed
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'manager_password' => ['required', 'string', 'max:512'], 'confirmation' => ['required', 'in:MAINTENANCE']]);
        $reauthentication->confirm($request->user(), $data['manager_password'], 'Change maintenance mode');
        $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
        $guard->exclusive(function () use ($request, $guard, $files, $enabled): void {
            abort_unless($request->user()->fresh()?->isManager(), 403);
            abort_if(is_file($guard->directory().'/restore-journal.json'), 503, 'Complete pending recovery before changing maintenance.');
            $path = $guard->directory().'/maintenance.json';
            if ($enabled) {
                $files->atomic($path, ['enabled' => true, 'actor_id' => $request->user()->id, 'changed_at' => now()->toIso8601String()]);
            } elseif (is_file($path)) {
                unlink($path);
            }
            app(AuditRecorder::class)->record($request->user(), 'Maintenance changed', 'system', 'system', 'maintenance', 'success', ['status' => $enabled ? 'enabled' : 'disabled']);
        });

        return $request->expectsJson() ? response()->json(['message' => $enabled ? 'Maintenance enabled; business writes paused.' : 'Maintenance disabled; sales available.']) : redirect()->route('settings.index',['section' => 'system']);
    }
}
