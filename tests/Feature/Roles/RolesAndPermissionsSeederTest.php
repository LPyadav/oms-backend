<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_repairs_existing_built_in_roles_and_preserves_other_grants(): void
    {
        $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $manager = Role::create(['name' => 'manager', 'guard_name' => 'web']);
        $staff = Role::create(['name' => 'staff', 'guard_name' => 'web']);
        $otherGuardAdmin = Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $extraGrant = Permission::create(['name' => 'orders.export', 'guard_name' => 'web']);
        $otherGuardGrant = Permission::create(['name' => 'roles.manage', 'guard_name' => 'api']);
        $admin->givePermissionTo($extraGrant);
        $otherGuardAdmin->givePermissionTo($otherGuardGrant);

        $actor = User::factory()->create()->assignRole($admin)->refresh();

        $this->seed(RolesAndPermissionsSeeder::class);

        self::assertTrue(app(RolePolicy::class)->create($actor));
        self::assertTrue(app(UserPolicy::class)->viewAny($actor));
        self::assertTrue($admin->refresh()->hasPermissionTo($extraGrant));
        self::assertTrue($manager->refresh()->hasPermissionTo('orders.assign'));
        self::assertTrue($staff->refresh()->hasPermissionTo('orders.view.assigned'));
        self::assertSame([$otherGuardGrant->id], $otherGuardAdmin->permissions()->pluck('permissions.id')->all());

        $grantCount = DB::table('role_has_permissions')->count();
        $permissionCount = Permission::query()->count();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseCount('roles', 4);
        $this->assertDatabaseCount('permissions', $permissionCount);
        $this->assertDatabaseCount('role_has_permissions', $grantCount);
        self::assertSame([$otherGuardGrant->id], $otherGuardAdmin->permissions()->pluck('permissions.id')->all());
    }
}
