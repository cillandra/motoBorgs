<?php

use App\Services\System\OperationGuard;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['moto_recovery.operations' => $argv[2], 'moto_recovery.lock_timeout' => 0.1]);
try {
    app(OperationGuard::class)->{$argv[1]}(fn () => null);
    echo json_encode(['status' => 200]);
} catch (HttpException $error) {
    echo json_encode(['status' => $error->getStatusCode()]);
}
