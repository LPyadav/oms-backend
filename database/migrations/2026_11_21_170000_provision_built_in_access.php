<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const string GUARD = 'web';

    public function up(): void
    {
        // Keep these grants fixed to the version of the permission catalog this migration introduced.
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

        $grants = [
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

        DB::connection($this->getConnection())->transaction(function (Connection $connection) use ($grants): void {
            $rolesTable = Config::string('permission.table_names.roles');
            $permissionsTable = Config::string('permission.table_names.permissions');
            $grantsTable = Config::string('permission.table_names.role_has_permissions');
            $columns = Config::array('permission.column_names');
            $roleKey = $columns['role_pivot_key'] ?? 'role_id';
            $permissionKey = $columns['permission_pivot_key'] ?? 'permission_id';

            // Lock the Admin role first, following the ordering used by role writes.
            $roles = [];
            foreach (array_keys($grants) as $name) {
                $roles[$name] = $this->ensureAccessRecord($connection, $rolesTable, $name);
            }

            $permissionIds = [];
            foreach ($grants['admin'] as $permission) {
                $permissionIds[$permission] = $this->ensureAccessRecord($connection, $permissionsTable, $permission)['id'];
            }

            foreach ($roles as $name => $role) {
                if ($name !== 'admin' && ! $role['created']) {
                    continue;
                }

                $requiredIds = array_map(static fn (string $permission): int => $permissionIds[$permission], $grants[$name]);
                $grantedIds = $connection->table($grantsTable)->where($roleKey, $role['id'])->pluck($permissionKey)->all();
                $missingIds = array_diff($requiredIds, $grantedIds);

                if ($missingIds !== []) {
                    $connection->table($grantsTable)->insert(array_map(
                        static fn (int $id): array => [$roleKey => $role['id'], $permissionKey => $id],
                        $missingIds,
                    ));
                }
            }

            $connection->afterCommit(static function (): void {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            });
        });
    }

    /** @return array{id: int, created: bool} */
    private function ensureAccessRecord(Connection $connection, string $table, string $name): array
    {
        $identity = ['name' => $name, 'guard_name' => self::GUARD];
        $existing = $connection->table($table)->where($identity)->lockForUpdate()->first(['id', 'name', 'guard_name']);

        if ($existing !== null) {
            if ($existing->name !== $name || $existing->guard_name !== self::GUARD) {
                throw new LogicException("Access record {$table}.{$name} must match its configured name and guard exactly.");
            }

            return ['id' => (int) $existing->id, 'created' => false];
        }

        $now = now();
        $id = $connection->table($table)->insertGetId([...$identity, 'created_at' => $now, 'updated_at' => $now]);

        return ['id' => $id, 'created' => true];
    }

    public function down(): void
    {
        // Keep roles and grants that may predate this migration or have live assignments.
    }
};
