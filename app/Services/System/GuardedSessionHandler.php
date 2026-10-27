<?php

namespace App\Services\System;

use Illuminate\Session\DatabaseSessionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GuardedSessionHandler extends DatabaseSessionHandler
{
    private ?string $readEpoch = null;

    public function read($sessionId): string|false
    {
        return app(OperationGuard::class)->administrative(function () use ($sessionId): string|false {
            $this->readEpoch = app(OperationGuard::class)->epoch();
            app()->instance('moto.request-epoch', $this->readEpoch);

            return parent::read($sessionId);
        });
    }

    public function write($sessionId, $data): bool
    {
        try {
            return app(OperationGuard::class)->administrative(function () use ($sessionId, $data): bool {
                if ($this->readEpoch !== null && $this->readEpoch !== app(OperationGuard::class)->epoch()) {
                    return true;
                }

                return parent::write($sessionId, $data);
            });
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [401, 503], true)) {
                return true;
            } throw $error;
        }
    }

    public function destroy($sessionId): bool
    {
        try {
            return app(OperationGuard::class)->administrative(fn () => parent::destroy($sessionId));
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [401, 503], true)) {
                return true;
            } throw $error;
        }
    }

    public function gc($lifetime): int
    {
        return app(OperationGuard::class)->administrative(fn () => parent::gc($lifetime));
    }
}
