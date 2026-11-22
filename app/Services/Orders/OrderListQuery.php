<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderPriority;
use App\Enums\OrderSortField;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @phpstan-type OrderListCriteria array{
 *     search: ?string,
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
    /**
     * @param  Builder<Order>  $query
     * @param  OrderListCriteria  $criteria
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(Builder $query, array $criteria): LengthAwarePaginator
    {
        if ($criteria['search'] !== null) {
            $this->applySearch($query, $criteria['search']);
        }

        $filters = [
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

        if ($criteria['date_from'] !== null) {
            $query->where('orders.order_date', '>=', $criteria['date_from']);
        }

        $dateTo = $criteria['date_to'];
        if ($dateTo !== null && $dateTo !== '9999-12-31') {
            // A half-open bound includes SQLite's timestamp-formatted date values.
            $nextDate = CarbonImmutable::parse($dateTo, 'UTC')->addDay()->toDateString();
            $query->where('orders.order_date', '<', $nextDate);
        }

        $direction = $criteria['direction'];
        $this->applySort($query, $criteria['sort'], $direction);

        return $query->with(['customer', 'assignee:id,name'])
            ->orderBy('orders.id', $direction)
            ->paginate(perPage: $criteria['per_page'], page: $criteria['page']);
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        $query->where(static function (Builder $matches) use ($pattern): void {
            $matches->whereRaw("orders.order_number like ? escape '!'", [$pattern])
                ->orWhereHas('customer', static fn (Builder $customer): Builder => $customer
                    ->whereRaw("(customers.name like ? escape '!' or customers.email like ? escape '!')", [$pattern, $pattern]))
                ->orWhereHas('items', static fn (Builder $item): Builder => $item
                    ->whereRaw("(order_items.product_name like ? escape '!' or order_items.product_sku like ? escape '!')", [$pattern, $pattern]))
                ->orWhereHas('assignee', static fn (Builder $assignee): Builder => $assignee
                    ->whereRaw("users.name like ? escape '!'", [$pattern]));
        });
    }

    /**
     * @param  Builder<Order>  $query
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
            OrderSortField::Customer => $query->orderBy(
                Customer::query()->select('customers.name')->whereColumn('customers.id', 'orders.customer_id'),
                $direction,
            ),
        };
    }
}
