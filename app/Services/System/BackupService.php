<?php

namespace App\Services\System;

use App\Models\BackupManifest;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Settings\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupService
{
    public function __construct(private RecoveryFiles $files, private DatabaseSnapshot $database, private OperationGuard $guard) {}

    public function create(?User $manager): BackupManifest
    {
        return $this->build($manager, false);
    }

    public function safety(User $manager): BackupManifest
    {
        return $this->build($manager, true);
    }

    private function build(?User $manager, bool $safety): BackupManifest
    {
        if ($manager) {
            abort_unless($manager->fresh()?->isManager(), 403);
        } else {
            abort_unless(app()->runningInConsole() && app()->bound('moto.scheduled-backup') && app('moto.scheduled-backup') === true, 403);
        }

        return $this->guard->exclusive(function () use ($manager, $safety): BackupManifest {
            abort_if(is_file($this->guard->directory().'/restore-journal.json'), 503, 'Resolve the pending recovery before making another backup.');
            if ($manager) {
                abort_unless($manager->fresh()?->isManager(), 403);
            }
            $id = (string) Str::uuid();
            $backup = BackupManifest::create(['identifier' => $id, 'actor_id' => $manager?->id, 'status' => 'running']);
            $sql = $this->files->path($id, '.sql.tmp');
            $temporary = $this->files->path($id, '.zip.tmp');
            try {
                $description = $this->database->describe();
                $this->database->dump($sql);
                $entries = ['database.sql' => $sql];
                foreach ($this->files->imageFiles(Storage::disk('local')->path('products')) as $name => $path) {
                    $entries['images/'.$name] = $path;
                }
                $zip = new ZipArchive;
                if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                    throw new RuntimeException('Cannot create the private backup archive.');
                }
                $hashes = [];
                $total = 0;
                try {
                    foreach ($entries as $name => $path) {
                        $size = filesize($path);
                        $total += $size;
                        if ($total > config('moto_recovery.max_uncompressed_bytes') || count($entries) > config('moto_recovery.max_entries') - 1) {
                            throw new RuntimeException('Backup exceeds the supported archive limit.');
                        }
                        $hashes[$name] = ['sha256' => hash_file('sha256', $path), 'size' => $size];
                        if (! $zip->addFile($path, $name)) {
                            throw new RuntimeException('Cannot add a backup file.');
                        }
                    }
                    $payload = ['format' => 1, 'identifier' => $id, 'kind' => $safety ? 'safety' : 'manual', 'created_at' => now()->toIso8601String(), 'schema' => $description['schema'], 'counts' => $description['counts'], 'last_sale_id' => (int) DB::table('sales')->max('id'), 'files' => $hashes];
                    $zip->addFromString('manifest.json', json_encode($this->files->signed($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                } finally {
                    $zip->close();
                }
                if (filesize($temporary) > config('moto_recovery.max_archive_bytes')) {
                    throw new RuntimeException('Backup exceeds the supported archive limit.');
                }
                if (! rename($temporary, $this->path($backup))) {
                    throw new RuntimeException('Cannot finish the backup archive.');
                }
                $archive = fopen($this->path($backup), 'c+b');
                if (! $archive) {
                    throw new RuntimeException('Cannot flush the completed backup.');
                }
                try {
                    if (! fflush($archive) || ! fsync($archive)) {
                        throw new RuntimeException('Cannot flush the completed backup.');
                    }
                } finally {
                    fclose($archive);
                }
                $this->verify($backup);
                $catalog = $payload + ['archive_sha256' => hash_file('sha256', $this->path($backup))];
                $this->files->atomic($this->files->path($id, '.json'), $catalog);
                $backup->update(['status' => 'complete', 'manifest' => $catalog, 'completed_at' => now()]);
                app(AuditRecorder::class)->record($manager, 'Backup completed', 'system', 'backup', $id, 'success', ['backup_id' => $id, 'changes' => ['kind' => $payload['kind'], 'scheduler_identity' => $manager ? null : (getenv('USERNAME') ?: 'local scheduler')]]);
                app(NotificationService::class)->publish('system', 'backup', $id, ['event_id' => 'complete']);
                $this->retain();

                return $backup->refresh();
            } catch (Throwable $error) {
                @unlink($temporary);
                @unlink($this->path($backup));
                @unlink($this->files->path($id, '.json'));
                $backup->update(['status' => 'failed', 'failure' => 'Snapshot did not complete. No usable backup was published.']);
                app(AuditRecorder::class)->record($manager, 'Backup failed', 'system', 'backup', $id, 'failed', ['backup_id' => $id]);
                throw $error;
            } finally {
                @unlink($sql);
            }
        });
    }

    public function path(BackupManifest $backup): string
    {
        return $this->files->path($backup->identifier);
    }

    public function verify(BackupManifest $backup): array
    {
        $path = $this->path($backup);
        if (! is_file($path) || is_link($path) || filesize($path) > config('moto_recovery.max_archive_bytes')) {
            throw new RuntimeException('Backup file is missing or exceeds the archive limit.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Backup archive cannot be read.');
        }
        try {
            if ($zip->numFiles > config('moto_recovery.max_entries')) {
                throw new RuntimeException('Backup contains too many files.');
            }
            $entries = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = $entry['name'];
                $zip->getExternalAttributesIndex($index, $os, $attributes);
                $kind = ($attributes >> 16) & 0170000;
                if (isset($entries[$name]) || ($kind !== 0 && $kind !== 0100000) || ($name !== 'manifest.json' && $name !== 'database.sql' && ! ($this->files->imageName(substr($name, 7)) && str_starts_with($name, 'images/')))) {
                    throw new RuntimeException('Backup contains an unsafe path, link, duplicate or unexpected file.');
                }
                $total += $entry['size'];
                if ($total > config('moto_recovery.max_uncompressed_bytes') || ($name === 'manifest.json' && $entry['size'] > 4194304)) {
                    throw new RuntimeException('Backup expands beyond the supported size limit.');
                }
                $entries[$name] = $entry;
            }
            if (! isset($entries['manifest.json'],$entries['database.sql'])) {
                throw new RuntimeException('Backup is incomplete.');
            }
            $payload = $this->files->verified(json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR));
            if (($payload['format'] ?? null) !== 1 || ($payload['identifier'] ?? null) !== $backup->identifier || ($payload['schema'] ?? null) !== $this->database->describe()['schema'] || array_keys($payload['counts'] ?? []) !== DatabaseSnapshot::TABLES) {
                throw new RuntimeException('Backup format or schema is incompatible with this version of MOTO.');
            }
            unset($entries['manifest.json']);
            $expected = array_keys($payload['files']);
            $actual = array_keys($entries);
            sort($expected);
            sort($actual);
            if ($expected !== $actual) {
                throw new RuntimeException('Backup file inventory does not match its signed manifest.');
            }
            foreach ($payload['files'] as $name => $expectedFile) {
                if ($entries[$name]['size'] !== $expectedFile['size']) {
                    throw new RuntimeException('Backup file size verification failed.');
                }
                $stream = $zip->getStream($name);
                $hash = hash_init('sha256');
                if (! $stream) {
                    throw new RuntimeException('Backup file cannot be read.');
                }
                try {
                    hash_update_stream($hash, $stream);
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($expectedFile['sha256'], hash_final($hash))) {
                    throw new RuntimeException('Backup checksum verification failed.');
                }
            }
            $sidecar = $this->files->path($backup->identifier, '.json');
            if (is_file($sidecar) && ! hash_equals($this->files->read($sidecar)['archive_sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Backup archive checksum verification failed.');
            }

            return $payload;
        } finally {
            $zip->close();
        }
    }

    public function unpack(BackupManifest $backup, string $directory): array
    {
        $manifest = $this->verify($backup);
        File::ensureDirectoryExists($directory, 0700);
        $zip = new ZipArchive;
        $zip->open($this->path($backup));
        try {
            foreach ($manifest['files'] as $name => $metadata) {
                $destination = $directory.'/'.$name;
                File::ensureDirectoryExists(dirname($destination), 0700);
                $input = $zip->getStream($name);
                $output = fopen($destination, 'xb');
                if (! $input || ! $output) {
                    throw new RuntimeException('Cannot stage the verified backup.');
                }
                try {
                    if (stream_copy_to_stream($input, $output) !== $metadata['size']) {
                        throw new RuntimeException('Staging file copy failed.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
            }
        } finally {
            $zip->close();
        }

        return $manifest;
    }

    public function reconcileCatalog(): void
    {
        foreach (glob($this->files->root().'/*.json') as $sidecar) {
            $payload = $this->files->read($sidecar);
            $id = $this->files->identifier($payload['identifier']);
            if (! is_file($this->files->path($id))) {
                continue;
            }
            BackupManifest::updateOrCreate(['identifier' => $id], ['actor_id' => null, 'status' => 'complete', 'manifest' => $payload, 'completed_at' => $payload['created_at'], 'failure' => null]);
        }
    }

    private function retain(): void
    {
        $backups = BackupManifest::where('status', 'complete')->orderByDesc('id')->get()->filter(fn ($backup) => ($backup->manifest['kind'] ?? 'manual') !== 'safety')->values();
        foreach ($backups->slice(7) as $backup) {
            @unlink($this->path($backup));
            @unlink($this->files->path($backup->identifier, '.json'));
            $backup->update(['status' => 'expired']);
        }
    }
}
