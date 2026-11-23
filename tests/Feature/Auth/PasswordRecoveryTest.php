<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const string NEW_PASSWORD = 'Replacement password for testing';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        Config::set('authentication.password_reset_frontend_url', 'https://oms.example.test');
    }

    public function test_link_requests_accept_active_disabled_and_unknown_addresses_identically(): void
    {
        Queue::fake();

        $active = User::factory()->create(['email' => 'active@example.test']);
        $disabled = User::factory()->create(['is_active' => false]);
        $unknown = 'unknown@example.test';

        $accepted = $this->postJson('/api/forgot-password', ['email' => ' ACTIVE@example.test '])
            ->assertAccepted()
            ->assertJson(['success' => true, 'data' => null])
            ->json();

        foreach ([$disabled->email, $unknown] as $email) {
            $this->postJson('/api/forgot-password', ['email' => $email])
                ->assertAccepted()
                ->assertExactJson($accepted);
        }

        Queue::assertPushed(SendPasswordResetLink::class, 3);
        Queue::assertPushed(SendPasswordResetLink::class, fn (SendPasswordResetLink $job): bool => $job->email === $active->email);
    }

    public function test_only_active_accounts_receive_throttled_email_with_a_configured_spa_link(): void
    {
        Notification::fake();

        $active = User::factory()->create(['email' => 'buyer+orders@example.test']);
        $disabled = User::factory()->create(['is_active' => false]);

        foreach ([$active->email, $active->email, $disabled->email, 'unknown@example.test'] as $email) {
            $this->withHeader('Host', 'untrusted.example.test')
                ->postJson('/api/forgot-password', ['email' => $email])
                ->assertAccepted();
        }

        Notification::assertSentToTimes($active, ResetPassword::class, 1);
        Notification::assertNothingSentTo($disabled);
        Notification::assertSentTo(
            $active,
            ResetPassword::class,
            function (ResetPassword $notification) use ($active): bool {
                $message = $notification->toMail($active);
                $this->assertInstanceOf(MailMessage::class, $message);
                $url = $message->actionUrl;
                $this->assertIsString($url);
                [$target, $fragment] = explode('#', $url, 2);
                parse_str($fragment, $payload);

                $this->assertSame('https://oms.example.test/reset-password', $target);
                $this->assertSame(['token' => $notification->token, 'email' => $active->email], $payload);
                $this->assertDatabaseMissing('password_reset_tokens', ['token' => $notification->token]);

                return Password::tokenExists($active, $notification->token);
            },
        );
    }

    public function test_password_reset_consumes_the_link_and_revokes_only_the_affected_accounts_tokens(): void
    {
        $active = User::factory()->create();
        $other = User::factory()->create();

        $active->createToken('First device');
        $active->createToken('Second device');
        $other->createToken('Another account');

        $token = Password::createToken($active);
        $payload = $this->resetPayload($active->email, $token);

        $this->postJson('/api/reset-password', $payload)
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $active->refresh()->getAuthPassword()));
        $this->assertSame(0, $active->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->assertFalse(Password::tokenExists($active, $token));

        $this->postJson('/api/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token', 'errors.fields');
    }

    public function test_expired_disabled_and_unknown_links_share_one_error(): void
    {
        $active = User::factory()->create();
        $disabled = User::factory()->create(['is_active' => false]);
        $expiredToken = Password::createToken($active);
        $this->travel(61)->minutes();
        $disabledToken = Password::createToken($disabled);

        $failure = $this->postJson('/api/reset-password', $this->resetPayload($active->email, $expiredToken))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token', 'errors.fields')
            ->json('errors');

        foreach ([$disabled->email, 'unknown@example.test'] as $email) {
            $this->postJson('/api/reset-password', $this->resetPayload($email, $disabledToken))
                ->assertUnprocessable()
                ->assertJsonPath('errors', $failure);
        }
    }

    public function test_invalid_password_confirmation_does_not_consume_the_link(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $payload = $this->resetPayload($user->email, $token);
        $payload['password_confirmation'] = 'Different password for testing';

        $this->postJson('/api/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password', 'errors.fields');

        $this->assertTrue(Password::tokenExists($user, $token));
    }

    public function test_rate_limits_normalize_addresses_and_keep_separate_link_and_reset_buckets(): void
    {
        Queue::fake();
        Config::set('rate_limits.password_link.per_email', 1);
        Config::set('rate_limits.password_reset.per_email', 1);

        $email = 'unknown@example.test';
        $this->postJson('/api/forgot-password', ['email' => 'UNKNOWN@example.test'])
            ->assertAccepted();
        $this->postJson('/api/forgot-password', ['email' => $email])
            ->assertTooManyRequests();

        $payload = $this->resetPayload($email, 'not-a-real-token');
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
        $this->postJson('/api/reset-password', $payload)->assertTooManyRequests();
    }

    public function test_changing_a_password_while_signed_in_invalidates_existing_recovery_links(): void
    {
        $current = 'Current password for recovery test';
        $user = User::factory()->create(['password' => Hash::make($current)]);
        $resetToken = Password::createToken($user);
        $session = $user->createToken('Browser')->plainTextToken;

        $this->withToken($session)->patchJson('/api/me/password', [
            'current_password' => $current,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertFalse(Password::tokenExists($user, $resetToken));
    }

    /** @return array{email: string, token: string, password: string, password_confirmation: string} */
    private function resetPayload(string $email, string $token): array
    {
        return [
            'email' => $email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];
    }
}
