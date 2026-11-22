<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Constants\ApiErrorCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

    public function test_valid_token_returns_the_user_with_empty_authorization_when_unassigned(): void
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
                        'roles' => [],
                        'permissions' => [],
                    ],
                ],
                'errors' => null,
                'meta' => null,
            ]);
    }

    public function test_valid_token_returns_roles_and_effective_permissions(): void
    {
        $user = User::factory()->create();
        $guardName = Guard::getDefaultName($user);

        $role = Role::findOrCreate('Assigned Orders Reviewer', $guardName);
        $inheritedPermission = Permission::findOrCreate('orders.view.assigned', $guardName);
        $sharedPermission = Permission::findOrCreate('orders.status.update.assigned', $guardName);
        $directPermission = Permission::findOrCreate('orders.notes.create.assigned', $guardName);

        $role->givePermissionTo($inheritedPermission, $sharedPermission);
        $user->assignRole($role);
        $user->givePermissionTo($sharedPermission, $directPermission);

        $token = $user->createToken(
            'Test device',
            expiresAt: now()->addMinutes(self::TOKEN_TTL_MINUTES),
        );

        $this->getJson('/api/me', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.roles', [$role->name])
            ->assertJsonPath('data.user.permissions', [
                'orders.notes.create.assigned',
                'orders.status.update.assigned',
                'orders.view.assigned',
            ]);
    }

    public function test_existing_bearer_token_is_rejected_after_user_is_disabled(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken(
            'Test device',
            expiresAt: now()->addMinutes(self::TOKEN_TTL_MINUTES),
        );
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

        $this->getJson('/api/me', $headers)
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $user->is_active = false;
        $user->saveOrFail();

        Auth::forgetGuards();

        $this->getJson('/api/me', $headers)
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->getKey(),
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
