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
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AdminUserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/admin/users';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
        ]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_read_separate_direct_and_inherited_grants(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('admin'));
        $role = Role::findOrCreate('Shift lead', 'web')->givePermissionTo('orders.create', 'products.view');
        $target = User::factory()->create()
            ->assignRole('manager', $role)
            ->givePermissionTo('orders.create', 'audit.view');
        $inherited = Role::findByName('manager', 'web')->permissions
            ->pluck('name')->sort(SORT_STRING)->values()->all();

        $this->getJson($this->endpoint($target))
            ->assertOk()
            ->assertJsonPath('data', [
                'user_id' => $target->id,
                'direct_permissions' => ['audit.view', 'orders.create'],
                'inherited_permissions' => $inherited,
            ]);
    }

    public function test_admin_can_replace_and_clear_direct_grants_with_transactional_audit(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $staff = Role::findByName('staff', 'web');
        $names = $staff->permissions
            ->whereInstanceOf(Permission::class)
            ->map(static fn (Permission $permission): string => $permission->name)
            ->sort(SORT_STRING);
        $inherited = array_values($names->all());
        $target = User::factory()->create()->assignRole($staff)->givePermissionTo('orders.create');
        $otherUser = User::factory()->create()->assignRole($staff)->givePermissionTo('orders.create');
        $targetToken = $target->createToken('Staff device')->plainTextToken;
        $adminToken = $admin->createToken('Admin device')->plainTextToken;

        $this->withToken($adminToken)
            ->patchJson($this->endpoint($target), ['direct_permissions' => ['products.view', 'orders.view.assigned']])
            ->assertOk()
            ->assertJsonPath('data.direct_permissions', ['orders.view.assigned', 'products.view'])
            ->assertJsonPath('data.inherited_permissions', $inherited);

        $this->patchJson($this->endpoint($target), ['direct_permissions' => ['orders.view.assigned', 'products.view']])
            ->assertOk();
        $this->assertDatabaseCount('access_audit_logs', 1);

        $this->assertEffectivePermissions($targetToken, [...$inherited, 'products.view']);

        Auth::forgetGuards();
        $this->withToken($adminToken)
            ->patchJson($this->endpoint($target), ['direct_permissions' => []])
            ->assertOk()
            ->assertJsonPath('data.direct_permissions', [])
            ->assertJsonPath('data.inherited_permissions', $inherited);

        $this->assertEffectivePermissions($targetToken, $inherited);

        self::assertTrue($target->refresh()->hasExactRoles('staff'));
        self::assertTrue($target->is_active);
        self::assertFalse($target->permissions()->exists());
        self::assertTrue($otherUser->refresh()->hasDirectPermission('orders.create'));
        self::assertSame($inherited, $staff->refresh()->permissions->pluck('name')->sort(SORT_STRING)->values()->all());

        $entries = array_values(AccessAuditLog::query()->orderBy('id')->get()->all());
        self::assertCount(2, $entries);
        [$replacement, $clear] = $entries;
        foreach ($entries as $entry) {
            self::assertSame($admin->id, $entry->actor_id);
            self::assertSame($target->id, $entry->target_id);
            self::assertSame('user', $entry->target_type);
            self::assertSame('user.permissions.replaced', $entry->action);
        }
        self::assertSame(['direct_permissions' => ['orders.create']], $replacement->before_values);
        $savedGrants = ['direct_permissions' => ['orders.view.assigned', 'products.view']];
        self::assertSame($savedGrants, $replacement->after_values);
        self::assertSame($savedGrants, $clear->before_values);
        self::assertSame(['direct_permissions' => []], $clear->after_values);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_targets_cannot_receive_new_admin_only_direct_grants(string $role): void
    {
        $this->authenticate(User::factory()->create()->assignRole('admin'));
        $target = User::factory()->create()->assignRole($role)->givePermissionTo('orders.create');

        $this->patchJson($this->endpoint($target), [
            'direct_permissions' => [
                'users.manage',
                'roles.manage',
                'products.manage',
                'customers.manage',
                'audit.view',
                'products.view',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direct_permissions'], 'errors.fields');

        self::assertSame(['orders.create'], $target->refresh()->permissions->pluck('name')->all());
        $this->assertDatabaseCount('access_audit_logs', 0);
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

    public function test_manager_can_keep_or_clear_existing_admin_only_direct_grants(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('admin'));
        $target = User::factory()->create()->assignRole('manager')->givePermissionTo('audit.view');
        $endpoint = $this->endpoint($target);

        $this->patchJson($endpoint, ['direct_permissions' => ['orders.assign', 'audit.view']])
            ->assertOk()
            ->assertJsonPath('data.direct_permissions', ['audit.view', 'orders.assign']);

        $this->patchJson($endpoint, ['direct_permissions' => ['audit.view', 'roles.manage']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direct_permissions'], 'errors.fields');

        $this->patchJson($endpoint, ['direct_permissions' => []])
            ->assertOk()
            ->assertJsonPath('data.direct_permissions', []);

        $this->patchJson($endpoint, ['direct_permissions' => ['audit.view']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direct_permissions'], 'errors.fields');

        self::assertFalse($target->refresh()->permissions()->exists());
        $this->assertDatabaseCount('access_audit_logs', 2);
    }

    public function test_target_with_the_admin_role_can_receive_admin_only_direct_grants(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('admin'));
        $target = User::factory()->create()->assignRole('manager', 'admin');

        $this->patchJson($this->endpoint($target), ['direct_permissions' => ['audit.view']])
            ->assertOk()
            ->assertJsonPath('data.direct_permissions', ['audit.view']);

        self::assertTrue($target->refresh()->hasDirectPermission('audit.view'));
        $this->assertDatabaseCount('access_audit_logs', 1);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidPermissionBodies')]
    public function test_invalid_input_preserves_the_current_grants(array $body, string $errorField): void
    {
        $this->authenticate(User::factory()->create()->assignRole('admin'));
        $target = User::factory()->create()->assignRole('staff')->givePermissionTo('products.view');
        Permission::findOrCreate('other.guard', 'api');

        $this->patchJson($this->endpoint($target), $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorField], 'errors.fields');

        self::assertSame(['products.view'], $target->refresh()->permissions->pluck('name')->all());
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPermissionBodies(): array
    {
        return [
            'absent list' => [[], 'direct_permissions'],
            'JSON object' => [['direct_permissions' => (object) []], 'direct_permissions'],
            'duplicate' => [['direct_permissions' => ['products.view', 'products.view']], 'direct_permissions.0'],
            'unknown key' => [['direct_permissions' => ['invented.permission']], 'direct_permissions'],
            'wrong spelling' => [['direct_permissions' => ['PRODUCTS.view']], 'direct_permissions'],
            'wrong guard' => [['direct_permissions' => ['other.guard']], 'direct_permissions'],
            'write inherited grants' => [['direct_permissions' => [], 'inherited_permissions' => []], 'inherited_permissions'],
        ];
    }

    public function test_guests_and_unauthorized_users_cannot_access_target_permissions(): void
    {
        $endpoint = self::ENDPOINT.'/999999/permissions';

        $this->getJson($endpoint)->assertUnauthorized();
        $this->patchJson($endpoint, ['direct_permissions' => []])->assertUnauthorized();

        $manager = User::factory()->create()->assignRole('manager')->givePermissionTo('users.manage', 'roles.manage');
        $this->authenticate($manager);
        $this->getJson($endpoint)->assertForbidden();
        $this->patchJson($endpoint, ['direct_permissions' => []])->assertForbidden();

        $adminRole = Role::findByName('admin', 'web');
        $adminRole->revokePermissionTo('roles.manage');
        $admin = User::factory()->create()->assignRole($adminRole);
        $this->authenticate($admin);
        $this->getJson($endpoint)->assertForbidden();
        $this->patchJson($endpoint, ['direct_permissions' => []])->assertForbidden();

        $adminRole->givePermissionTo('roles.manage');
        $this->authenticate($admin);
        $this->getJson($endpoint)->assertNotFound();
        $this->patchJson($endpoint, ['direct_permissions' => []])->assertNotFound();
    }

    public function test_audit_failure_rolls_back_the_direct_grant_replacement(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('admin'));
        $target = User::factory()->create()->assignRole('staff')->givePermissionTo('products.view');
        AccessAuditLog::saving(static fn (): bool => false);

        $this->patchJson($this->endpoint($target), ['direct_permissions' => ['orders.create']])
            ->assertInternalServerError()
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);

        self::assertSame(['products.view'], $target->refresh()->permissions->pluck('name')->all());
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    private function endpoint(User $target): string
    {
        return self::ENDPOINT.'/'.$target->id.'/permissions';
    }

    private function authenticate(User $actor): void
    {
        Auth::forgetGuards();
        $this->withToken($actor->createToken('Admin permissions test')->plainTextToken);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function assertEffectivePermissions(string $token, array $permissions): void
    {
        Auth::forgetGuards();
        sort($permissions, SORT_STRING);

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.permissions', $permissions);
    }
}
