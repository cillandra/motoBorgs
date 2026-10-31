<?php

use App\Models\User;
use App\Services\Catalog\ProductService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'moto_concurrency_test']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'moto_concurrency_test') {
    throw new RuntimeException('Catalog worker refuses a non-test database.');
}
[$script, $actorId, $unitId, $mode, $barrier] = $argv;
$root = realpath(storage_path('framework/testing'));
if (! $root || ! str_starts_with(realpath(dirname($barrier)) ?: '', $root.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Invalid barrier directory.');
}
$actor = User::findOrFail((int) $actorId);
file_put_contents($barrier.'.ready.'.$actorId, 'ready');
$deadline = microtime(true) + 15;
while (! is_file($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Catalog barrier timed out.');
    }
    usleep(10000);
}
DB::listen(function (QueryExecuted $query): void {
    if (str_contains(strtolower($query->sql), 'exists') && str_contains($query->sql, 'variants') && in_array('SHARED-CATALOG-CODE', $query->bindings, true)) {
        usleep(250000);
    }
});
try {
    app(ProductService::class)->save($actor, null, ['name' => 'Race sample '.$actorId, 'variants' => [['sku' => $mode === 'sku' ? 'SHARED-CATALOG-CODE' : 'UNIQUE-'.$actorId, 'barcode' => $mode === 'barcode' ? 'SHARED-CATALOG-CODE' : null, 'name' => 'Standard', 'unit_id' => (int) $unitId, 'price' => '100.00', 'threshold' => 3]]]);
    echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
} catch (ValidationException) {
    echo json_encode(['status' => 422], JSON_THROW_ON_ERROR);
}
