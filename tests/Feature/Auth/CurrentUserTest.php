<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Constants\ApiErrorCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class CurrentUserTest extends TestCase
{
    use RefreshDatabase;

    private const int TOKEN_TTL_MINUTES = 60;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 10,
            'sanctum.expiration' => self::TOKEN_TTL_MINUTES,
        ]);
    }

    public function test_valid_token_returns_only_the_public_user_fields(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken(
            'Test device',
            expiresAt: now()->addMinutes(self::TOKEN_TTL_MINUTES),
        );

        $this->getJson('/api/me', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => Lang::string('api.auth.current_user_retrieved'),
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
                'errors' => null,
                'meta' => null,
            ]);
    }

    public function test_guest_gets_a_json_401_without_an_accept_header(): void
    {
        $this->get('/api/me')
            ->assertUnauthorized()
            ->assertHeaderContains('Content-Type', 'application/json')
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
    }

    public function test_web_guard_authentication_does_not_grant_api_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
    }

    public function test_an_existing_token_id_with_the_wrong_secret_is_rejected(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken(
            'Test device',
            expiresAt: now()->addMinutes(self::TOKEN_TTL_MINUTES),
        );

        $invalidToken = $token->accessToken->getKey().'|incorrect-secret';

        $this->getJson('/api/me', [
            'Authorization' => 'Bearer '.$invalidToken,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
    }

    public function test_a_token_with_a_past_expiry_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Expired device', expiresAt: now()->subMinute());

        $this->getJson('/api/me', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
    }

    public function test_the_global_lifetime_applies_even_when_stored_expiry_is_later(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken(
            'Long-lived device',
            expiresAt: now()->addMinutes(self::TOKEN_TTL_MINUTES * 2),
        );

        $this->travel(self::TOKEN_TTL_MINUTES + 1)->minutes();

        $this->getJson('/api/me', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
    }
}
