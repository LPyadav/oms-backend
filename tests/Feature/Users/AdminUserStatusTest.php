<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Constants\ApiErrorCode;
use App\Models\AccessAuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AdminUserStatusTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/admin/users';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'auth.timebox_duration' => 0,
            'rate_limits.api.per_ip' => 20,
            'rate_limits.login.per_ip' => 10,
            'rate_limits.login.per_email' => 5,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_reactivate_a_user_without_restoring_their_revoked_tokens(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $adminHeaders = ['Authorization' => 'Bearer '.$admin->createToken('Admin fixture')->plainTextToken];
        $password = 'User reactivation fixture password';
        $target = User::factory()->create(['password' => Hash::make($password)])->assignRole('staff');
        $oldToken = $target->createToken('Before disable');
        $endpoint = self::ENDPOINT.'/'.$target->id;
        $credentials = ['email' => $target->email, 'password' => $password];

        $this->patchJson($endpoint.'/disable', [], $adminHeaders)
            ->assertOk()
            ->assertJsonPath('data.user.is_active', false);
        $this->assertModelMissing($oldToken->accessToken);

        $tokenFromDisabledAccount = $target->createToken('Residual token before repeated disable');
        $this->patchJson($endpoint.'/disable', [], $adminHeaders)->assertOk();
        $this->assertModelMissing($tokenFromDisabledAccount->accessToken);
        $this->assertDatabaseCount('access_audit_logs', 1);
        $this->assertStatusAudit($admin, $target, 'user.disabled', false);

        $this->postJson('/api/login', $credentials)
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_INVALID_CREDENTIALS);

        $residualToken = $target->createToken('Residual token from a legacy disabled account');
        $this->patchJson($endpoint.'/enable', [], $adminHeaders)
            ->assertOk()
            ->assertJsonPath('data.user.id', $target->id)
            ->assertJsonPath('data.user.is_active', true)
            ->assertJsonPath('data.user.roles', ['staff']);
        $this->assertModelMissing($residualToken->accessToken);
        $this->assertStatusAudit($admin, $target, 'user.enabled', true);

        foreach ([$oldToken, $residualToken] as $revokedToken) {
            Auth::forgetGuards();

            $this->getJson('/api/me', ['Authorization' => 'Bearer '.$revokedToken->plainTextToken])
                ->assertUnauthorized()
                ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
        }

        $freshToken = $this->postJson('/api/login', $credentials)
            ->assertOk()
            ->assertJsonPath('data.user.id', $target->id)
            ->json('data.access_token');
        self::assertIsString($freshToken);

        $this->patchJson($endpoint.'/enable', [], $adminHeaders)
            ->assertOk()
            ->assertJsonPath('data.user.is_active', true);
        $this->assertDatabaseCount('access_audit_logs', 2);

        Auth::forgetGuards();

        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$freshToken])
            ->assertOk()
            ->assertJsonPath('data.user.id', $target->id)
            ->assertJsonPath('data.user.roles', ['staff']);
    }

    public function test_guests_and_non_admins_cannot_enable_users(): void
    {
        $target = User::factory()->create(['is_active' => false]);
        $endpoint = self::ENDPOINT.'/'.$target->id.'/enable';

        $this->patchJson($endpoint)
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);

        foreach (['manager', 'staff'] as $role) {
            $actor = User::factory()->create()->assignRole($role)->givePermissionTo('users.manage');
            $token = $actor->createToken('Non-Admin fixture');

            Auth::forgetGuards();

            $this->patchJson($endpoint, [], ['Authorization' => 'Bearer '.$token->plainTextToken])
                ->assertForbidden()
                ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);
        }

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    public function test_admin_receives_not_found_when_enabling_a_missing_user(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $this->withToken($admin->createToken('Admin fixture')->plainTextToken);

        $this->patchJson(self::ENDPOINT.'/999999/enable')
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND);
    }

    #[DataProvider('statusTransitions')]
    public function test_failed_audit_rolls_back_status_and_token_revocation(string $operation, bool $wasActive): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $this->withToken($admin->createToken('Admin fixture')->plainTextToken);
        $target = User::factory()->create(['is_active' => $wasActive]);
        $token = $target->createToken('Rollback fixture');
        AccessAuditLog::saving(static fn (): bool => false);

        $this->patchJson(self::ENDPOINT.'/'.$target->id.'/'.$operation)
            ->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR)
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => $wasActive]);
        $this->assertModelExists($token->accessToken);
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function statusTransitions(): array
    {
        return [
            'disable' => ['disable', true],
            'enable' => ['enable', false],
        ];
    }

    private function assertStatusAudit(User $actor, User $target, string $action, bool $isActive): void
    {
        $audit = AccessAuditLog::query()->where('action', $action)->sole();

        self::assertSame($actor->id, $audit->actor_id);
        self::assertSame('user', $audit->target_type);
        self::assertSame($target->id, $audit->target_id);
        self::assertSame(['is_active' => ! $isActive], $audit->before_values);
        self::assertSame(['is_active' => $isActive], $audit->after_values);
    }
}
