<?php

namespace App\Services\System;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseSnapshot
{
    public const TABLES = ['access_reset_tokens', 'approvals', 'audit_events', 'backup_manifests', 'cache', 'cache_locks', 'cart_lines', 'carts', 'catalog_locks', 'categories', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'notifications', 'password_reset_tokens', 'payment_records', 'preferences', 'products', 'refund_records', 'return_lines', 'return_requests', 'sale_lines', 'sales', 'sessions', 'settings', 'stock_movements', 'units', 'users', 'variants'];

    public const EPHEMERAL = ['sessions', 'cache', 'cache_locks', 'password_reset_tokens', 'access_reset_tokens', 'jobs', 'job_batches', 'failed_jobs', 'backup_manifests'];

    public function connection(): Connection
    {
        $target = DB::connection()->getDatabaseName();
        if (! in_array($target, ['moto_demo', 'moto_test', 'moto_concurrency_test'], true)) {
            throw new RuntimeException('Recovery refuses an unapproved database.');
        }
        $configuration = $target === 'moto_demo' ? config('database.connections.maintenance') : config('database.connections.mysql');
        $configuration['database'] = $target;
        config(['database.connections.recovery' => $configuration]);
        DB::purge('recovery');

        return DB::connection('recovery');
    }

    public function candidate(): string
    {
        return DB::connection()->getDatabaseName() === 'moto_demo' ? 'moto_restore_candidate' : 'moto_benchmark';
    }

    public function describe(?Connection $connection = null, bool $snapshot = true): array
    {
        $connection ??= DB::connection();
        $rows = $connection->select('SHOW TABLE STATUS');
        $names = array_map(fn ($row) => $row->Name, $rows);
        sort($names);
        if ($names !== self::TABLES || collect($rows)->contains(fn ($row) => $row->Engine !== 'InnoDB')) {
            throw new RuntimeException('The backup schema or storage engine is incompatible.');
        }
        $definitions = [];
        $counts = [];
        foreach (self::TABLES as $table) {
            $ddl = (array) $connection->selectOne('SHOW CREATE TABLE `'.$table.'`');
            $definitions[$table] = preg_replace('/ AUTO_INCREMENT=\d+/', '', array_values($ddl)[1]);
            $counts[$table] = $snapshot && in_array($table, self::EPHEMERAL, true) ? 0 : $connection->table($table)->count();
        }

        return ['schema' => hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR)), 'counts' => $counts];
    }

    public function dump(string $destination): void
    {
        $connection = $this->connection();
        $arguments = ['--single-transaction', '--skip-lock-tables', '--skip-add-locks', '--skip-triggers', '--skip-comments', '--hex-blob', '--default-character-set=utf8mb4'];
        $stream = fopen($destination, 'wb');
        if (! $stream) {
            throw new RuntimeException('Cannot create backup database snapshot.');
        }
        $write = static function ($type, $bytes) use ($stream): void {
            if ($type === Process::OUT && fwrite($stream, $bytes) === false) {
                throw new RuntimeException('Backup storage write failed.');
            }
        };
        try {
            $this->execute(config('moto_recovery.dump_binary'), [...$arguments, '--no-data', $connection->getDatabaseName(), ...self::TABLES], $connection, null, $write);
            $this->execute(config('moto_recovery.dump_binary'), [...$arguments, '--no-create-info', $connection->getDatabaseName(), ...array_values(array_diff(self::TABLES, self::EPHEMERAL))], $connection, null, $write);
        } finally {
            fclose($stream);
        }
    }

    public function stage(string $sql, array $manifest): Connection
    {
        $maintenance = $this->connection();
        $candidate = $this->candidate();
        if (! in_array($candidate, ['moto_restore_candidate', 'moto_benchmark'], true) || $candidate === $maintenance->getDatabaseName()) {
            throw new RuntimeException('Unsafe recovery staging database.');
        }
        $configuration = $maintenance->getConfig();
        $configuration['database'] = $candidate;
        config(['database.connections.recovery_stage' => $configuration]);
        DB::purge('recovery_stage');
        $stage = DB::connection('recovery_stage');
        $stage->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($stage->select('SHOW TABLES') as $row) {
                $table = array_values((array) $row)[0];
                if (! preg_match('/^[a-z_]+$/D', $table)) {
                    throw new RuntimeException('Unexpected staging table.');
                } $stage->statement('DROP TABLE `'.$table.'`');
            }
        } finally {
            $stage->statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $stream = fopen($sql, 'rb');
        try {
            $this->execute(config('moto_recovery.client_binary'), ['--binary-mode', '--default-character-set=utf8mb4', $candidate], $maintenance, $stream);
        } finally {
            fclose($stream);
        }
        $description = $this->describe($stage, false);
        if ($description['schema'] !== $manifest['schema']) {
            throw new RuntimeException('Restored candidate schema does not match the verified snapshot.');
        }
        if ($description['counts'] !== $manifest['counts']) {
            throw new RuntimeException('Restored candidate record counts do not match: '.json_encode(array_diff_assoc($description['counts'], $manifest['counts'])));
        }
        $bad = $stage->selectOne('SELECT COUNT(*) AS n FROM variants v LEFT JOIN (SELECT variant_id,SUM(delta) AS balance FROM stock_movements GROUP BY variant_id) m ON m.variant_id=v.id WHERE v.stock<>COALESCE(m.balance,0)');
        if ((int) $bad->n !== 0) {
            throw new RuntimeException('Restored stock does not reconcile with its ledger.');
        }

        return $stage;
    }

    private function execute(string $binary, array $arguments, Connection $connection, mixed $input = null, ?callable $callback = null): void
    {
        $options = tempnam(app(OperationGuard::class)->directory(), 'db-');
        $quote = static fn ($value) => '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], (string) $value).'"';
        $configuration = $connection->getConfig();
        $content = "[client]\nhost=".$quote($configuration['host'])."\nport=".(int) $configuration['port']."\nuser=".$quote($configuration['username'])."\npassword=".$quote($configuration['password'])."\n";
        try {
            if (file_put_contents($options, $content, LOCK_EX) === false) {
                throw new RuntimeException('Cannot prepare the private database client.');
            }
            @chmod($options, 0600);
            $process = new Process([$binary, '--defaults-extra-file='.$options, ...$arguments], base_path(), null, $input, 180);
            $process->disableOutput();
            if ($callback) {
                $process->run($callback);
            } else {
                $process->run();
            }
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Database snapshot client failed. Check the private local server and available disk space.');
            }
        } finally {
            @unlink($options);
        }
    }
}
