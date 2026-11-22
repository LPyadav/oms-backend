<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Constants\ApiErrorCode;
use App\Models\AccessAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AdminRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private const string ROLES_ENDPOINT = '/api/admin/roles';

    private const string PERMISSIONS_ENDPOINT = '/api/admin/permissions';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 20,
        ]);

    }

    public function test_admin_can_list_paginated_roles_and_available_permissions_for_the_web_guard(): void
    {
        $this->authenticateAs('admin');
        $manager = Role::findByName('manager', 'web');
        $managerPermissions = $manager->permissions->pluck('name')->sort(SORT_STRING)->values()->all();
        Role::create(['name' => 'other guard', 'guard_name' => 'api']);
        Permission::create(['name' => 'other.guard', 'guard_name' => 'api']);

        Config::set([
            'pagination.roles.default_per_page' => 1,
            'pagination.roles.max_per_page' => 2,
        ]);

        $this->getJson(self::ROLES_ENDPOINT.'?page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.roles', [[
                'id' => $manager->id,
                'name' => 'manager',
                'permissions' => $managerPermissions,
            ]])
            ->assertJsonPath('meta', [
                'current_page' => 2,
                'per_page' => 1,
                'total' => 3,
                'last_page' => 3,
            ]);

        $permissionNames = Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();
        $this->getJson(self::PERMISSIONS_ENDPOINT)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.permissions', $permissionNames)
            ->assertJsonPath('data.admin_only_permissions', [
                'audit.view',
                'customers.manage',
                'products.manage',
                'roles.manage',
                'users.manage',
            ])
            ->assertJsonPath('meta', null);

        $this->getJson(self::ROLES_ENDPOINT.'?page=0&per_page=3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page', 'per_page'], 'errors.fields');
    }

    public function test_custom_roles_cannot_acquire_new_admin_only_permissions(): void
    {
        $this->authenticateAs('admin');
        $role = Role::create(['name' => 'Warehouse Operator', 'guard_name' => 'web']);
        $path = self::ROLES_ENDPOINT.'/'.$role->id.'/permissions';

        $this->patchJson($path, ['permissions' => ['users.manage', 'roles.manage']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions'], 'errors.fields');

        self::assertCount(0, $role->refresh()->permissions);
        $this->assertDatabaseCount('access_audit_logs', 0);

        $role->givePermissionTo('users.manage');

        $this->patchJson($path, ['permissions' => ['users.manage', 'orders.view.any']])
            ->assertOk();

        $this->patchJson($path, ['permissions' => ['orders.view.any']])
            ->assertOk()
            ->assertJsonPath('data.role.permissions', ['orders.view.any']);
    }

    public function test_admin_can_create_and_rename_a_custom_role_without_altering_its_grants(): void
    {
        $actor = $this->authenticateAs('admin');

        $this->postJson(self::ROLES_ENDPOINT, ['name' => 'Warehouse Lead'])
            ->assertCreated()
            ->assertJsonPath('data.role.name', 'Warehouse Lead')
            ->assertJsonPath('data.role.permissions', []);

        $role = Role::findByName('Warehouse Lead', 'web');
        $role->givePermissionTo('orders.assign');
        $user = User::factory()->create()->assignRole($role);

        $this->patchJson(self::ROLES_ENDPOINT.'/'.$role->id, ['name' => 'Dispatch Lead'])
            ->assertOk()
            ->assertJsonPath('data.role.id', $role->id)
            ->assertJsonPath('data.role.name', 'Dispatch Lead')
            ->assertJsonPath('data.role.permissions', ['orders.assign']);

        self::assertSame('Dispatch Lead', $role->refresh()->name);
        self::assertTrue($user->refresh()->hasRole($role));
        self::assertTrue($user->checkPermissionTo('orders.assign'));

        $this->patchJson(self::ROLES_ENDPOINT.'/'.$role->id, ['name' => 'Dispatch Lead'])->assertOk();

        $creation = AccessAuditLog::query()->where('action', 'role.created')->sole();
        self::assertSame($actor->id, $creation->actor_id);
        self::assertSame('role', $creation->target_type);
        self::assertSame($role->id, $creation->target_id);
        self::assertNull($creation->before_values);
        self::assertSame(['name' => 'Warehouse Lead'], $creation->after_values);

        $rename = AccessAuditLog::query()->where('action', 'role.renamed')->sole();
        self::assertSame($actor->id, $rename->actor_id);
        self::assertSame('role', $rename->target_type);
        self::assertSame($role->id, $rename->target_id);
        self::assertSame(['name' => 'Warehouse Lead'], $rename->before_values);
        self::assertSame(['name' => 'Dispatch Lead'], $rename->after_values);
    }

    public function test_role_names_must_be_valid_body_fields_and_duplicates_return_conflict(): void
    {
        $this->authenticateAs('admin');
        $role = Role::create(['name' => 'Dispatch Lead', 'guard_name' => 'web']);

        $this->postJson(self::ROLES_ENDPOINT.'?name=QueryOnly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'], 'errors.fields');

        $this->postJson(self::ROLES_ENDPOINT, ['name' => str_repeat('x', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'], 'errors.fields');

        $this->postJson(self::ROLES_ENDPOINT, ['name' => ' ADMIN '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'], 'errors.fields');

        $this->postJson(self::ROLES_ENDPOINT, ['name' => $role->name])
            ->assertConflict()
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertJsonValidationErrors(['name'], 'errors.fields');

        $this->patchJson(self::ROLES_ENDPOINT.'/'.$role->id.'?name=QueryOnly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'], 'errors.fields');

        $this->patchJson(self::ROLES_ENDPOINT.'/'.$role->id, ['name' => 'staff'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'], 'errors.fields');

        $this->assertDatabaseCount('roles', 4);
        self::assertSame('Dispatch Lead', $role->refresh()->name);
    }

    #[DataProvider('builtInRoles')]
    public function test_admin_cannot_rename_built_in_roles(string $name): void
    {
        $this->authenticateAs('admin');
        $role = Role::findByName($name, 'web');

        $this->patchJson(self::ROLES_ENDPOINT.'/'.$role->id, ['name' => 'Replacement'])
            ->assertForbidden()
            ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);

        self::assertSame($name, $role->refresh()->name);
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    public function test_admin_role_permissions_are_protected(): void
    {
        $this->authenticateAs('admin');
        $role = Role::findByName('admin', 'web');
        $permissions = $role->permissions->modelKeys();

        $this->patchJson(self::ROLES_ENDPOINT.'/'.$role->id.'/permissions', ['permissions' => []])
            ->assertForbidden()
            ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);

        self::assertSame($permissions, $role->permissions->modelKeys());
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    #[DataProvider('editableBuiltInRoles')]
    public function test_admin_can_check_and_uncheck_permissions_for_an_existing_role(
        string $name,
        string $unchecked,
        string $checked,
    ): void {
        $role = Role::findByName($name, 'web');
        $before = $role->permissions->pluck('name')->sort(SORT_STRING)->values()->all();
        self::assertContains($unchecked, $before);
        self::assertNotContains($checked, $before);

        $selected = array_values(array_diff($before, [$unchecked]));
        $selected[] = $checked;
        sort($selected, SORT_STRING);

        $member = User::factory()->create()->assignRole($role);
        $token = $member->createToken('Role member test')->plainTextToken;
        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.permissions', $before);

        Auth::forgetGuards();
        $actor = $this->authenticateAs('admin');
        $path = self::ROLES_ENDPOINT.'/'.$role->id.'/permissions';

        $this->patchJson($path, ['permissions' => [...$selected, 'roles.manage']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions'], 'errors.fields');
        $this->patchJson($path, ['permissions' => $selected])
            ->assertOk()
            ->assertJsonPath('data.role.permissions', $selected);

        $this->getJson(self::ROLES_ENDPOINT)
            ->assertOk()
            ->assertJsonFragment([
                'id' => $role->id,
                'name' => $name,
                'permissions' => $selected,
            ]);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.permissions', $selected);

        self::assertFalse($member->refresh()->can($unchecked));
        self::assertTrue($member->can($checked));
        $this->assertDatabaseCount('access_audit_logs', 1);
        $this->assertDatabaseHas('access_audit_logs', [
            'actor_id' => $actor->id,
            'target_type' => 'role',
            'target_id' => $role->id,
            'action' => 'role.permissions.replaced',
        ]);
    }

    public function test_admin_can_replace_permissions_and_audit_the_change_once(): void
    {
        $actor = $this->authenticateAs('admin');
        $role = Role::create(['name' => 'Dispatch Lead', 'guard_name' => 'web']);
        $before = Permission::findByName('orders.view.assigned', 'web');
        $created = Permission::findByName('orders.create', 'web');
        $assigned = Permission::findByName('orders.assign', 'web');
        $role->givePermissionTo($before);
        $path = self::ROLES_ENDPOINT.'/'.$role->id.'/permissions';

        $this->patchJson($path, ['permissions' => [$created->name, $assigned->name]])
            ->assertOk()
            ->assertJsonPath('data.role.permissions', ['orders.assign', 'orders.create']);

        $this->patchJson($path, ['permissions' => [$assigned->name, $created->name]])->assertOk();

        $log = AccessAuditLog::query()->sole();
        self::assertSame($actor->id, $log->actor_id);
        self::assertSame('role', $log->target_type);
        self::assertSame($role->id, $log->target_id);
        self::assertSame('role.permissions.replaced', $log->action);
        self::assertSame([
            'permissions' => [['id' => $before->id, 'name' => $before->name]],
        ], $log->before_values);
        self::assertSame([
            'permissions' => [
                ['id' => $created->id, 'name' => $created->name],
                ['id' => $assigned->id, 'name' => $assigned->name],
            ],
        ], $log->after_values);
        self::assertEqualsCanonicalizing([$created->id, $assigned->id], $role->refresh()->permissions->modelKeys());
    }

    public function test_revoked_role_permission_is_denied_if_an_old_cache_fill_finishes_late(): void
    {
        $this->authenticateAs('admin');
        $role = Role::create(['name' => 'Dispatch Lead', 'guard_name' => 'web']);
        $permission = 'orders.assign';
        $role->givePermissionTo($permission);
        $assignee = User::factory()->create()->assignRole($role);

        self::assertTrue($assignee->can($permission));
        $cacheKey = Config::string('permission.cache.key');
        $oldPermissions = Cache::get($cacheKey);
        self::assertIsArray($oldPermissions);

        $this->patchJson(self::ROLES_ENDPOINT.'/'.$role->id.'/permissions', ['permissions' => []])
            ->assertOk()
            ->assertJsonPath('data.role.permissions', []);

        // A reader that started before the revocation may publish its old snapshot late.
        Cache::forever($cacheKey, $oldPermissions);
        app(PermissionRegistrar::class)->clearPermissionsCollection();

        $reloadedAssignee = User::query()->findOrFail($assignee->id);
        self::assertFalse($reloadedAssignee->can($permission));
        self::assertFalse($reloadedAssignee->checkPermissionTo($permission));
    }

    public function test_permissions_require_a_body_list_and_an_empty_array_clears_them(): void
    {
        $this->authenticateAs('admin');
        $role = Role::create(['name' => 'Dispatch Lead', 'guard_name' => 'web']);
        $role->givePermissionTo('orders.assign');
        $path = self::ROLES_ENDPOINT.'/'.$role->id.'/permissions';

        $this->patchJson($path, ['permissions' => (object) []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions'], 'errors.fields');
        $this->patchJson($path.'?permissions[]=orders.create')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions'], 'errors.fields');

        foreach (['orders.unknown', 'ORDERS.ASSIGN'] as $invalidName) {
            $this->patchJson($path, ['permissions' => [$invalidName]])
                ->assertUnprocessable()
                ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED);
        }

        self::assertSame(['orders.assign'], $role->refresh()->permissions->pluck('name')->all());
        $this->assertDatabaseCount('access_audit_logs', 0);

        $this->patchJson($path, ['permissions' => []])
            ->assertOk()
            ->assertJsonPath('data.role.permissions', []);

        self::assertTrue($role->permissions()->doesntExist());
        self::assertSame(['permissions' => []], AccessAuditLog::query()->sole()->after_values);
    }

    public function test_permission_replacement_rolls_back_if_the_audit_cannot_be_saved(): void
    {
        $this->authenticateAs('admin');
        $role = Role::create(['name' => 'Dispatch Lead', 'guard_name' => 'web']);
        $role->givePermissionTo('orders.assign');
        AccessAuditLog::saving(static fn (): bool => false);

        $this->patchJson(self::ROLES_ENDPOINT.'/'.$role->id.'/permissions', [
            'permissions' => ['orders.create'],
        ])
            ->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR)
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);

        self::assertSame(['orders.assign'], $role->refresh()->permissions->pluck('name')->all());
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    public function test_admin_cannot_change_a_role_in_another_guard(): void
    {
        $this->authenticateAs('admin');
        $role = Role::create(['name' => 'Other Guard', 'guard_name' => 'api']);
        $path = self::ROLES_ENDPOINT.'/'.$role->id;

        $this->patchJson($path, ['name' => 'Renamed'])
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND);
        $this->patchJson($path.'/permissions', ['permissions' => []])
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND);

        self::assertSame('Other Guard', $role->refresh()->name);
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    public function test_guests_cannot_manage_roles_or_list_permissions(): void
    {
        $this->assertRoleAccessDenied(Response::HTTP_UNAUTHORIZED, ApiErrorCode::AUTH_UNAUTHENTICATED);
    }

    public function test_roles_manage_without_the_admin_role_does_not_grant_access(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $manager->givePermissionTo('roles.manage');
        $this->withToken($manager->createToken('Role management test')->plainTextToken);

        $this->assertRoleAccessDenied(Response::HTTP_FORBIDDEN, ApiErrorCode::ACCESS_FORBIDDEN);
    }

    public function test_admin_without_roles_manage_cannot_manage_roles(): void
    {
        Role::findByName('admin', 'web')->revokePermissionTo('roles.manage');
        $this->authenticateAs('admin');

        $this->assertRoleAccessDenied(Response::HTTP_FORBIDDEN, ApiErrorCode::ACCESS_FORBIDDEN);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function builtInRoles(): array
    {
        return [
            'admin' => ['admin'],
            'manager' => ['manager'],
            'staff' => ['staff'],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function editableBuiltInRoles(): array
    {
        return [
            'manager' => ['manager', 'orders.assign', 'orders.notes.create.assigned'],
            'staff' => ['staff', 'orders.view.assigned', 'orders.assign'],
        ];
    }

    private function authenticateAs(string $role): User
    {
        $user = User::factory()->create()->assignRole($role);
        $this->withToken($user->createToken('Role management test')->plainTextToken);

        return $user;
    }

    private function assertRoleAccessDenied(int $status, string $errorCode): void
    {
        $missing = self::ROLES_ENDPOINT.'/999999';

        $this->getJson(self::ROLES_ENDPOINT)
            ->assertStatus($status)
            ->assertJsonPath('errors.code', $errorCode);
        $this->getJson(self::PERMISSIONS_ENDPOINT)
            ->assertStatus($status)
            ->assertJsonPath('errors.code', $errorCode);
        $this->postJson(self::ROLES_ENDPOINT, ['name' => 'Unauthorized role'])
            ->assertStatus($status)
            ->assertJsonPath('errors.code', $errorCode);
        $this->patchJson($missing, ['name' => 'Unauthorized role'])
            ->assertStatus($status)
            ->assertJsonPath('errors.code', $errorCode);
        $this->patchJson($missing.'/permissions', ['permissions' => []])
            ->assertStatus($status)
            ->assertJsonPath('errors.code', $errorCode);
    }
}
