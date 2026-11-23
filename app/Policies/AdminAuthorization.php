<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Guard;

final class AdminAuthorization
{
    /**
     * @param  'users.manage'|'roles.manage'|'audit.view'|'audit.view'  $permission
     * @param  'users.manage'|'roles.manage'|'audit.view'|'audit.view'  ...$additionalPermissions
     */
    public function allows(User $actor, string $permission, string ...$additionalPermissions): bool
    {
        $guardName = Guard::getDefaultName($actor);

        // Check current grants and membership together, because a loaded relation can outlive a revocation.
        $query = $actor->newQuery()
            ->useWritePdo()
            ->whereKey($actor->getKey())
            ->where('is_active', true)
            ->whereHas('roles', static fn (Builder $role): Builder => $role
                ->whereLike('name', 'admin', caseSensitive: true)
                ->whereLike('guard_name', $guardName, caseSensitive: true));

        foreach ([$permission, ...$additionalPermissions] as $name) {
            $matchesPermission = static fn (Builder $grant): Builder => $grant
                ->whereLike('name', $name, caseSensitive: true)
                ->whereLike('guard_name', $guardName, caseSensitive: true);

            $query->where(static fn (Builder $user): Builder => $user
                ->whereHas('permissions', $matchesPermission)
                ->orWhereHas('roles.permissions', $matchesPermission));
        }

        return $query->exists();
    }
}
