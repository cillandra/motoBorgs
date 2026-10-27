<?php

namespace App\Console\Commands;

use App\Services\System\RestoreService;
use Illuminate\Console\Command;

class ResumeRecovery extends Command
{
    protected $signature = 'moto:recovery-resume';

    protected $description = 'Finish or roll back a journaled interrupted MOTO recovery before allowing sales';

    public function handle(RestoreService $restore): int
    {
        try {
            $this->info($restore->resume());

            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);
            $this->error('Recovery remains blocked. Preserve the journal and safety copy; inspect the private local log.');

            return self::FAILURE;
        }
    }
}
