<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Guard;

final readonly class DashboardPolicy
{
    public function __construct(
        private OrderPolicy $orderPolicy,
        private CustomerPolicy $customerPolicy,
        private ProductPolicy $productPolicy,
    ) {}

    public function view(User $actor): bool
    {
        return $this->orderPolicy->viewAll($actor)
            && $actor->checkPermissionTo('dashboard.view', Guard::getDefaultName($actor));
    }

    public function viewCustomerTotals(User $actor): bool
    {
        return $this->customerPolicy->viewAny($actor);
    }

    public function viewProductTotals(User $actor): bool
    {
        return $this->productPolicy->viewAny($actor);
    }
}
