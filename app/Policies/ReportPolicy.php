<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Guard;

final readonly class ReportPolicy
{
    public function __construct(private OrderPolicy $orderPolicy) {}

    public function view(User $actor): bool
    {
        return $this->orderPolicy->viewAll($actor)
            && $actor->checkPermissionTo('reports.view', Guard::getDefaultName($actor));
    }
}
