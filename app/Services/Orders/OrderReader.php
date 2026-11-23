<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** @phpstan-import-type OrderListCriteria from OrderListQuery */
final readonly class OrderReader
{
    public const array DETAIL_RELATIONS = [
        'customer',
        'assignee:id,name',
        'items',
        'notes.author:id,name',
        'statusHistory.actor:id,name',
        'assignmentHistory.actor:id,name',
        'systemActivity.actor:id,name',
    ];

    public function __construct(
        private OrderPolicy $policy,
        private OrderListQuery $listQuery,
    ) {}

    /**
     * @param  OrderListCriteria  $criteria
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(User $actor, array $criteria): LengthAwarePaginator
    {
        return $this->listQuery->paginate($this->visibleOrders($actor), $criteria, $actor);
    }

    public function find(User $actor, string $orderId): Order
    {
        return DB::transaction(fn (): Order => $this->visibleOrders($actor)
            ->with(self::DETAIL_RELATIONS)
            ->findOrFail($orderId));
    }

    /** @return Builder<Order> */
    public function visibleOrders(User $actor): Builder
    {
        if (! $this->policy->viewAny($actor)) {
            throw new AuthorizationException;
        }

        $query = Order::query();
        if (! $this->policy->viewAll($actor)) {
            $query->where('assignee_id', $actor->id);
        }

        return $query;
    }
}
