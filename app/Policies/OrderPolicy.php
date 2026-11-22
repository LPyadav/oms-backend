<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Guard;

final class OrderPolicy
{
    private const string VIEW_ALL = 'orders.view.any';

    private const string UPDATE = 'orders.update';

    private const string ASSIGN = 'orders.assign';

    private const string ADD_ANY_NOTE = 'orders.notes.create.any';

    public function viewAny(User $actor): bool
    {
        return $this->viewAll($actor) || $this->hasPermission($actor, 'orders.view.assigned');
    }

    public function viewAll(User $actor): bool
    {
        return $this->hasPermission($actor, self::VIEW_ALL);
    }

    public function create(
        User $actor,
        bool $withAssignment = false,
        bool $withNote = false,
        bool $withTaxOverride = false,
    ): bool {
        if (! $actor->is_active) {
            return false;
        }

        // Read grants from the actor loaded under the write lock for create and replay.
        $permissions = $actor->getAllPermissions()
            ->whereStrict('guard_name', Guard::getDefaultName($actor))
            ->pluck('name');

        return $permissions->containsStrict(self::VIEW_ALL)
            && $permissions->containsStrict('orders.create')
            && (! $withAssignment || $permissions->containsStrict(self::ASSIGN))
            && (! $withNote || $permissions->containsStrict(self::ADD_ANY_NOTE))
            && (! $withTaxOverride || $permissions->containsStrict(self::UPDATE));
    }

    public function update(User $actor): bool
    {
        return $this->viewAll($actor) && $this->hasPermission($actor, self::UPDATE);
    }

    public function delete(User $actor): bool
    {
        return $this->viewAll($actor)
            && $actor->hasRole('admin', Guard::getDefaultName($actor))
            && $this->hasPermission($actor, 'orders.delete');
    }

    public function assign(User $actor): bool
    {
        return $this->viewAll($actor) && $this->hasPermission($actor, self::ASSIGN);
    }

    public function addNote(User $actor): bool
    {
        return $this->addAnyNote($actor)
            || ($this->hasPermission($actor, 'orders.view.assigned')
                && $this->hasPermission($actor, 'orders.notes.create.assigned'));
    }

    public function addAnyNote(User $actor): bool
    {
        return $this->viewAll($actor) && $this->hasPermission($actor, self::ADD_ANY_NOTE);
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
