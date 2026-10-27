<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\Reauthentication;
use App\Services\Identity\RecoveryService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecoveryController extends Controller
{
    public function issue(Request $request, User $employee, RecoveryService $service, Reauthentication $reauthentication): mixed
    {
        $data = $request->validate(['manager_password' => ['required', 'string', 'max:1024'], 'confirmed' => ['accepted']]);
        $reauthentication->confirm($request->user(), $data['manager_password'], 'Assisted recovery');
        $token = $service->issue($request->user(), $employee);
        $html = view('employees.reset-code', compact('employee', 'token'))->render();

        return $request->expectsJson() ? response()->json(['modal_html' => $html, 'message' => 'Recovery code issued. Show it only to this employee.'])->header('Cache-Control', 'private, no-store') : response($html)->header('Cache-Control', 'private, no-store');
    }

    public function form(): mixed
    {
        return response()->view('auth.reset', ['mode' => 'recovery'])->header('Cache-Control', 'private, no-store');
    }

    public function complete(Request $request, RecoveryService $service): mixed
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64'], 'password' => [...PasswordPolicy::rules(), 'confirmed']]);
        $service->complete($data['token'], $data['password']);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your passphrase has been changed. Sign in with it.');
    }

    public function change(Request $request): mixed
    {
        abort_unless($request->user()->force_password_change, 409, 'A required password change is not pending.');

        return response()->view('auth.reset', ['mode' => 'required'])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, RecoveryService $service, Reauthentication $reauthentication): mixed
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:1024'], 'password' => [...PasswordPolicy::rules(), 'confirmed']]);
        $reauthentication->confirm($request->user(), $data['current_password'], 'Required passphrase change');
        $service->changeRequired($request->user(), $data['current_password'], $data['password']);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your passphrase has been changed. Sign in with it.');
    }
}
