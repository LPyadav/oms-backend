<?php

declare(strict_types=1);

namespace App\Services\Roles;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class BuiltInAccessProvisioner
{
    public function provision(): void
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

        DB::transaction(static function () use ($guardName, $rolePermissions): void {
            $permissionsByName = [];

            foreach ($rolePermissions as $roleName => $permissionNames) {
                $role = Role::query()->lockForUpdate()->firstOrCreate([
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

                if ($roleName === 'admin' || $role->wasRecentlyCreated) {
                    $role->givePermissionTo($permissions);
                }
            }

            DB::afterCommit(static function (): void {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            });
        });
    }
}
