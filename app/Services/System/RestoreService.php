<?php

namespace App\Services\System;

use App\Models\BackupManifest;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Auth\Reauthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RestoreService
{
    public function __construct(private OperationGuard $guard, private BackupService $backups, private DatabaseSnapshot $database, private RecoveryFiles $files) {}

    public function inspect(User $manager, BackupManifest $backup): array
    {
        abort_unless($manager->fresh()?->isManager(), 403);
        $manifest = $this->backups->verify($backup);

        return ['backup' => $backup, 'manifest' => $manifest, 'later_sales' => DB::table('sales')->where('id', '>', $manifest['last_sale_id'])->count(), 'current_sales' => DB::table('sales')->count(), 'snapshot_sales' => $manifest['counts']['sales'], 'snapshot_variants' => $manifest['counts']['variants'], 'images' => count($manifest['files']) - 1, 'compatible' => true];
    }

    public function restore(User $manager, BackupManifest $backup, string $password, string $confirmation): void
    {
        abort_unless($manager->fresh()?->isManager(), 403);
        abort_unless($confirmation === 'RESTORE', 422, 'Type RESTORE to confirm replacement of the current records.');
        app(Reauthentication::class)->confirm($manager, $password, 'Restore backup');
        $this->guard->exclusive(function () use ($manager, $backup): void {
            abort_unless($manager->fresh()?->isManager(), 403);
            abort_if(is_file($this->journalPath()), 503, 'Complete the pending recovery before starting another.');
            $id = (string) Str::uuid();
            $work = $this->files->path($id, '.work');
            try {
                $manifest = $this->backups->unpack($backup, $work);
                $stage = $this->database->stage($work.'/database.sql', $manifest);
                foreach ($stage->table('products')->whereNotNull('image_path')->pluck('image_path') as $image) {
                    if (! $this->files->imageName($image) || ! isset($manifest['files']['images/'.$image])) {
                        throw new RuntimeException('The candidate references an unverified product image.');
                    }
                }
                $safety = $this->backups->safety($manager);
                $journal = ['operation' => $id, 'backup' => $backup->identifier, 'safety' => $safety->identifier, 'actor_id' => $manager->id, 'actor_name' => $manager->name, 'started_at' => now()->toIso8601String(), 'phase' => 'prepared', 'images_existed' => is_dir($this->images()), 'epoch' => (string) Str::uuid()];
                $this->files->atomic($this->journalPath(), $journal);
                $connection = $this->database->connection();
                try {
                    $connection->statement('SET FOREIGN_KEY_CHECKS=0');
                    $connection->transaction(function () use ($connection, $stage, $id, $journal): void {
                        foreach (DatabaseSnapshot::TABLES as $table) {
                            $connection->table($table)->delete();
                        }
                        foreach (DatabaseSnapshot::TABLES as $table) {
                            if (in_array($table, DatabaseSnapshot::EPHEMERAL, true)) {
                                continue;
                            }
                            $connection->statement('INSERT INTO `'.$table.'` SELECT * FROM `'.$stage->getDatabaseName().'`.`'.$table.'`');
                        }
                        $connection->table('settings')->updateOrInsert(['section' => 'recovery_marker'], ['value' => json_encode(['operation' => $id]), 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
                        $connection->table('users')->update(['remember_token' => null]);
                        $connection->table('approvals')->whereNull('consumed_at')->update(['invalidated_at' => now()]);
                        $this->swapImages($journal);
                    });
                } catch (Throwable $error) {
                    $marker = $connection->table('settings')->where('section', 'recovery_marker')->value('value');
                    if ((json_decode($marker ?? '{}', true)['operation'] ?? null) === $id) {
                        $this->completeJournal($journal);

                        return;
                    }
                    $this->restoreOriginalImages($journal);
                    @unlink($this->journalPath());
                    throw $error;
                } finally {
                    $connection->statement('SET FOREIGN_KEY_CHECKS=1');
                }
                $this->completeJournal($journal);
            } finally {
                if (! is_file($this->journalPath())) {
                    $this->removeWork($id, '.work');
                }
            }
        });
    }

    public function resume(): string
    {
        return $this->guard->exclusive(function (): string {
            if (! is_file($this->journalPath())) {
                return 'No pending recovery.';
            }
            $journal = $this->files->read($this->journalPath());
            $id = $this->files->identifier($journal['operation']);
            $marker = DB::table('settings')->where('section', 'recovery_marker')->value('value');
            if ((json_decode($marker ?? '{}', true)['operation'] ?? null) === $id) {
                if (! is_dir($this->images())) {
                    throw new RuntimeException('Committed recovery images are unavailable. Keep maintenance enabled and recover the retained safety copy with local administration.');
                }
                $this->completeJournal($journal);

                return 'Committed recovery completed. Sign in again.';
            }
            $this->restoreOriginalImages($journal);
            $this->history($journal, 'rolled_back');
            @unlink($this->journalPath());
            $this->removeWork($id, '.work');

            return 'Interrupted transaction rolled back; original images retained.';
        });
    }

    protected function completeJournal(array $journal): void
    {
        $id = $this->files->identifier($journal['operation']);
        $backup = new BackupManifest(['identifier' => $journal['backup']]);
        $manifest = $this->backups->verify($backup);
        $actual = $this->files->imageFiles($this->images());
        $expected = collect($manifest['files'])->filter(fn ($entry, $name) => str_starts_with($name, 'images/'))->keys()->map(fn ($name) => substr($name, 7))->sort()->values()->all();
        if (array_keys($actual) !== $expected) {
            throw new RuntimeException('Recovery image inventory does not match the verified snapshot. Preserve the journal and safety copy.');
        }
        foreach ($actual as $name => $path) {
            if (! hash_equals($manifest['files']['images/'.$name]['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Recovery image checksum failed. Preserve the journal and safety copy.');
            }
        }
        $this->files->atomic($this->guard->directory().'/session-epoch.json', ['epoch' => $journal['epoch']]);
        DB::table('sessions')->delete();
        $this->backups->reconcileCatalog();
        if (! DB::table('audit_events')->where('type', 'Backup restored')->where('subject_id', $id)->exists()) {
            $actor = User::find($journal['actor_id']);
            app(AuditRecorder::class)->record($actor, 'Backup restored', 'system', 'recovery', $id, 'success', ['backup_id' => $journal['backup'], 'changes' => ['safety_backup' => $journal['safety'], 'confirmed_by' => $journal['actor_name']]]);
        }
        $this->history($journal, 'completed');
        @unlink($this->journalPath());
        $this->removeWork($id, '.work');
        // The previous images and safety archive remain available for local recovery.
    }

    private function swapImages(array $journal): void
    {
        $id = $journal['operation'];
        $current = $this->images();
        $old = $this->files->path($id, '.previous-images');
        $incoming = $this->files->path($id, '.work').'/images/products';
        File::ensureDirectoryExists($incoming, 0700);
        if (is_link($current) || is_link(dirname($current))) {
            throw new RuntimeException('Linked image storage is not supported for restore.');
        }
        if (is_dir($current) && ! rename($current, $old)) {
            throw new RuntimeException('Cannot retain the current product images.');
        }
        if (! rename($incoming, $current)) {
            throw new RuntimeException('Cannot activate the restored product images.');
        }
        $this->files->atomic($this->journalPath(), $journal + ['images_ready' => true]);
    }

    private function restoreOriginalImages(array $journal): void
    {
        $id = $this->files->identifier($journal['operation']);
        $old = $this->files->path($id, '.previous-images');
        if (is_dir($old)) {
            $failed = $this->files->path($id, '.failed-images');
            if (is_dir($this->images()) && ! rename($this->images(), $failed)) {
                throw new RuntimeException('Cannot retain the interrupted image set. Recovery remains blocked.');
            }
            if (! rename($old, $this->images())) {
                throw new RuntimeException('Cannot recover original images. Recovery remains blocked.');
            }
        } elseif (! $journal['images_existed'] && is_dir($this->images())) {
            if (! rename($this->images(), $this->files->path($id, '.failed-images'))) {
                throw new RuntimeException('Cannot recover the original empty image state.');
            }
        }
    }

    private function history(array $journal, string $outcome): void
    {
        $path = $this->guard->directory().'/recovery-history.jsonl';
        $line = json_encode($this->files->signed(['operation' => $journal['operation'], 'backup' => $journal['backup'], 'safety' => $journal['safety'], 'actor_name' => $journal['actor_name'], 'outcome' => $outcome, 'recorded_at' => now()->toIso8601String()]), JSON_THROW_ON_ERROR)."\n";
        $existing = is_file($path) ? file_get_contents($path) : '';
        foreach (explode("\n", trim($existing)) as $saved) {
            if ($saved && ($this->files->verified(json_decode($saved, true))['operation'] ?? null) === $journal['operation']) {
                return;
            }
        }
        $stream = fopen($path, 'ab');
        if (! $stream) {
            throw new RuntimeException('Cannot save durable recovery history.');
        }
        try {
            if (fwrite($stream, $line) !== strlen($line) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Cannot flush durable recovery history.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function images(): string
    {
        return Storage::disk('local')->path('products');
    }

    private function journalPath(): string
    {
        return $this->guard->directory().'/restore-journal.json';
    }

    private function removeWork(string $id, string $suffix): void
    {
        $path = $this->files->path($id, $suffix);
        $root = realpath($this->files->root());
        $resolved = realpath($path);
        if ($resolved && str_starts_with(strtolower(str_replace('\\', '/', $resolved)).'/', strtolower(str_replace('\\', '/', $root)).'/') && ! is_link($path)) {
            File::deleteDirectory($path);
        }
    }
}
