<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Audit\AccessAuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;

final readonly class UserPermissionAssignmentService
{
    public function __construct(
        private UserPolicy $userPolicy,
        private AdminUserWriteLock $writeLock,
        private AccessAuditLogWriter $auditLogWriter,
    ) {}

    /**
     * @return array{user_id: int, direct_permissions: list<string>, inherited_permissions: list<string>}
     */
    public function show(User $actor, string $targetId): array
    {
        $this->authorize($actor);
        $target = User::query()->findOrFail($targetId);

        return $this->snapshot($target, Guard::getDefaultName($target));
    }

    /**
     * @param  list<string>  $permissionNames
     * @return array{user_id: int, direct_permissions: list<string>, inherited_permissions: list<string>}
     */
    public function replace(User $actor, string $targetId, array $permissionNames): array
    {
        return DB::transaction(function () use ($actor, $targetId, $permissionNames): array {
            ['actor' => $lockedActor] = $this->writeLock->acquire($actor);
            $this->authorize($lockedActor);

            $target = User::query()->lockForUpdate()->findOrFail($targetId);
            $guardName = Guard::getDefaultName($target);
            $permissions = Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', $permissionNames)
                ->get()
                ->filter(static fn (Permission $permission): bool => $permission->guard_name === $guardName
                    && in_array($permission->name, $permissionNames, true))
                ->values();

            if ($permissions->count() !== count($permissionNames)) {
                throw ValidationException::withMessages([
                    'direct_permissions' => Lang::string('validation.exists', ['attribute' => 'direct permissions']),
                ]);
            }

            $before = $this->names($target->permissions, $guardName);
            $after = $this->names($permissions, $guardName);

            if ($before !== $after) {
                $target->syncPermissions(...$permissions->all());
                $this->auditLogWriter->record(
                    $lockedActor,
                    $target,
                    'user.permissions.replaced',
                    ['direct_permissions' => $before],
                    ['direct_permissions' => $after],
                );
            }

            return $this->snapshot($target, $guardName);
        });
    }

    private function authorize(User $actor): void
    {
        if (! $this->userPolicy->assignPermissions($actor)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @return array{user_id: int, direct_permissions: list<string>, inherited_permissions: list<string>}
     */
    private function snapshot(User $target, string $guardName): array
    {
        return [
            'user_id' => $target->id,
            'direct_permissions' => $this->names($target->permissions, $guardName),
            'inherited_permissions' => $this->names($target->getPermissionsViaRoles(), $guardName),
        ];
    }

    /**
     * @param  Collection<array-key, mixed>  $permissions
     * @return list<string>
     */
    private function names(Collection $permissions, string $guardName): array
    {
        $names = $permissions
            ->whereInstanceOf(Permission::class)
            ->whereStrict('guard_name', $guardName)
            ->map(static fn (Permission $permission): string => $permission->name)
            ->uniqueStrict()
            ->sort(SORT_STRING);

        return array_values($names->all());
    }
}
