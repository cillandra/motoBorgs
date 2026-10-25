<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $database = $app['config']->get('database.connections.mysql.database');
        if (! in_array($database, ['moto_test', 'moto_concurrency_test'], true)) {
            throw new \RuntimeException('Tests refuse to run outside a dedicated MOTO test database.');
        }

        return $app;
    }
}
