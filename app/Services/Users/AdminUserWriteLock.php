<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

final class AdminUserWriteLock
{
    /**
     * Acquire the shared Admin role lock before the actor row in the caller's transaction.
     *
     * @return array{adminRole: Role, actor: User}
     */
    public function acquire(User $actor): array
    {
        $adminRoleName = 'admin';
        $guardName = Guard::getDefaultName(User::class);

        $adminRole = Role::query()
            ->where('name', $adminRoleName)
            ->where('guard_name', $guardName)
            ->lockForUpdate()
            ->first();

        if (
            $adminRole === null
            || $adminRole->name !== $adminRoleName
            || $adminRole->guard_name !== $guardName
        ) {
            throw new LogicException('The Admin role must be seeded before managing user accounts.');
        }

        $lockedActor = User::query()->lockForUpdate()->find($actor->id);

        if ($lockedActor === null) {
            throw new AuthorizationException;
        }

        return ['adminRole' => $adminRole, 'actor' => $lockedActor];
    }
}
