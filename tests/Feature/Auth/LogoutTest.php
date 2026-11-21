<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Constants\ApiErrorCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class LogoutTest extends TestCase
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

    public function test_logout_revokes_only_the_token_used_for_the_request(): void
    {
        $user = User::factory()->create();
        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);

        $webToken = $user->createToken('Web browser', expiresAt: $expiresAt);
        $mobileToken = $user->createToken('Mobile app', expiresAt: $expiresAt);

        $webHeaders = [
            'Authorization' => 'Bearer '.$webToken->plainTextToken,
        ];

        $mobileHeaders = [
            'Authorization' => 'Bearer '.$mobileToken->plainTextToken,
        ];

        $this->postJson('/api/logout', headers: $webHeaders)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => Lang::string('api.auth.logged_out'),
                'data' => null,
                'errors' => null,
                'meta' => null,
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $webToken->accessToken->getKey(),
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $mobileToken->accessToken->getKey(),
        ]);

        // Each simulated request must resolve its own Bearer token.
        Auth::forgetGuards();

        $this->getJson('/api/me', $webHeaders)
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);

        Auth::forgetGuards();

        $this->getJson('/api/me', $mobileHeaders)
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }
}
