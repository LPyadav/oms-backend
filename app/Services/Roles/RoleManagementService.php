<?php

declare(strict_types=1);

namespace App\Services\Roles;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Services\Audit\AccessAuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

final readonly class RoleManagementService
{
    private const array BUILT_IN_ROLE_NAMES = ['admin', 'manager', 'staff'];

    public function __construct(
        private RolePolicy $rolePolicy,
        private AccessAuditLogWriter $auditLogWriter,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Role>
     */
    public function paginate(User $actor, int $perPage, int $page): LengthAwarePaginator
    {
        if (! $this->rolePolicy->viewAny($actor)) {
            throw new AuthorizationException;
        }

        return Role::query()
            ->where('guard_name', Guard::getDefaultName(User::class))
            ->with('permissions')
            ->orderBy('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    public function create(User $actor, string $name): Role
    {
        return DB::transaction(function () use ($actor, $name): Role {
            $guardName = Guard::getDefaultName(User::class);
            $lockedActor = $this->lockActor($actor, $guardName);

            if (! $this->rolePolicy->create($lockedActor)) {
                throw new AuthorizationException;
            }

            $role = new Role([
                'name' => $this->customRoleName($name),
                'guard_name' => $guardName,
            ]);

            $this->save($role);
            $this->auditLogWriter->record($lockedActor, $role, 'role.created', null, ['name' => $role->name]);

            return $role->load('permissions');
        });
    }

    public function update(User $actor, int|string $roleId, string $name): Role
    {
        return DB::transaction(function () use ($actor, $roleId, $name): Role {
            $guardName = Guard::getDefaultName(User::class);
            $lockedActor = $this->lockActor($actor, $guardName);

            if (! $this->rolePolicy->update($lockedActor)) {
                throw new AuthorizationException;
            }

            $role = $this->lockCustomRole($roleId, $guardName);
            $beforeName = $role->name;
            $role->name = $this->customRoleName($name);

            if ($role->isDirty('name')) {
                $this->save($role);
                $this->auditLogWriter->record(
                    $lockedActor,
                    $role,
                    'role.renamed',
                    ['name' => $beforeName],
                    ['name' => $role->name],
                );
            }

            return $role->load('permissions');
        });
    }

    /**
     * @return list<string>
     */
    public function permissions(User $actor): array
    {
        if (! $this->rolePolicy->viewAny($actor)) {
            throw new AuthorizationException;
        }

        $guardName = Guard::getDefaultName(User::class);
        $names = Permission::query()
            ->where('guard_name', $guardName)
            ->get(['name', 'guard_name'])
            ->whereStrict('guard_name', $guardName)
            ->map(static fn (Permission $permission): string => $permission->name)
            ->sort(SORT_STRING);

        return array_values($names->all());
    }

    /**
     * @param  list<string>  $permissionNames
     */
    public function replacePermissions(User $actor, int|string $roleId, array $permissionNames): Role
    {
        return DB::transaction(function () use ($actor, $roleId, $permissionNames): Role {
            $guardName = Guard::getDefaultName(User::class);
            $lockedActor = $this->lockActor($actor, $guardName);

            if (! $this->rolePolicy->assignPermissions($lockedActor)) {
                throw new AuthorizationException;
            }

            $role = $this->lockCustomRole($roleId, $guardName);
            $permissions = Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', $permissionNames)
                ->orderBy('id')
                ->get()
                ->filter(static fn (Permission $permission): bool => $permission->guard_name === $guardName
                    && in_array($permission->name, $permissionNames, true))
                ->values();

            if ($permissions->count() !== count($permissionNames)) {
                throw ValidationException::withMessages([
                    'permissions' => Lang::string('validation.exists', ['attribute' => 'permissions']),
                ]);
            }

            $currentPermissions = $role->permissions->sortBy('id')->values();

            if ($currentPermissions->modelKeys() === $permissions->modelKeys()) {
                return $role;
            }

            $beforePermissions = $this->permissionSnapshot($currentPermissions);

            $role->syncPermissions(...$permissions->all());
            $role->load('permissions');

            $this->auditLogWriter->record(
                $lockedActor,
                $role,
                'role.permissions.replaced',
                ['permissions' => $beforePermissions],
                ['permissions' => $this->permissionSnapshot($role->permissions)],
            );

            DB::afterCommit(static function (): void {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            });

            return $role;
        });
    }

    private function lockCustomRole(int|string $roleId, string $guardName): Role
    {
        $role = Role::query()
            ->where('guard_name', $guardName)
            ->lockForUpdate()
            ->findOrFail($roleId);

        if ($role->guard_name !== $guardName) {
            throw (new ModelNotFoundException)->setModel(Role::class, [$roleId]);
        }

        if ($this->isBuiltInName($role->name)) {
            throw new AuthorizationException;
        }

        return $role;
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     * @return list<array{id: int|string, name: string}>
     */
    private function permissionSnapshot(Collection $permissions): array
    {
        $snapshot = [];

        foreach ($permissions->sortBy('id') as $permission) {
            $snapshot[] = [
                'id' => $permission->id,
                'name' => $permission->name,
            ];
        }

        return $snapshot;
    }

    private function lockActor(User $actor, string $guardName): User
    {
        $adminRole = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', $guardName)
            ->lockForUpdate()
            ->first();

        if ($adminRole === null || $adminRole->name !== 'admin' || $adminRole->guard_name !== $guardName) {
            throw new LogicException('The Admin role must be seeded before changing roles.');
        }

        $lockedActor = User::query()->lockForUpdate()->find($actor->id);

        if ($lockedActor === null) {
            throw new AuthorizationException;
        }

        return $lockedActor;
    }

    private function customRoleName(string $name): string
    {
        $name = trim($name);

        if ($this->isBuiltInName($name)) {
            throw ValidationException::withMessages([
                'name' => Lang::string('validation.not_in', ['attribute' => 'name']),
            ]);
        }

        return $name;
    }

    private function isBuiltInName(string $name): bool
    {
        return in_array(Str::lower($name), self::BUILT_IN_ROLE_NAMES, true);
    }

    private function save(Role $role): void
    {
        try {
            $saved = $role->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => Lang::string('validation.unique', ['attribute' => 'name']),
            ])->status(Response::HTTP_CONFLICT);
        }

        if (! $saved) {
            throw new RuntimeException('The role could not be saved.');
        }
    }
}
