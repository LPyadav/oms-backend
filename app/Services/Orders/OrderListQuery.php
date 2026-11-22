<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderPriority;
use App\Enums\OrderSortField;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\OrderCustomerContactScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @phpstan-type OrderListCriteria array{
 *     search: ?string,
 *     order_number: ?string,
 *     sku: ?string,
 *     status: ?OrderStatus,
 *     priority: ?OrderPriority,
 *     customer_id: ?int,
 *     assignee_id: ?int,
 *     date_from: ?string,
 *     date_to: ?string,
 *     sort: OrderSortField,
 *     direction: 'asc'|'desc',
 *     per_page: int,
 *     page: int
 * }
 */
final class OrderListQuery
{
    public function __construct(
        private readonly CustomerPolicy $customerPolicy,
    ) {}

    /**
     * @param  Builder<Order>  $query
     * @param  OrderListCriteria  $criteria
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(Builder $query, array $criteria, User $actor): LengthAwarePaginator
    {
        if ($criteria['search'] !== null) {
            $this->applySearch($query, $criteria['search'], $this->customerPolicy->orderContactScope($actor));
        }

        $filters = [
            'orders.order_number' => $criteria['order_number'],
            'orders.status' => $criteria['status']?->value,
            'orders.priority' => $criteria['priority']?->value,
            'orders.customer_id' => $criteria['customer_id'],
            'orders.assignee_id' => $criteria['assignee_id'],
        ];

        foreach ($filters as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }

        if ($criteria['sku'] !== null) {
            $matchingOrderIds = OrderItem::query()
                ->select('order_items.order_id')
                ->where('order_items.product_sku', $criteria['sku']);

            $query->whereIn('orders.id', $matchingOrderIds);
        }

        if ($criteria['date_from'] !== null) {
            $query->where('orders.order_date', '>=', $criteria['date_from']);
        }

        $dateTo = $criteria['date_to'];
        if ($dateTo !== null && $dateTo !== '9999-12-31') {
            // A half-open bound includes SQLite's timestamp-formatted date values.
            $nextDate = CarbonImmutable::parse($dateTo, 'UTC')->addDay()->toDateString();
            $query->where('orders.order_date', '<', $nextDate);
        }

        $sort = $criteria['sort'];
        if ($sort === OrderSortField::Customer || ($sort === OrderSortField::OrderDate && $criteria['page'] > 1)) {
            return $this->paginateByPageKeys($query, $criteria);
        }

        $direction = $criteria['direction'];
        $this->applySort($query, $sort, $direction);

        return $query->with(['customer', 'assignee:id,name'])
            ->orderBy('orders.id', $direction)
            ->paginate(perPage: $criteria['per_page'], page: $criteria['page']);
    }

    /**
     * @param  Builder<Order>  $query
     * @param  OrderListCriteria  $criteria
     * @return LengthAwarePaginator<int, Order>
     */
    private function paginateByPageKeys(Builder $query, array $criteria): LengthAwarePaginator
    {
        $page = $criteria['page'];
        $perPage = $criteria['per_page'];
        $direction = $criteria['direction'];
        $total = $query->toBase()->getCountForPagination();
        $orders = $query->getModel()->newCollection();

        if ($total > 0) {
            $sortColumn = 'orders.order_date';
            if ($criteria['sort'] === OrderSortField::Customer) {
                $query->join('customers as sort_customer', 'sort_customer.id', '=', 'orders.customer_id');
                $sortColumn = 'sort_customer.name';
            }

            // Apply the offset to the filtered sort keys before loading full order rows.
            $pageKeys = $query
                ->select(['orders.id', $sortColumn.' as sort_value'])
                ->orderBy($sortColumn, $direction)
                ->orderBy('orders.id', $direction)
                ->forPage($page, $perPage);

            $orders = $query->getModel()->newQuery()
                ->joinSub($pageKeys->toBase(), 'sorted_page', 'sorted_page.id', '=', 'orders.id')
                ->select('orders.*')
                ->with(['customer', 'assignee:id,name'])
                ->orderBy('sorted_page.sort_value', $direction)
                ->orderBy('orders.id', $direction)
                ->get();
        }

        return new LengthAwarePaginator($orders, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function applySearch(Builder $query, string $search, OrderCustomerContactScope $contactScope): void
    {
        $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        $query->where(static function (Builder $matches) use ($pattern, $contactScope): void {
            $matches->whereRaw("orders.order_number like ? escape '!'", [$pattern])
                ->orWhereHas('customer', static function (Builder $customer) use ($pattern, $contactScope): void {
                    $customer->where(static function (Builder $fields) use ($pattern, $contactScope): void {
                        $fields->whereRaw("customers.name like ? escape '!'", [$pattern]);

                        if ($contactScope->viewAll) {
                            $fields->orWhereRaw("customers.email like ? escape '!'", [$pattern]);
                        } elseif ($contactScope->assignedActorId !== null) {
                            $fields->orWhere(static fn (Builder $email): Builder => $email
                                ->where('orders.assignee_id', $contactScope->assignedActorId)
                                ->whereRaw("customers.email like ? escape '!'", [$pattern]));
                        }
                    });
                })
                ->orWhereHas('items', static fn (Builder $item): Builder => $item
                    ->whereRaw("(order_items.product_name like ? escape '!' or order_items.product_sku like ? escape '!')", [$pattern, $pattern]))
                ->orWhereHas('assignee', static fn (Builder $assignee): Builder => $assignee
                    ->whereRaw("users.name like ? escape '!'", [$pattern]));
        });
    }

    /**
     * @param  Builder<Order>  $query
     * @param  OrderSortField::OrderDate|OrderSortField::Total|OrderSortField::Status|OrderSortField::Priority  $field
     * @param  'asc'|'desc'  $direction
     */
    private function applySort(Builder $query, OrderSortField $field, string $direction): void
    {
        $directionSql = $direction === 'asc' ? ' asc' : ' desc';

        match ($field) {
            OrderSortField::OrderDate => $query->orderBy('orders.order_date', $direction),
            OrderSortField::Total => $query->orderBy('orders.grand_total_minor', $direction),
            OrderSortField::Status => $query->orderByRaw(
                'case orders.status when ? then 0 when ? then 1 when ? then 2 when ? then 3 '
                .'when ? then 4 when ? then 5 when ? then 6 else 7 end'.$directionSql,
                [
                    OrderStatus::Draft->value,
                    OrderStatus::Pending->value,
                    OrderStatus::Confirmed->value,
                    OrderStatus::Processing->value,
                    OrderStatus::Ready->value,
                    OrderStatus::Completed->value,
                    OrderStatus::Cancelled->value,
                ],
            ),
            OrderSortField::Priority => $query->orderByRaw(
                'case orders.priority when ? then 0 when ? then 1 when ? then 2 when ? then 3 else 4 end'.$directionSql,
                [
                    OrderPriority::Low->value,
                    OrderPriority::Normal->value,
                    OrderPriority::High->value,
                    OrderPriority::Urgent->value,
                ],
            ),
        };
    }
}
