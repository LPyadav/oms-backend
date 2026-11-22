<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Guard;

final class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active
            && $actor->checkPermissionTo('customers.view', Guard::getDefaultName($actor));
    }

    public function view(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function orderContactScope(User $actor): OrderCustomerContactScope
    {
        if ($this->view($actor)) {
            return new OrderCustomerContactScope(viewAll: true);
        }

        $mayViewAssigned = $actor->is_active && $actor->checkPermissionTo('customers.view.assigned-orders', Guard::getDefaultName($actor));

        return new OrderCustomerContactScope(assignedActorId: $mayViewAssigned ? $actor->id : null);
    }

    public function create(User $actor): bool
    {
        return $this->canManageCustomers($actor);
    }

    public function update(User $actor): bool
    {
        return $this->canManageCustomers($actor);
    }

    public function deactivate(User $actor): bool
    {
        return $this->canManageCustomers($actor);
    }

    private function canManageCustomers(User $actor): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        $guardName = Guard::getDefaultName($actor);

        return $actor->hasRole('admin', $guardName)
            && $actor->checkPermissionTo('customers.manage', $guardName);
    }
}
