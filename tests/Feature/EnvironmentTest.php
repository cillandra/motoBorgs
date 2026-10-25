<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    public function test_health_reports_only_app_and_database_status(): void
    {
        $this->getJson('/health')->assertOk()->assertExactJson([
            'app' => 'MOTO',
            'database' => 'ok',
        ]);
    }
}
