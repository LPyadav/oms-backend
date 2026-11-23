<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/me/password';

    private const string CURRENT_PASSWORD = 'Existing password for testing';

    private const string NEW_PASSWORD = 'Replacement password for testing';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
    }

    public function test_password_change_revokes_every_token_for_the_account(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::CURRENT_PASSWORD)]);
        $otherUser = User::factory()->create();
        $token = $user->createToken('Current browser')->plainTextToken;
        $otherDevice = $user->createToken('Other device')->plainTextToken;
        $unrelatedToken = $otherUser->createToken('Other account')->plainTextToken;

        $this->withToken($token)
            ->patchJson(self::ENDPOINT, $this->changeBody())
            ->assertOk()
            ->assertJsonPath('data', null);
        self::assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->getAuthPassword()));
        self::assertSame(0, $user->tokens()->count());

        Auth::forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($otherDevice)->getJson('/api/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($unrelatedToken)->getJson('/api/me')->assertOk();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::CURRENT_PASSWORD])
            ->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])
            ->assertOk();
    }

    public function test_password_validation_keeps_the_previous_password_and_tokens(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::CURRENT_PASSWORD)]);
        $token = $user->createToken('Current browser')->plainTextToken;

        $this->withToken($token)
            ->patchJson(self::ENDPOINT, $this->changeBody('Wrong current password'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password', 'errors.fields');

        $this->patchJson(self::ENDPOINT, [...$this->changeBody(), 'password_confirmation' => 'Different'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password', 'errors.fields');

        self::assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->refresh()->getAuthPassword()));
        $this->getJson('/api/me')->assertOk();
    }

    public function test_password_credentials_are_accepted_only_in_the_request_body(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::CURRENT_PASSWORD)]);

        $this->withToken($user->createToken('Current browser')->plainTextToken)
            ->patchJson(self::ENDPOINT.'?'.http_build_query($this->changeBody()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password', 'password'], 'errors.fields');

        self::assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->refresh()->getAuthPassword()));
    }

    public function test_guest_cannot_change_the_password(): void
    {
        $this->patchJson(self::ENDPOINT, $this->changeBody())->assertUnauthorized();
    }

    /**
     * @return array{current_password: string, password: string, password_confirmation: string}
     */
    private function changeBody(string $currentPassword = self::CURRENT_PASSWORD): array
    {
        return [
            'current_password' => $currentPassword,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];
    }
}
