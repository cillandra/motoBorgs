<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class EnvironmentScriptTest extends TestCase
{
    public function test_environment_check_runs_in_windows_powershell_from_another_directory(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows PowerShell compatibility check.');
        }
        $root = dirname(__DIR__, 2);
        $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $root.'/scripts/check-environment.ps1'], sys_get_temp_dir());
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('MOTO PHP extensions ready.', $process->getOutput());
    }

    public function test_php_check_still_rejects_missing_required_extensions(): void
    {
        $process = new Process([PHP_BINARY, '-n', dirname(__DIR__, 2).'/scripts/check-environment.php']);
        $process->setTimeout(15);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Missing extension: pdo_mysql', $process->getErrorOutput());
    }
}
