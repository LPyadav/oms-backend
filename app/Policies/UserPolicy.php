<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Guard;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canManageUsers($actor);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->canManageUsers($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManageUsers($actor);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->canManageUsers($actor);
    }

    public function changeStatus(User $actor, User $target): bool
    {
        return $this->canManageUsers($actor);
    }

    public function assignRoles(User $actor, User $target): bool
    {
        return $this->canManageUsers($actor)
            && $actor->checkPermissionTo('roles.manage');
    }

    private function canManageUsers(User $actor): bool
    {
        return $actor->is_active
            && $actor->hasRole('admin', Guard::getDefaultName($actor))
            && $actor->checkPermissionTo('users.manage');
    }
}
