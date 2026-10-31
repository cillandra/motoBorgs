<?php

namespace App\Console\Commands;

use App\Services\System\RecordIntegrity;
use Illuminate\Console\Command;

class AuditRecords extends Command
{
    protected $signature = 'moto:audit-data {--json : Print aggregate checks as JSON}';

    protected $description = 'Read-only reconciliation of MOTO sales, payments, carts, returns and stock';

    public function handle(RecordIntegrity $integrity): int
    {
        $result = $integrity->inspect();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $rows = [];
            foreach ($result['checks'] as $check => $violations) {
                $rows[] = [$check, $violations];
            }
            $this->table(['Integrity check', 'Violations'], $rows);
            $this->line('Read-only audit; no records were changed.');
            if ($result['ok']) {
                $this->info('All checked records reconcile.');
            } else {
                $this->error('Record inconsistencies found. Review the affected workflows before correcting data.');
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
