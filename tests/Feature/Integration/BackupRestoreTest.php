<?php

namespace Tests\Feature\Integration;

use App\Models\AuditEvent;
use App\Models\BackupManifest;
use App\Models\CustomerInquiry;
use App\Models\Sale;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\CheckoutService;
use App\Services\Settings\SettingsService;
use App\Services\System\BackupService;
use App\Services\System\DatabaseSnapshot;
use App\Services\System\DiagnosticsService;
use App\Services\System\GuardedSessionHandler;
use App\Services\System\OperationGuard;
use App\Services\System\RecoveryFiles;
use App\Services\System\RestoreService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;
use ZipArchive;

class BackupRestoreTest extends TestCase
{
    use MotoFixtures;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('moto_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->workspace = storage_path('framework/testing/recovery-'.Str::uuid());
        config(['moto_recovery.root' => $this->workspace.'/backups', 'moto_recovery.operations' => $this->workspace.'/operations', 'moto_recovery.lock_timeout' => 0.15, 'filesystems.disks.local.root' => $this->workspace.'/images', 'database.connections.maintenance' => config('database.connections.mysql')]);
        File::ensureDirectoryExists($this->workspace.'/images');
        Storage::forgetDisk('local');
        app()->forgetInstance(OperationGuard::class);
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        RefreshDatabaseState::$migrated = false;
        if (isset($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }
        parent::tearDown();
    }

    private function failure(callable $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException $error) {
            $this->assertNotSame('', $error->getMessage());

            return;
        }
        $this->fail('Unsafe recovery input was accepted.');
    }

    public function test_snapshot_contains_verified_database_and_images_with_private_metadata_and_manager_only_download(): void
    {
        $manager = $this->manager();
        $staff = $this->staff();
        $variant = $this->variant(4);
        Storage::disk('local')->put('products/'.str_repeat('a', 36).'.png', 'image-bytes');
        $variant->product->update(['image_path' => 'products/'.str_repeat('a', 36).'.png']);
        $backup = app(BackupService::class)->create($manager);
        $manifest = app(BackupService::class)->verify($backup);
        $this->assertSame('complete', $backup->status);
        $this->assertSame(1, $manifest['counts']['variants']);
        $this->assertArrayHasKey('images/products/'.str_repeat('a', 36).'.png', $manifest['files']);
        $metadata = json_encode($manifest);
        foreach (['DB_PASSWORD=', 'DB_USERNAME=', '127.0.0.1', 'DB_', 'htdocs', 'moto_test'] as $secret) {
            $this->assertStringNotContainsString($secret, $metadata);
        }
        $this->actingAs($staff)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp])->get('/backups/'.$backup->id.'/download')->assertForbidden();
        $this->actingAs($manager)->get('/backups/'.$backup->id.'/download')->assertOk()->assertHeader('content-type', 'application/zip');
    }

    public function test_corruption_unsafe_entries_schema_and_archive_bounds_are_rejected(): void
    {
        $manager = $this->manager();
        $this->variant(2);
        $service = app(BackupService::class);
        foreach (['traversal', 'symlink', 'schema', 'size', 'checksum'] as $case) {
            $backup = $service->create($manager);
            $path = $service->path($backup);
            $zip = new ZipArchive;
            $zip->open($path);
            if ($case === 'traversal') {
                $zip->addFromString('../outside.txt', 'bad');
            }
            if ($case === 'symlink') {
                $zip->addFromString('images/products/link.png', 'target');
                $zip->setExternalAttributesName('images/products/link.png', ZipArchive::OPSYS_UNIX, 0120777 << 16);
            }
            if ($case === 'schema') {
                $document = json_decode($zip->getFromName('manifest.json'), true);
                $document['payload']['schema'] = 'invalid';
                $document['signature'] = app(RecoveryFiles::class)->signature($document['payload']);
                $zip->addFromString('manifest.json', json_encode($document));
            }
            if ($case === 'checksum') {
                $zip->addFromString('database.sql', 'corrupt');
            }
            $zip->close();
            if ($case === 'size') {
                config(['moto_recovery.max_archive_bytes' => 1]);
            }
            $this->failure(fn () => $service->verify($backup));
            config(['moto_recovery.max_archive_bytes' => 268435456]);
        }
    }

    public function test_interrupted_backup_records_failure_without_a_downloadable_completed_file(): void
    {
        $manager = $this->manager();
        $this->mock(DatabaseSnapshot::class)->shouldReceive('describe')->andReturn(['schema' => 'test', 'counts' => []])->shouldReceive('dump')->andThrow(new RuntimeException('Interrupted fixture'));
        $this->failure(fn () => app(BackupService::class)->create($manager));
        $this->assertSame('failed', BackupManifest::latest('id')->first()->status);
        $this->assertCount(0, glob($this->workspace.'/backups/*.zip'));
    }

    public function test_retention_keeps_seven_completed_files_without_deleting_sales_or_audit(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(3);
        $cart = $this->cart($manager, [$variant->id => 1])->refresh();
        app(CheckoutService::class)->complete($manager, $cart, $cart->version, (string) Str::uuid(), ['method' => 'cash', 'received_centavos' => 10000]);
        for ($i = 0; $i < 8; $i++) {
            app(BackupService::class)->create($manager);
        }
        $this->assertCount(7, glob($this->workspace.'/backups/*.zip'));
        $this->assertSame(1, BackupManifest::where('status', 'expired')->count());
        $this->assertSame(1, Sale::count());
        $this->assertGreaterThanOrEqual(8, AuditEvent::where('type', 'Backup completed')->count());
    }

    public function test_restore_stages_verifies_then_reconciles_records_images_sessions_and_durable_history(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(3);
        Storage::disk('local')->put('products/original.png', 'original');
        $variant->product->update(['image_path' => 'products/original.png']);
        $inquiry = CustomerInquiry::factory()->create(['variant_id' => $variant->id]);
        $backup = app(BackupService::class)->create($manager);
        $inquiry->update(['message' => 'Changed after backup']);
        $cart = $this->cart($manager, [$variant->id => 1])->refresh();
        app(CheckoutService::class)->complete($manager, $cart, $cart->version, (string) Str::uuid(), ['method' => 'cash', 'received_centavos' => 10000]);
        Storage::disk('local')->put('products/original.png', 'changed');
        Storage::disk('local')->put('products/later.png', 'later');
        DB::table('sessions')->insert(['id' => 'stale', 'user_id' => $manager->id, 'payload' => base64_encode('stale'), 'last_activity' => time()]);
        $preview = app(RestoreService::class)->inspect($manager, $backup);
        $this->assertSame(1, $preview['later_sales']);
        app(RestoreService::class)->restore($manager, $backup, 'Unit test passphrase 2026!', 'RESTORE');
        $this->assertSame(0, Sale::count());
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(3, (int) $variant->movements()->sum('delta'));
        $this->assertSame('original', Storage::disk('local')->get('products/original.png'));
        $this->assertSame('Demonstration inquiry for a motorcycle part.', CustomerInquiry::findOrFail($inquiry->id)->message);
        $this->assertFalse(Storage::disk('local')->exists('products/later.png'));
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame(1, AuditEvent::where('type', 'Backup restored')->count());
        $this->assertFileExists($this->workspace.'/operations/recovery-history.jsonl');
        $this->assertFileDoesNotExist($this->workspace.'/operations/restore-journal.json');
        $this->assertGreaterThanOrEqual(2, count(glob($this->workspace.'/backups/*.zip')));
    }

    public function test_failure_in_staging_preserves_current_records_and_images(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(2);
        Storage::disk('local')->put('products/original.png', 'original');
        $backup = app(BackupService::class)->create($manager);
        Storage::disk('local')->put('products/original.png', 'current');
        $this->mock(DatabaseSnapshot::class)->makePartial()->shouldReceive('stage')->andThrow(new RuntimeException('Staging failed'));
        $this->failure(fn () => app(RestoreService::class)->restore($manager, $backup, 'Unit test passphrase 2026!', 'RESTORE'));
        $this->assertSame(2, $variant->fresh()->stock);
        $this->assertSame('current', Storage::disk('local')->get('products/original.png'));
    }

    public function test_nested_exclusive_safety_snapshot_retains_ownership_and_shared_upgrade_is_rejected(): void
    {
        $manager = $this->manager();
        $guard = app(OperationGuard::class);
        $guard->exclusive(function () use ($manager, $guard): void {
            $backup = app(BackupService::class)->create($manager);
            $this->assertSame('complete', $backup->status);
            $this->assertSame('exclusive', $guard->mode());
            $this->assertSame(42, $guard->write(fn () => 42));
        });
        $this->failure(fn () => $guard->write(fn () => $guard->exclusive(fn () => null)));
    }

    public function test_maintenance_blocks_domain_writes_and_manager_recovery_remains_available(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(2);
        $this->actingAs($manager)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->postJson('/maintenance', ['enabled' => true, 'manager_password' => 'Unit test passphrase 2026!', 'confirmation' => 'MAINTENANCE'])->assertOk();
        try {
            app(StockLedger::class)->receive($manager, $variant, 1, 'ref', 'reason', (string) Str::uuid());
            $this->fail('Maintenance allowed stock write.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->get('/settings?section=backup')->assertOk();
        $this->postJson('/maintenance', ['enabled' => false, 'manager_password' => 'Unit test passphrase 2026!', 'confirmation' => 'MAINTENANCE'])->assertOk();
        app(StockLedger::class)->receive($manager, $variant, 1, 'ref', 'reason', (string) Str::uuid());
        $this->assertSame(3, $variant->fresh()->stock);
    }

    public function test_interruption_after_commit_keeps_journal_blocks_writes_and_resumes_once(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(2);
        $backup = app(BackupService::class)->create($manager);
        $restore = new class(app(OperationGuard::class), app(BackupService::class), app(DatabaseSnapshot::class), app(RecoveryFiles::class)) extends RestoreService
        {
            protected function completeJournal(array $journal): void
            {
                throw new RuntimeException('Simulated process interruption after commit');
            }
        };
        $this->failure(fn () => $restore->restore($manager, $backup, 'Unit test passphrase 2026!', 'RESTORE'));
        $this->assertFileExists($this->workspace.'/operations/restore-journal.json');
        try {
            app(OperationGuard::class)->write(fn () => null);
            $this->fail('Pending recovery allowed a new write.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertStringContainsString('completed', app(RestoreService::class)->resume());
        $this->assertStringContainsString('No pending', app(RestoreService::class)->resume());
        $this->assertSame(1, AuditEvent::where('type', 'Backup restored')->count());
        $this->assertSame(2, $variant->fresh()->stock);
    }

    public function test_old_inflight_session_cannot_be_resaved_or_write_after_restore_epoch_changes(): void
    {
        $handler = new GuardedSessionHandler(DB::connection(), 'sessions', 120, app());
        $handler->read('old-session');
        app(RecoveryFiles::class)->atomic(app(OperationGuard::class)->directory().'/session-epoch.json', ['epoch' => 'new-recovery']);
        $this->assertTrue($handler->write('old-session', 'stale authenticated payload'));
        $this->assertSame(0, DB::table('sessions')->count());
        try {
            app(OperationGuard::class)->write(fn () => null);
            $this->fail('Stale request allowed a write.');
        } catch (HttpException $error) {
            $this->assertSame(401, $error->getStatusCode());
        }
    }

    public function test_scheduler_stays_off_until_installation_heartbeat_and_explicit_enable_are_verified(): void
    {
        $service = app(DiagnosticsService::class);
        $this->assertFalse($service->scheduler()['verified']);
        $manager = $this->manager();
        try {
            app(SettingsService::class)->save($manager, 'backup', ['enabled' => true, 'schedule' => '20:00', 'retain' => 7], 0);
            $this->fail('Unverified scheduler enabled.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $files = app(RecoveryFiles::class);
        $root = app(OperationGuard::class)->directory();
        $files->atomic($root.'/scheduler-installed.json', ['verified_at' => time()]);
        $files->atomic($root.'/scheduler-heartbeat.json', ['timestamp' => time()]);
        $this->assertTrue($service->scheduler()['verified']);
        $this->assertFalse($service->scheduler()['enabled']);
        app(SettingsService::class)->save($manager, 'backup', ['enabled' => true, 'schedule' => '20:00', 'retain' => 7], 0);
        $this->assertTrue($service->scheduler()['enabled']);
        $files->atomic($root.'/scheduler-heartbeat.json', ['timestamp' => time() - 181]);
        $this->assertFalse($service->scheduler()['verified']);
        $this->assertFalse($service->scheduler()['enabled']);
    }

    public function test_resume_rejects_changed_active_images_and_retains_the_recovery_journal(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(2);
        Storage::disk('local')->put('products/original.png', 'original');
        $variant->product->update(['image_path' => 'products/original.png']);
        $backup = app(BackupService::class)->create($manager);
        $restore = new class(app(OperationGuard::class), app(BackupService::class), app(DatabaseSnapshot::class), app(RecoveryFiles::class)) extends RestoreService
        {
            protected function completeJournal(array $journal): void
            {
                throw new RuntimeException('Simulated process interruption');
            }
        };
        $this->failure(fn () => $restore->restore($manager, $backup, 'Unit test passphrase 2026!', 'RESTORE'));
        Storage::disk('local')->put('products/original.png', 'corrupt after interruption');
        $this->failure(fn () => app(RestoreService::class)->resume());
        $this->assertFileExists($this->workspace.'/operations/restore-journal.json');
        Storage::disk('local')->put('products/original.png', 'original');
        $this->assertStringContainsString('completed', app(RestoreService::class)->resume());
    }

    public function test_staged_restore_rejects_sale_totals_that_do_not_reconcile_with_lines_and_payment(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(3);
        $cart = $this->cart($manager, [$variant->id => 1])->refresh();
        $sale = app(CheckoutService::class)->complete($manager, $cart, $cart->version, (string) Str::uuid(), ['method' => 'cash', 'received_centavos' => 10000])->sale;
        DB::table('sales')->where('id', $sale->id)->update(['total_centavos' => 9999]);
        $backup = app(BackupService::class)->create($manager);
        $this->failure(fn () => app(RestoreService::class)->restore($manager, $backup, 'Unit test passphrase 2026!', 'RESTORE'));
        $this->assertFileDoesNotExist($this->workspace.'/operations/restore-journal.json');
        $this->assertSame(9999, $sale->fresh()->total_centavos);
    }
}
