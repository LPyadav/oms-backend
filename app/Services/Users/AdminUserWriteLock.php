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
     * Acquire exclusive access locks before a user or permission change.
     *
     * @return array{adminRole: Role, actor: User}
     */
    public function acquire(User $actor): array
    {
        return $this->lockAccess($actor, exclusive: true);
    }

    /**
     * Keep the current actor's access stable during a write in another domain.
     *
     * Lock the Admin role before the actor row to follow the same lock order
     * as exclusive access changes.
     */
    public function acquireShared(User $actor): User
    {
        return $this->lockAccess($actor, exclusive: false)['actor'];
    }

    /**
     * Lock the Admin role before the actor row in the caller's transaction.
     *
     * @return array{adminRole: Role, actor: User}
     */
    private function lockAccess(User $actor, bool $exclusive): array
    {
        $adminRoleName = 'admin';
        $guardName = Guard::getDefaultName(User::class);

        $adminRole = Role::query()
            ->where('name', $adminRoleName)
            ->where('guard_name', $guardName)
            ->lock($exclusive)
            ->first();

        if (
            $adminRole === null
            || $adminRole->name !== $adminRoleName
            || $adminRole->guard_name !== $guardName
        ) {
            throw new LogicException('The Admin role must be seeded before authorizing administrator writes.');
        }

        $lockedActor = User::query()->lock($exclusive)->find($actor->id);

        if ($lockedActor === null) {
            throw new AuthorizationException;
        }

        return ['adminRole' => $adminRole, 'actor' => $lockedActor];
    }
}
