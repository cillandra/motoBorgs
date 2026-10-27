<?php

namespace App\Services\System;

use Illuminate\Support\Str;
use RuntimeException;

class RecoveryFiles
{
    public function root(): string
    {
        $root = config('moto_recovery.root');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Private backup storage is unavailable.');
        }
        $actual = realpath($root);
        $public = realpath(public_path());
        if (! $actual || is_link($root) || str_starts_with(strtolower(str_replace('\\', '/', $actual)).'/', strtolower(str_replace('\\', '/', $public)).'/')) {
            throw new RuntimeException('Backup storage must be private and outside the public directory.');
        }

        return $actual;
    }

    public function identifier(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Invalid backup identifier.');
        }

        return $id;
    }

    public function path(string $id, string $suffix = '.zip'): string
    {
        return $this->root().'/'.$this->identifier($id).$suffix;
    }

    public function signature(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), config('app.key'));
    }

    public function signed(array $payload): array
    {
        return ['payload' => $payload, 'signature' => $this->signature($payload)];
    }

    public function verified(array $document): array
    {
        if (! isset($document['payload'],$document['signature']) || ! is_array($document['payload']) || ! hash_equals($this->signature($document['payload']), (string) $document['signature'])) {
            throw new RuntimeException('Backup authenticity verification failed.');
        }

        return $document['payload'];
    }

    public function atomic(string $path, array $payload): void
    {
        $temporary = $path.'.'.Str::uuid().'.tmp';
        $stream = fopen($temporary, 'xb');
        if (! $stream) {
            throw new RuntimeException('Recovery journal could not be created.');
        }
        try {
            $bytes = json_encode($this->signed($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Recovery journal could not be flushed to disk.');
            }
        } finally {
            fclose($stream);
        }
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Recovery journal could not be saved.');
        }
    }

    public function read(string $path): array
    {
        return $this->verified(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    public function imageName(string $name): bool
    {
        return (bool) preg_match('~^products/[a-zA-Z0-9_-]{1,100}\.png$~D', $name);
    }

    public function imageFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        if (is_link($root)) {
            throw new RuntimeException('Linked image directories cannot be backed up.');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Linked images cannot be backed up.');
            }
            if ($entry->isDir()) {
                continue;
            }
            $relative = 'products/'.str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($root, '/\\')) + 1));
            if (! $this->imageName($relative)) {
                throw new RuntimeException('Unexpected image path in private product storage.');
            }
            $files[$relative] = $entry->getPathname();
        }
        ksort($files);

        return $files;
    }
}
