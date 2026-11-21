<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Guard;

final class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canManageRoles($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManageRoles($actor);
    }

    public function update(User $actor): bool
    {
        return $this->canManageRoles($actor);
    }

    public function assignPermissions(User $actor): bool
    {
        return $this->canManageRoles($actor);
    }

    private function canManageRoles(User $actor): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        $guardName = Guard::getDefaultName($actor);

        return $actor->hasRole('admin', $guardName)
            && $actor->checkPermissionTo('roles.manage', $guardName);
    }
}
