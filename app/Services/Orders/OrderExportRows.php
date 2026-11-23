<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\LazyCollection;
use Pdo\Mysql;

final class OrderExportRows
{
    /**
     * @param  Builder<Order>  $query
     * @return LazyCollection<int, Order>
     */
    public function fromQuery(Builder $query): LazyCollection
    {
        // A single query keeps row values and names in the same snapshot.
        $rows = $query->select([
            'orders.id',
            'orders.order_number',
            'orders.customer_id',
            'orders.assignee_id',
            'orders.order_date',
            'orders.completed_at',
            'orders.status',
            'orders.priority',
            'orders.currency',
            'orders.subtotal_minor',
            'orders.discount_total_minor',
            'orders.order_discount_minor',
            'orders.tax_total_minor',
            'orders.grand_total_minor',
        ])->addSelect([
            'export_customer_name' => Customer::query()->select('customers.name')
                ->whereColumn('customers.id', 'orders.customer_id'),
            'export_assignee_name' => User::query()->select('users.name')
                ->whereColumn('users.id', 'orders.assignee_id'),
        ])->cursor();

        $baseQuery = $query->getQuery();
        $connection = $baseQuery->getConnection();
        if (! $connection instanceof MySqlConnection) {
            return $rows;
        }

        return new LazyCollection(static function () use ($rows, $connection, $baseQuery): Generator {
            $pdo = $baseQuery->useWritePdo ? $connection->getPdo() : $connection->getReadPdo();
            $buffered = $pdo->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);

            try {
                yield from $rows;
            } finally {
                $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        });
    }
}
