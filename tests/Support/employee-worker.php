<?php

use App\Models\User;
use App\Services\Identity\EmployeeService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'moto_concurrency_test']);
app('db')->purge('mysql');
if (config('database.connections.mysql.database') !== 'moto_concurrency_test') {
    throw new RuntimeException('Worker refuses a non-test database.');
}
[$script,$actorId,$targetId,$barrier] = $argv;
$root = realpath(storage_path('framework/testing'));
if (! $root || ! str_starts_with(realpath(dirname($barrier)) ?: '', $root.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Invalid barrier directory.');
}
$actor = User::findOrFail((int) $actorId);
$target = User::findOrFail((int) $targetId);
file_put_contents($barrier.'.ready.'.$actorId, 'ready');
$deadline = microtime(true) + 15;
while (! is_file($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    }usleep(10000);
}
try {
    app(EmployeeService::class)->save($actor, $target, ['name' => $target->name, 'username' => $target->username, 'role' => 'staff', 'version' => $target->version]);
    echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
} catch (HttpException $error) {
    echo json_encode(['status' => $error->getStatusCode()], JSON_THROW_ON_ERROR);
}
