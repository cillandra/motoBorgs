<?php

namespace App\Services\Identity;

use App\Models\AccessResetToken;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Settings\NotificationService;
use App\Services\System\OperationGuard;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RecoveryService
{
    public function issue(User $manager, User $employee): string
    {
        try {
            return app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($manager, $employee): string {
                $users = User::orderBy('id')->lockForUpdate()->get();
                $manager = $users->firstWhere('id', $manager->id);
                $employee = $users->firstWhere('id', $employee->id);
                abort_unless($manager?->isManager(), 403, 'Active Manager access is required.');
                abort_unless($employee?->active, 409, 'Reactivate the account before issuing recovery.');
                abort_if($manager->id === $employee->id, 409, 'Use My account to change your own password.');
                AccessResetToken::where('user_id', $employee->id)->whereNull('used_at')->update(['used_at' => now()]);
                $token = Str::random(64);
                $reset = AccessResetToken::create(['user_id' => $employee->id, 'issued_by' => $manager->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(15)]);
                $employee->forceFill(['force_password_change' => true, 'password' => Hash::make(Str::random(64)), 'remember_token' => null, 'version' => $employee->version + 1])->save();
                app(SessionService::class)->revoke($employee);
                app(AuditRecorder::class)->record($manager, 'Recovery code issued', 'management', 'employee', (string) $employee->id, 'success');

                app(NotificationService::class)->publish('recovery', 'employee', (string) $employee->id, ['event_id' => $reset->id.':issued']);

                return $token;
            }, 3));
        } catch (HttpException $error) {
            app(AuditRecorder::class)->record($manager, 'Recovery issuance denied', 'management', 'employee', (string) $employee->id, 'denied');
            throw $error;
        }
    }

    public function complete(string $token, string $newPassword): void
    {
        Validator::make(['password' => $newPassword], ['password' => PasswordPolicy::rules()])->validate();
        try {
            app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($token, $newPassword): void {
                $lookup = AccessResetToken::where('token_hash', hash('sha256', $token))->first();
                abort_unless($lookup, 422, 'The recovery code is invalid or expired. Ask your Manager for a new code.');
                $user = User::whereKey($lookup->user_id)->lockForUpdate()->first();
                $reset = AccessResetToken::whereKey($lookup->id)->lockForUpdate()->first();
                abort_unless($user?->active && $reset && ! $reset->used_at && $reset->expires_at->isFuture(), 422, 'The recovery code is invalid or expired. Ask your Manager for a new code.');
                $user->forceFill(['password' => Hash::make($newPassword), 'force_password_change' => false, 'remember_token' => null, 'version' => $user->version + 1])->save();
                $reset->update(['used_at' => now()]);
                app(SessionService::class)->revoke($user);
                app(AuditRecorder::class)->record($user, 'Assisted recovery completed', 'authentication', 'employee', (string) $user->id, 'success');
                app(NotificationService::class)->publish('recovery', 'employee', (string) $user->id, ['event_id' => $reset->id.':completed']);
            }, 3));
        } catch (HttpException $error) {
            app(AuditRecorder::class)->record(null, 'Recovery code rejected', 'authentication', 'recovery', 'unverified', 'denied');
            throw $error;
        }
    }

    public function changeRequired(User $user, string $currentPassword, string $newPassword): void
    {
        Validator::make(['password' => $newPassword], ['password' => PasswordPolicy::rules()])->validate();
        app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($user, $currentPassword, $newPassword): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active && $user->force_password_change, 409, 'A required password change is no longer pending.');
            abort_unless(Hash::check($currentPassword, $user->password), 422, 'The current passphrase could not be verified.');
            abort_if(Hash::check($newPassword, $user->password), 422, 'Choose a different passphrase.');
            $user->forceFill(['password' => Hash::make($newPassword), 'force_password_change' => false, 'remember_token' => null, 'version' => $user->version + 1])->save();
            app(SessionService::class)->revoke($user);
            app(AuditRecorder::class)->record($user, 'Required password changed', 'authentication', 'employee', (string) $user->id, 'success');
        }, 3));
    }
}
