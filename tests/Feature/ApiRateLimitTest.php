<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ApiErrorCode;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ApiRateLimitTest extends TestCase
{
    public function test_health_and_unauthenticated_requests_share_the_same_ip_quota(): void
    {
        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 2,
        ]);

        $this->freezeSecond();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');

        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);

        $this->travel(10)->seconds();

        $this->getJson('/api/health')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '50')
            ->assertJsonPath('errors.code', ApiErrorCode::RATE_LIMIT_EXCEEDED);

        $this->getJson('/api/me')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '50')
            ->assertJsonPath('errors.code', ApiErrorCode::RATE_LIMIT_EXCEEDED);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');
    }
}
