<?php

use App\Services\Customer\InquiryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'moto_concurrency_test']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'moto_concurrency_test') {
    throw new RuntimeException('Worker refuses a non-test database.');
}
[$script, $worker, $barrier, $key] = $argv;
$root = realpath(storage_path('framework/testing'));
if (! $root || ! str_starts_with(realpath(dirname($barrier)) ?: '', $root.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Invalid worker barrier directory.');
}
$waiting = false;
DB::listen(function ($event) use ($barrier, $worker, &$waiting): void {
    if ($waiting || ! str_starts_with($event->sql, 'select * from `customer_inquiries`')) {
        return;
    }
    $waiting = true;
    file_put_contents($barrier.'.ready.'.$worker, 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($barrier.'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }
        usleep(10000);
    }
});
try {
    $inquiry = app(InquiryService::class)->submit(['submission_key' => $key, 'name' => 'Race demonstration', 'email' => 'demo@example.test', 'topic' => 'general', 'message' => 'Test an overlapping inquiry submission.']);
    echo json_encode(['id' => $inquiry->id], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage());
    exit(1);
}
