<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final readonly class UserPolicy
{
    public function __construct(private AdminAuthorization $authorization) {}

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

    public function update(User $actor, ?User $target = null): bool
    {
        return $this->canManageUsers($actor);
    }

    public function changeStatus(User $actor, ?User $target = null): bool
    {
        return $this->canManageUsers($actor);
    }

    public function assignRoles(User $actor, ?User $target = null): bool
    {
        return $this->authorization->allows($actor, 'users.manage', 'roles.manage');
    }

    public function assignPermissions(User $actor): bool
    {
        return $this->assignRoles($actor);
    }

    private function canManageUsers(User $actor): bool
    {
        return $this->authorization->allows($actor, 'users.manage');
    }
}
