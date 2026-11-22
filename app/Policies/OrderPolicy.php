<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Guard;

final class OrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->viewAll($actor) || $this->hasPermission($actor, 'orders.view.assigned');
    }

    public function viewAll(User $actor): bool
    {
        return $this->hasPermission($actor, 'orders.view.any');
    }

    public function create(User $actor): bool
    {
        return $this->viewAll($actor) && $this->hasPermission($actor, 'orders.create');
    }

    public function update(User $actor): bool
    {
        return $this->viewAll($actor) && $this->hasPermission($actor, 'orders.update');
    }

    public function delete(User $actor): bool
    {
        return $this->viewAll($actor)
            && $actor->hasRole('admin', Guard::getDefaultName($actor))
            && $this->hasPermission($actor, 'orders.delete');
    }

    public function assign(User $actor): bool
    {
        return $this->viewAll($actor) && $this->hasPermission($actor, 'orders.assign');
    }

    public function addInitialNote(User $actor): bool
    {
        return $this->viewAll($actor) && $this->hasPermission($actor, 'orders.notes.create.any');
    }

    public function changeStatus(User $actor): bool
    {
        return $this->changeAnyStatus($actor)
            || ($this->hasPermission($actor, 'orders.view.assigned')
                && $this->hasPermission($actor, 'orders.status.update.assigned'));
    }

    public function changeAnyStatus(User $actor): bool
    {
        return $this->viewAll($actor) && $this->hasPermission($actor, 'orders.status.update.any');
    }

    private function hasPermission(User $actor, string $permission): bool
    {
        return $actor->is_active && $actor->checkPermissionTo($permission, Guard::getDefaultName($actor));
    }
}
