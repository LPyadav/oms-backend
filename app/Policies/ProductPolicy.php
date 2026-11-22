<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Guard;

final class ProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active
            && $actor->checkPermissionTo('products.view', Guard::getDefaultName($actor));
    }

    public function view(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManageProducts($actor);
    }

    public function update(User $actor): bool
    {
        return $this->canManageProducts($actor);
    }

    public function deactivate(User $actor): bool
    {
        return $this->canManageProducts($actor);
    }

    public function adjustStock(User $actor): bool
    {
        return $this->canManageProducts($actor);
    }

    private function canManageProducts(User $actor): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        $guardName = Guard::getDefaultName($actor);

        return $actor->hasRole('admin', $guardName)
            && $actor->checkPermissionTo('products.manage', $guardName);
    }
}
