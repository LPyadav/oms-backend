<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Constants\ApiErrorCode;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/admin/users';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 10,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_list_users_with_pagination_metadata(): void
    {
        $this->authenticateAs('admin');
        $staff = User::factory()->create()->assignRole('staff');
        User::factory()->create();

        $this->getJson(self::ENDPOINT.'?page=2&per_page=1')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => Lang::string('api.users.list_retrieved'),
                'data' => [
                    'users' => [[
                        'id' => $staff->id,
                        'name' => $staff->name,
                        'email' => $staff->email,
                        'is_active' => true,
                        'roles' => ['staff'],
                    ]],
                ],
                'errors' => null,
                'meta' => [
                    'current_page' => 2,
                    'per_page' => 1,
                    'total' => 3,
                    'last_page' => 3,
                ],
            ]);
    }

    public function test_admin_can_create_a_user_with_a_hashed_password(): void
    {
        $this->authenticateAs('admin');
        $password = 'Create user fixture password';

        $response = $this->postJson(self::ENDPOINT, [
            'name' => 'New user',
            'email' => ' NEW@EXAMPLE.TEST ',
            'password' => $password,
            'is_active' => false,
            'roles' => ['admin'],
        ])->assertCreated();

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();

        $response->assertExactJson([
            'success' => true,
            'message' => Lang::string('api.users.created'),
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => 'New user',
                    'email' => 'new@example.test',
                    'is_active' => true,
                    'roles' => [],
                ],
            ],
            'errors' => null,
            'meta' => null,
        ]);

        self::assertTrue($user->is_active);
        self::assertTrue($user->roles()->doesntExist());
        self::assertTrue(Hash::check($password, $user->password));
    }

    public function test_admin_can_partially_update_the_profile_without_changing_access(): void
    {
        $this->authenticateAs('admin');
        $target = User::factory()->create([
            'email' => 'original@example.test',
            'email_verified_at' => now(),
        ])->assignRole('staff');
        $originalPasswordHash = $target->password;
        $endpoint = self::ENDPOINT.'/'.$target->id;

        $this->patchJson($endpoint, [
            'name' => 'Updated name',
            'is_active' => false,
            'roles' => ['admin'],
            'password' => 'Ignored fixture password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Updated name')
            ->assertJsonPath('data.user.email', 'original@example.test');

        $this->patchJson($endpoint, ['email' => ' UPDATED@EXAMPLE.TEST '])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => Lang::string('api.users.updated'),
                'data' => [
                    'user' => [
                        'id' => $target->id,
                        'name' => 'Updated name',
                        'email' => 'updated@example.test',
                        'is_active' => true,
                        'roles' => ['staff'],
                    ],
                ],
                'errors' => null,
                'meta' => null,
            ]);

        $target->refresh();

        self::assertSame('updated@example.test', $target->email);
        self::assertNull($target->email_verified_at);
        self::assertSame($originalPasswordHash, $target->password);
        self::assertTrue($target->is_active);
        self::assertTrue($target->hasExactRoles('staff'));
    }

    public function test_admin_can_disable_a_user_and_revoke_their_tokens(): void
    {
        $this->authenticateAs('admin');
        $target = User::factory()->create()->assignRole('staff');
        $desktopToken = $target->createToken('Target desktop');
        $mobileToken = $target->createToken('Target mobile');

        $this->patchJson(self::ENDPOINT.'/'.$target->id.'/disable')
            ->assertOk()
            ->assertJsonPath('data.user.id', $target->id)
            ->assertJsonPath('data.user.is_active', false);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->assertModelMissing($desktopToken->accessToken);
        $this->assertModelMissing($mobileToken->accessToken);

        Auth::forgetGuards();

        $this->getJson('/api/me', [
            'Authorization' => 'Bearer '.$desktopToken->plainTextToken,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
    }

    public function test_last_active_admin_cannot_disable_their_account(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        User::factory()->create(['is_active' => false])->assignRole('admin');
        $token = $admin->createToken('Admin device');

        $this->withToken($token->plainTextToken)
            ->patchJson(self::ENDPOINT.'/'.$admin->id.'/disable')
            ->assertConflict()
            ->assertExactJson([
                'success' => false,
                'message' => Lang::string('api.users.last_active_admin'),
                'data' => null,
                'errors' => [
                    'code' => ApiErrorCode::LAST_ACTIVE_ADMIN,
                    'fields' => [],
                ],
                'meta' => null,
            ]);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true]);
        $this->assertModelExists($token->accessToken);

        Auth::forgetGuards();

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $admin->id);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_cannot_manage_users(string $role): void
    {
        $this->authenticateAs($role);

        $this->assertManagementDenied(Response::HTTP_FORBIDDEN, ApiErrorCode::ACCESS_FORBIDDEN);
    }

    public function test_guests_cannot_manage_users(): void
    {
        $this->assertManagementDenied(Response::HTTP_UNAUTHORIZED, ApiErrorCode::AUTH_UNAUTHENTICATED);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'manager' => ['manager'],
            'staff' => ['staff'],
        ];
    }

    private function authenticateAs(string $role): void
    {
        $user = User::factory()->create()->assignRole($role);
        $token = $user->createToken('Admin user management test');

        $this->withToken($token->plainTextToken);
    }

    private function assertManagementDenied(int $status, string $errorCode): void
    {
        $target = User::factory()->create(['name' => 'Existing user']);
        $targetToken = $target->createToken('Target device')->accessToken;
        $endpoint = self::ENDPOINT.'/'.$target->id;

        $this->getJson(self::ENDPOINT)
            ->assertStatus($status)
            ->assertJsonPath('errors.code', $errorCode);

        $this->postJson(self::ENDPOINT, [
            'name' => 'Blocked user',
            'email' => 'blocked@example.test',
            'password' => 'Blocked fixture password',
        ])
            ->assertStatus($status)
            ->assertJsonPath('errors.code', $errorCode);

        $this->patchJson($endpoint, ['name' => 'Blocked edit'])
            ->assertStatus($status)
            ->assertJsonPath('errors.code', $errorCode);

        $this->patchJson($endpoint.'/disable')
            ->assertStatus($status)
            ->assertJsonPath('errors.code', $errorCode);

        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.test']);
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Existing user',
            'is_active' => true,
        ]);
        $this->assertModelExists($targetToken);
    }
}
