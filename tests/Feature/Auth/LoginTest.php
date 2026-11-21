<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Constants\ApiErrorCode;
use App\Models\User;
use App\Services\Users\UserStatusService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const string VALID_PASSWORD = 'valid-login-password';

    private const string WRONG_PASSWORD = 'incorrect-login-password';

    private const string DEFAULT_TOKEN_NAME = 'Test client';

    private const int TOKEN_TTL_MINUTES = 45;

    private const int LOGIN_IP_LIMIT = 3;

    private const int LOGIN_EMAIL_LIMIT = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'auth.timebox_duration' => 0,
            'authentication.tokens.default_name' => self::DEFAULT_TOKEN_NAME,
            'sanctum.expiration' => self::TOKEN_TTL_MINUTES,
            'rate_limits.api.per_ip' => 10,
            'rate_limits.login.per_ip' => self::LOGIN_IP_LIMIT,
            'rate_limits.login.per_email' => self::LOGIN_EMAIL_LIMIT,
        ]);
    }

    public function test_login_issues_an_expiring_token_without_a_configured_dummy_hash(): void
    {
        Config::set('authentication.login.dummy_password_hash', '');

        $this->freezeSecond();

        $user = $this->createLoginUser();
        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::VALID_PASSWORD,
        ]);

        $response
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertExactJsonStructure([
                'success',
                'message',
                'data' => [
                    'access_token',
                    'expires_at',
                    'user' => ['id', 'name', 'email'],
                ],
                'errors',
                'meta',
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.expires_at', $expiresAt->toImmutable()->utc()->toIso8601String())
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.name', $user->name)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null);

        $token = $response->json('data.access_token');

        self::assertIsString($token);
        self::assertNotSame('', $token);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'name' => self::DEFAULT_TOKEN_NAME,
            'token' => hash('sha256', Str::after($token, '|')),
            'expires_at' => $expiresAt->toDateTimeString(),
        ]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_unknown_email_and_wrong_password_return_the_same_error(): void
    {
        $user = $this->createLoginUser();

        $wrongPassword = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::WRONG_PASSWORD,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_INVALID_CREDENTIALS);

        $unknownEmail = $this->postJson('/api/login', [
            'email' => 'unknown@example.test',
            'password' => self::WRONG_PASSWORD,
        ])->assertUnauthorized();

        self::assertSame($wrongPassword->json(), $unknownEmail->json());

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_user_cannot_log_in_with_the_correct_password(): void
    {
        $user = $this->createLoginUser();
        $user->is_active = false;
        $user->saveOrFail();

        $inactive = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::VALID_PASSWORD,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_INVALID_CREDENTIALS);

        $unknown = $this->postJson('/api/login', [
            'email' => 'unknown@example.test',
            'password' => self::VALID_PASSWORD,
        ])->assertUnauthorized();

        self::assertSame($unknown->json(), $inactive->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_does_not_issue_a_token_when_user_is_disabled_after_password_verification(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create()->assignRole('admin');
        $user = $this->createLoginUser();
        $user->createToken('Existing device');

        $realHasher = app(Hasher::class);
        $statusService = app(UserStatusService::class);

        $this->mock(Hasher::class, static function (MockInterface $mock) use ($realHasher, $statusService, $actor, $user): void {
            $mock->shouldReceive('check')
                ->once()
                ->with(self::VALID_PASSWORD, $user->getAuthPassword())
                ->andReturnUsing(
                    static function (string $password, string $hash) use ($realHasher, $statusService, $actor, $user): bool {
                        self::assertTrue($realHasher->check($password, $hash));
                        $statusService->disable($actor, $user->id);

                        return true;
                    },
                );
        });

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::VALID_PASSWORD,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_INVALID_CREDENTIALS);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_payload_returns_field_errors_in_the_common_envelope(): void
    {
        $this->postJson('/api/login', [
            'email' => 'invalid-email',
            'device_name' => ['invalid'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertJsonValidationErrors(
                ['email', 'password', 'device_name'],
                'errors.fields',
            );

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_in_the_url_query_is_rejected(): void
    {
        $user = $this->createLoginUser();
        $queryPassword = rawurlencode(self::VALID_PASSWORD);

        $this->postJson('/api/login?password='.$queryPassword, [
            'email' => $user->email,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertOnlyJsonValidationErrors(['password'], 'errors.fields');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_limits_one_ip_across_different_emails(): void
    {
        $user = $this->createLoginUser();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);

        for ($attempt = 1; $attempt <= self::LOGIN_IP_LIMIT; $attempt++) {
            $this->postJson('/api/login', [
                'email' => "attempt-{$attempt}@example.test",
                'password' => self::WRONG_PASSWORD,
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::VALID_PASSWORD,
        ])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('errors.code', ApiErrorCode::RATE_LIMIT_EXCEEDED);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_limits_the_same_normalized_email_across_different_ips(): void
    {
        $user = $this->createLoginUser();

        for ($attempt = 1; $attempt <= self::LOGIN_EMAIL_LIMIT; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$attempt}"])
                ->postJson('/api/login', [
                    'email' => $user->email,
                    'password' => self::WRONG_PASSWORD,
                ])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100'])
            ->postJson('/api/login', [
                'email' => '  '.strtoupper($user->email).'  ',
                'password' => self::VALID_PASSWORD,
            ])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('errors.code', ApiErrorCode::RATE_LIMIT_EXCEEDED);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_mysql_rejects_an_email_that_only_matches_the_database_collation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('This regression requires MySQL.');
        }

        $user = $this->createLoginUser();
        $collationOnlyEmail = "r\u{00E9}viewer@example.test";

        self::assertTrue(
            User::query()
                ->whereKey($user->id)
                ->where('email', $collationOnlyEmail)
                ->exists(),
            'The MySQL fixture must match under the users.email collation.',
        );

        $this->postJson('/api/login', [
            'email' => $collationOnlyEmail,
            'password' => self::VALID_PASSWORD,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_INVALID_CREDENTIALS);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/login', [
            'email' => '  '.Str::upper($user->email).'  ',
            'password' => self::VALID_PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    private function createLoginUser(): User
    {
        return User::factory()->create([
            'email' => 'reviewer@example.test',
            'password' => self::VALID_PASSWORD,
        ]);
    }
}
