<?php

namespace Tests\Feature;

use App\Models\AccessResetToken;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\ActivityQuery;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\EmployeeService;
use App\Services\Identity\RecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class EmployeeActivityTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private function signedIn(User $user): static
    {
        return $this->actingAs($user)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
    }

    private function denied(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Account operation unexpectedly succeeded.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function sessionFor(User $user): void
    {
        DB::table('sessions')->insert(['id' => 'fixture-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    }

    public function test_employee_creation_enforces_unique_username_fixed_roles_and_long_passphrases(): void
    {
        $manager = $this->manager();
        $service = app(EmployeeService::class);
        $employee = $service->save($manager, null, ['name' => 'School cashier', 'username' => 'cashier.demo', 'role' => 'staff', 'password' => 'A locally assigned school passphrase!']);
        $this->assertTrue($employee->active);
        $this->assertTrue($employee->force_password_change);
        $this->assertTrue(Hash::check('A locally assigned school passphrase!', $employee->password));
        foreach ([['username' => 'cashier.demo'], ['role' => 'admin'], ['password' => 'too short']] as $invalid) {
            try {
                $service->save($manager, null, array_merge(['name' => 'Other', 'username' => 'other.demo', 'role' => 'staff', 'password' => 'Another locally assigned passphrase!'], $invalid));
                $this->fail('Invalid account data accepted.');
            } catch (ValidationException) {
                $this->assertSame(2, User::count());
            }
        }
    }

    public function test_self_changes_cannot_remove_manager_access_and_denial_survives_rollback(): void
    {
        $manager = $this->manager();
        $service = app(EmployeeService::class);
        $this->denied(409, fn () => $service->deactivate($manager, $manager, 'Should be blocked'));
        $this->denied(409, fn () => $service->save($manager, $manager, ['name' => $manager->name, 'username' => $manager->username, 'role' => 'staff', 'version' => $manager->version]));
        $this->assertTrue($manager->fresh()->isManager());
        $this->assertSame(2, AuditEvent::where('module', 'management')->where('outcome', 'denied')->count());
        $this->assertSame(1, User::where('role', 'manager')->where('active', true)->count());
    }

    public function test_account_status_revokes_sessions_and_retains_identity_and_reactivation(): void
    {
        $manager = $this->manager();
        $employee = $this->staff();
        $this->sessionFor($employee);
        $name = $employee->name;
        $service = app(EmployeeService::class);
        $service->deactivate($manager, $employee, 'Academic access review');
        $this->assertFalse($employee->fresh()->active);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $employee->id)->count());
        $this->assertSame($name, User::find($employee->id)->name);
        $service->reactivate($manager, $employee);
        $this->assertTrue($employee->fresh()->active);
        $this->assertSame($name, AuditEvent::where('subject_id', (string) $employee->id)->where('type', 'Employee deactivated')->first()->context['changes']['name']);
    }

    public function test_role_change_is_versioned_revokes_sessions_and_rechecks_current_actor(): void
    {
        $manager = $this->manager();
        $other = $this->manager();
        $service = app(EmployeeService::class);
        $this->sessionFor($other);
        $service->save($manager, $other, ['name' => $other->name, 'username' => $other->username, 'role' => 'staff', 'version' => $other->version]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->denied(403, fn () => $service->deactivate($other, $manager, 'Stale Manager object'));
        $this->denied(409, fn () => $service->save($manager, $other, ['name' => 'Stale change', 'username' => $other->username, 'role' => 'staff', 'version' => $other->version]));
        $this->assertSame(1, User::where('active', true)->where('role', 'manager')->count());
    }

    public function test_assisted_recovery_is_hashed_one_use_and_replaces_access_without_email(): void
    {
        $manager = $this->manager();
        $employee = $this->staff();
        $this->sessionFor($employee);
        $service = app(RecoveryService::class);
        $token = $service->issue($manager, $employee);
        $record = AccessResetToken::first();
        $this->assertSame(hash('sha256', $token), $record->token_hash);
        $this->assertNotSame($token, $record->token_hash);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $employee->id)->count());
        $this->assertTrue($employee->fresh()->force_password_change);
        $this->assertFalse(Hash::check('Unit test passphrase 2026!', $employee->fresh()->password));
        $service->complete($token, 'My newly chosen school passphrase!');
        $this->assertTrue(Hash::check('My newly chosen school passphrase!', $employee->fresh()->password));
        $this->assertFalse($employee->fresh()->force_password_change);
        $this->denied(422, fn () => $service->complete($token, 'Another new school passphrase!'));
        $this->assertStringNotContainsString($token, AuditEvent::all()->toJson());
    }

    public function test_expired_or_superseded_recovery_codes_and_weak_passwords_are_rejected(): void
    {
        $manager = $this->manager();
        $employee = $this->staff();
        $service = app(RecoveryService::class);
        $first = $service->issue($manager, $employee);
        $second = $service->issue($manager, $employee);
        $this->denied(422, fn () => $service->complete($first, 'Valid but superseded passphrase!'));
        try {
            $service->complete($second, 'short');
            $this->fail('Weak recovery password accepted.');
        } catch (ValidationException) {
            $this->assertNull(AccessResetToken::latest('id')->first()->used_at);
        }
        $this->travel(16)->minutes();
        $this->denied(422, fn () => $service->complete($second, 'A long but expired passphrase!'));
        $this->travelBack();
    }

    public function test_forced_password_change_blocks_every_business_route_until_completed(): void
    {
        $employee = $this->staff();
        $employee->update(['force_password_change' => true]);
        $this->signedIn($employee);
        foreach (['/dashboard', '/inventory', '/pos', '/sales', '/returns'] as $url) {
            $this->get($url)->assertRedirect(route('password.change'));
        }
        $this->postJson('/pos/carts/999', ['action' => 'add'])->assertStatus(423);
        $this->get('/access/change')->assertOk()->assertSee('Choose your own passphrase');
        $this->post('/access/change', ['current_password' => 'Unit test passphrase 2026!', 'password' => 'My own demonstration passphrase!', 'password_confirmation' => 'My own demonstration passphrase!'])->assertRedirect(route('login'));
        $this->assertFalse($employee->fresh()->force_password_change);
        $this->assertGuest();
    }

    public function test_changing_own_username_requires_signing_in_again(): void
    {
        $manager = $this->manager();
        $this->signedIn($manager);
        $this->putJson('/employees/'.$manager->id, ['name' => $manager->name, 'username' => 'new.manager', 'role' => 'manager', 'version' => $manager->version, 'manager_password' => 'Unit test passphrase 2026!'])->assertOk()->assertJsonPath('redirect', route('login'));
        $this->assertGuest();
        $this->assertSame('new.manager', $manager->fresh()->username);
    }

    public function test_http_manager_role_confirmation_and_one_time_recovery_result_are_private(): void
    {
        $manager = $this->manager();
        $this->signedIn($manager);
        $input = ['name' => 'Demo second Manager', 'username' => 'SECOND.MANAGER', 'role' => 'manager', 'password' => 'A school temporary passphrase!', 'manager_password' => 'Unit test passphrase 2026!'];
        $this->postJson('/employees', $input)->assertUnprocessable()->assertJsonValidationErrors('role_confirmed');
        $this->postJson('/employees', $input + ['role_confirmed' => true])->assertOk();
        $employee = User::where('username', 'second.manager')->firstOrFail();
        $this->assertTrue($employee->force_password_change);
        $response = $this->postJson('/employees/'.$employee->id.'/recovery', ['manager_password' => 'Unit test passphrase 2026!', 'confirmed' => true])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('Private recovery code', $response->json('modal_html'));
        $this->assertStringContainsString('expires in 15 minutes', $response->json('modal_html'));
        $this->get('/employees/'.$employee->id)->assertOk()->assertDontSee(AccessResetToken::latest('id')->first()->token_hash);
    }

    public function test_sensitive_http_changes_require_reauthentication_and_staff_cannot_manage_or_export(): void
    {
        $manager = $this->manager();
        $staff = $this->staff();
        $this->signedIn($staff);
        foreach (['/employees', '/employees/create', '/employees/'.$manager->id, '/activity', '/activity/export'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->postJson('/employees/'.$manager->id.'/deactivate', ['reason' => 'Forbidden', 'manager_password' => 'Unit test passphrase 2026!'])->assertForbidden();
        $this->signedIn($manager)->postJson('/employees/'.$staff->id.'/deactivate', ['reason' => 'Access review', 'manager_password' => 'incorrect'])->assertUnprocessable();
        $this->assertTrue($staff->fresh()->active);
        $this->postJson('/employees/'.$staff->id.'/deactivate', ['reason' => 'Access review', 'manager_password' => 'Unit test passphrase 2026!'])->assertOk();
    }

    public function test_activity_filters_and_details_export_are_safe_even_for_legacy_context(): void
    {
        $manager = $this->manager();
        $staff = $this->staff();
        $event = app(AuditRecorder::class)->record($staff, 'Sale completed', 'sales', 'sale', '123', 'success', ['reason' => 'School fixture']);
        $event->update(['context' => ['password' => 'DO-NOT-EXPOSE', 'changes' => ['api_token' => 'TOKEN-SECRET', 'name' => 'Safe name'], 'reason' => 'School fixture']]);
        app(AuditRecorder::class)->record($manager, 'Access denied', 'security', 'route', 'reports.index', 'denied');
        $filters = ['q' => 'School', 'employee_id' => $staff->id, 'start' => now()->timezone('Asia/Manila')->format('Y-m-d'), 'end' => now()->timezone('Asia/Manila')->format('Y-m-d'), 'type' => 'Sale completed', 'module' => 'sales', 'outcome' => 'success'];
        foreach ($filters as $key => $value) {
            $this->assertContains($event->id, app(ActivityQuery::class)->search($manager, [$key => $value])->pluck('id')->all(), 'Filter failed: '.$key);
        }
        $this->assertSame([$event->id], app(ActivityQuery::class)->search($manager, $filters)->pluck('id')->all());
        $this->signedIn($manager)->get('/activity?'.http_build_query($filters))->assertOk()->assertSee('Sale completed')->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$event->id]);
        $this->get('/activity/'.$event->id)->assertOk()->assertSee('Safe name')->assertDontSee('DO-NOT-EXPOSE')->assertDontSee('TOKEN-SECRET');
        $csv = $this->get('/activity/export?'.http_build_query($filters))->assertOk()->streamedContent();
        $this->assertStringContainsString('Safe name', $csv);
        $this->assertStringNotContainsString('DO-NOT-EXPOSE', $csv);
        $this->assertStringNotContainsString('TOKEN-SECRET', $csv);
    }
}
