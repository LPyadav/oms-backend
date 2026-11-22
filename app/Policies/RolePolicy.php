<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final readonly class RolePolicy
{
    public function __construct(private AdminAuthorization $authorization) {}

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
        return $this->authorization->allows($actor, 'roles.manage');
    }
}
