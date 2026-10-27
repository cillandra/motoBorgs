<?php

namespace App\Http\Controllers;

use App\Models\BackupManifest;
use App\Services\System\BackupService;
use App\Services\System\OperationGuard;
use App\Services\System\RestoreService;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function create(): mixed
    {
        return view('settings.backup-create');
    }

    public function store(Request $request, BackupService $backups): mixed
    {
        $backup = $backups->create($request->user());

        return $request->expectsJson() ? response()->json(['message' => 'Verified backup created.', 'modal_url' => route('backups.show', $backup)]) : redirect()->route('backups.show', $backup);
    }

    public function show(Request $request, BackupManifest $backup, RestoreService $restore): mixed
    {
        return view('settings.backup-inspect', $restore->inspect($request->user(), $backup));
    }

    public function download(Request $request, BackupManifest $backup, BackupService $backups): mixed
    {
        app(OperationGuard::class)->administrative(fn () => $backups->verify($backup));

        return response()->streamDownload(function () use ($backup, $backups): void {
            app(OperationGuard::class)->administrative(function () use ($backup, $backups): void {
                $backups->verify($backup);
                $stream = fopen($backups->path($backup), 'rb');
                if (! $stream) {
                    throw new \RuntimeException('Backup is no longer available.');
                }
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            });
        }, 'moto-'.$backup->identifier.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function restore(Request $request, BackupManifest $backup, RestoreService $restore): mixed
    {
        $data = $request->validate(['manager_password' => ['required', 'string', 'max:512'], 'confirmation' => ['required', 'in:RESTORE']]);
        $restore->restore($request->user(), $backup, $data['manager_password'], $data['confirmation']);

        return $request->expectsJson() ? response()->json(['message' => 'Recovery completed. Sign in again.', 'redirect' => route('login')]) : redirect()->route('login');
    }
}
