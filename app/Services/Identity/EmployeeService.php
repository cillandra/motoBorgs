<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\System\OperationGuard;
use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeeService
{
    private function change(User $manager, ?User $employee, string $action, Closure $operation): mixed
    {
        try {
            return app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($manager, $employee, $operation): mixed {
                $users = User::orderBy('id')->lockForUpdate()->get();
                $actor = $users->firstWhere('id', $manager->id);
                abort_unless($actor?->isManager(), 403, 'Active Manager access is required.');
                $target = $employee ? $users->firstWhere('id', $employee->id) : null;
                abort_if($employee && ! $target, 404);

                return $operation($actor, $target, $users);
            }, 3));
        } catch (HttpException $error) {
            app(AuditRecorder::class)->record($manager, $action.' denied', 'management', 'employee', (string) ($employee?->id ?? 'new'), 'denied', ['reason' => $error->getMessage()]);
            throw $error;
        }
    }

    public function save(User $manager, ?User $employee, array $validated): User
    {
        return $this->change($manager, $employee, 'Employee change', function (User $actor, ?User $target, $users) use ($validated): User {
            $data = Validator::make($validated, ['name' => ['required', 'string', 'min:2', 'max:100'], 'username' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users', 'username')->ignore($target?->id)], 'email' => ['nullable', 'email', 'max:190'], 'role' => ['required', 'in:manager,staff'],
                'version' => $target ? ['required', 'integer', 'min:1'] : ['nullable'], 'password' => $target ? ['prohibited'] : PasswordPolicy::rules()])->validate();
            if ($target) {
                abort_if((int) $data['version'] !== $target->version, 409, 'This account changed. Reload before saving.');
                abort_if($target->id === $actor->id && $data['role'] !== 'manager', 409, 'You cannot remove your own Manager access.');
                abort_if($target->active && $target->role === 'manager' && $data['role'] !== 'manager' && $users->where('active', true)->where('role', 'manager')->count() <= 1, 409, 'At least one active Manager must remain.');
                $revoke = $target->role !== $data['role'] || $target->username !== $data['username'];
                $target->fill(array_intersect_key($data, array_flip(['name', 'username', 'email', 'role'])));
                $target->version++;
                $target->save();
                if ($revoke) {
                    $target->forceFill(['remember_token' => null])->save();
                    app(SessionService::class)->revoke($target);
                }
                $type = 'Employee updated';
            } else {
                $target = User::create(array_merge(array_intersect_key($data, array_flip(['name', 'username', 'email', 'role', 'password'])), ['active' => true, 'force_password_change' => true]));
                $type = 'Employee created';
            }
            app(AuditRecorder::class)->record($actor, $type, 'management', 'employee', (string) $target->id, 'success', ['changes' => ['name' => $target->name, 'username' => $target->username, 'role' => $target->role, 'active' => $target->active]]);

            return $target->refresh();
        });
    }

    public function deactivate(User $manager, User $employee, string $reason): void
    {
        $this->change($manager, $employee, 'Employee deactivation', function (User $actor, User $target, $users) use ($reason): void {
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();
            abort_if($target->id === $actor->id, 409, 'You cannot deactivate your own account.');
            abort_if($target->active && $target->role === 'manager' && $users->where('active', true)->where('role', 'manager')->count() <= 1, 409, 'At least one active Manager must remain.');
            if (! $target->active) {
                return;
            }
            $target->forceFill(['active' => false, 'remember_token' => null, 'version' => $target->version + 1])->save();
            app(SessionService::class)->revoke($target);
            DB::table('access_reset_tokens')->where('user_id', $target->id)->whereNull('used_at')->update(['used_at' => now()]);
            app(AuditRecorder::class)->record($actor, 'Employee deactivated', 'management', 'employee', (string) $target->id, 'success', ['reason' => $reason, 'changes' => ['name' => $target->name, 'active' => false]]);
        });
    }

    public function reactivate(User $manager, User $employee): void
    {
        $this->change($manager, $employee, 'Employee reactivation', function (User $actor, User $target): void {
            if ($target->active) {
                return;
            }
            $target->forceFill(['active' => true, 'version' => $target->version + 1])->save();
            app(AuditRecorder::class)->record($actor, 'Employee reactivated', 'management', 'employee', (string) $target->id, 'success', ['changes' => ['name' => $target->name, 'active' => true]]);
        });
    }
}
