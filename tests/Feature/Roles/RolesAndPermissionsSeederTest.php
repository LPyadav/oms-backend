<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_provision_built_in_access_without_creating_user_accounts(): void
    {
        $admin = Role::findByName('admin', 'web');
        $manager = Role::findByName('manager', 'web');
        $staff = Role::findByName('staff', 'web');

        self::assertTrue($admin->hasPermissionTo('roles.manage'));
        self::assertTrue($admin->hasPermissionTo('users.manage'));
        self::assertTrue($manager->hasPermissionTo('orders.assign'));
        self::assertTrue($staff->hasPermissionTo('orders.view.assigned'));
        self::assertEqualsCanonicalizing(
            Permission::query()->where('guard_name', 'web')->pluck('name')->all(),
            $admin->getPermissionNames()->all(),
        );
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseEmpty('users');
    }

    public function test_provisioning_repairs_admin_and_preserves_configured_grants_on_upgrade(): void
    {
        $admin = Role::findByName('admin', 'web');
        $manager = Role::findByName('manager', 'web');
        $staff = Role::findByName('staff', 'web');
        $custom = Role::create(['name' => 'warehouse', 'guard_name' => 'web']);
        $otherGuardAdmin = Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $extraGrant = Permission::create(['name' => 'orders.export', 'guard_name' => 'web']);
        $otherGuardGrant = Permission::create(['name' => 'roles.manage', 'guard_name' => 'api']);
        $admin->givePermissionTo($extraGrant);
        $admin->revokePermissionTo('roles.manage');
        $admin->revokePermissionTo('users.manage');
        $manager->syncPermissions($extraGrant);
        $staff->syncPermissions();
        $custom->givePermissionTo($extraGrant);
        $otherGuardAdmin->givePermissionTo($otherGuardGrant);

        $actor = User::factory()->create()->assignRole($admin)->refresh();
        $migration = '2026_11_21_170000_provision_built_in_access';
        DB::table('migrations')->where('migration', $migration)->delete();

        self::assertSame(0, Artisan::call('migrate', ['--path' => 'database/migrations/'.$migration.'.php']));

        self::assertTrue(app(RolePolicy::class)->create($actor));
        self::assertTrue(app(UserPolicy::class)->viewAny($actor));
        self::assertTrue($admin->refresh()->hasPermissionTo($extraGrant));
        self::assertSame(['orders.export'], $manager->refresh()->getPermissionNames()->all());
        self::assertSame([], $staff->refresh()->getPermissionNames()->all());
        self::assertSame(['orders.export'], $custom->refresh()->getPermissionNames()->all());
        self::assertSame([$otherGuardGrant->id], $otherGuardAdmin->permissions()->pluck('permissions.id')->all());

        $grantCount = DB::table('role_has_permissions')->count();
        $permissionCount = Permission::query()->count();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('permissions', $permissionCount);
        $this->assertDatabaseCount('role_has_permissions', $grantCount);
        self::assertSame([$otherGuardGrant->id], $otherGuardAdmin->permissions()->pluck('permissions.id')->all());
    }
}
