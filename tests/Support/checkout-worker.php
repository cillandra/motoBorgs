<?php

use App\Models\Cart;
use App\Models\User;
use App\Services\Pos\CheckoutService;
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
[$script, $cashierId, $cartId, $barrier, $key] = $argv;
$root = realpath(storage_path('framework/testing'));
if (! $root || ! str_starts_with(realpath(dirname($barrier)) ?: '', $root.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Invalid worker barrier directory.');
}
file_put_contents($barrier.'.ready.'.$cashierId, 'ready');
$deadline = microtime(true) + 15;
while (! is_file($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker start barrier timed out.');
    }
    usleep(10000);
}
try {
    $result = app(CheckoutService::class)->complete(User::findOrFail((int) $cashierId), Cart::findOrFail((int) $cartId), 1, $key, ['method' => 'cash', 'received_centavos' => 10000]);
    echo json_encode(['status' => 201, 'sale_id' => $result->sale->id], JSON_THROW_ON_ERROR);
} catch (HttpException $error) {
    echo json_encode(['status' => $error->getStatusCode(), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
