<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $managerPermissions = [
            'customers.view',
            'products.view',
            'orders.view.any',
            'orders.create',
            'orders.update',
            'orders.assign',
            'orders.status.update.any',
            'orders.notes.create.any',
            'dashboard.view',
            'reports.view',
        ];

        $staffPermissions = [
            'customers.view.assigned-orders',
            'orders.view.assigned',
            'orders.status.update.assigned',
            'orders.notes.create.assigned',
        ];

        $rolePermissions = [
            'admin' => [
                ...$managerPermissions,
                ...$staffPermissions,
                'users.manage',
                'roles.manage',
                'customers.manage',
                'products.manage',
                'orders.delete',
                'audit.view',
            ],
            'manager' => $managerPermissions,
            'staff' => $staffPermissions,
        ];

        $guardName = Guard::getDefaultName(User::class);
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        DB::transaction(static function () use ($guardName, $rolePermissions): void {
            $permissionsByName = [];

            foreach ($rolePermissions as $roleName => $permissionNames) {
                $role = Role::query()->firstOrCreate([
                    'name' => $roleName,
                    'guard_name' => $guardName,
                ]);

                $permissions = [];

                foreach ($permissionNames as $permissionName) {
                    $permissionsByName[$permissionName] ??= Permission::query()->firstOrCreate([
                        'name' => $permissionName,
                        'guard_name' => $guardName,
                    ]);

                    $permissions[] = $permissionsByName[$permissionName];
                }

                if ($role->wasRecentlyCreated) {
                    $role->givePermissionTo($permissions);
                }
            }
        });

        $registrar->forgetCachedPermissions();
    }
}
