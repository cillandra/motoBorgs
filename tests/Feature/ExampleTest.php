<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_application_root_shows_public_customer_page(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertSee('Browse products');
    }
}
