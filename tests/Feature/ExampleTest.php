<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class ExampleTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => Lang::string('api.health.ok'),
                'data' => [
                    'status' => 'ok',
                ],
                'errors' => null,
                'meta' => null,
            ]);
    }
}
