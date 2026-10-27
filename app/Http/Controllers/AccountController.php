<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Auth\Reauthentication;
use App\Services\Identity\SessionService;
use App\Services\System\OperationGuard;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function password(Request $request, Reauthentication $reauthentication): mixed
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:1024'], 'password' => [...PasswordPolicy::rules(), 'confirmed']]);
        $reauthentication->confirm($request->user(), $data['current_password'], 'Own passphrase change');
        app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($request, $data): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active, 403);
            abort_unless(Hash::check($data['current_password'], $user->password), 422, 'The current passphrase could not be verified.');
            abort_if(Hash::check($data['password'], $user->password), 422, 'Choose a different passphrase.');
            $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => null, 'force_password_change' => false, 'version' => $user->version + 1])->save();
            app(SessionService::class)->revoke($user);
            app(AuditRecorder::class)->record($user, 'Own password changed', 'authentication', 'employee', (string) $user->id, 'success');
        }, 3));
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->json(['redirect' => route('login'), 'message' => 'Passphrase changed. Sign in again.']) : redirect()->route('login')->with('status', 'Passphrase changed. Sign in again.');
    }

    public function sessions(Request $request, Reauthentication $reauthentication): mixed
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:1024']]);
        $reauthentication->confirm($request->user(), $data['current_password'], 'End other sessions');
        app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($request): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active, 403);
            $user->forceFill(['remember_token' => null])->save();
            app(SessionService::class)->revoke($user, $request->session()->getId());
            app(AuditRecorder::class)->record($user, 'Other sessions ended', 'authentication', 'employee', (string) $user->id, 'success');
        }));

        return $request->expectsJson() ? response()->json(['modal_url' => route('settings.index', ['section' => 'account']), 'message' => 'Other sessions ended.']) : redirect()->route('settings.index', ['section' => 'account'])->with('status', 'Other sessions ended.');
    }
}
