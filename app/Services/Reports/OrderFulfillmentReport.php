<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * @phpstan-type OrderFulfillmentFilters array{
 *     date_from: string,
 *     date_to: string,
 *     status: ?OrderStatus,
 *     customer_id: ?int,
 *     assignee_id: ?int,
 *     page: int,
 *     per_page: int
 * }
 * @phpstan-type OrderFulfillmentLabel array{id: int, name: string}
 * @phpstan-type OrderFulfillmentRow array{
 *     id: int,
 *     order_number: string,
 *     order_date: string,
 *     customer: OrderFulfillmentLabel,
 *     priority: string,
 *     status: string,
 *     assignee: ?OrderFulfillmentLabel,
 *     currency: string,
 *     grand_total_minor: int
 * }
 * @phpstan-type OrderFulfillmentCurrencyTotal array{currency: string, order_count: int, grand_total_minor: string}
 * @phpstan-type OrderFulfillmentTotals array{order_count: int, by_currency: list<OrderFulfillmentCurrencyTotal>}
 * @phpstan-type OrderFulfillmentResult array{
 *     orders: list<OrderFulfillmentRow>,
 *     totals: OrderFulfillmentTotals,
 *     pagination: array{current_page: int, per_page: int, last_page: int, total: int},
 *     snapshot_at: string
 * }
 */
final readonly class OrderFulfillmentReport
{
    public function __construct(
        private OrderReportTotals $reportTotals,
        private ReportOrderPage $orderPage,
    ) {}

    /**
     * @param  OrderFulfillmentFilters  $filters
     * @return OrderFulfillmentResult
     */
    public function generate(array $filters): array
    {
        // The first consistent read establishes the snapshot under the MySQL connection's repeatable-read isolation.
        return DB::transaction(function () use ($filters): array {
            $query = $this->filteredOrders($filters);
            $snapshotAt = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z');
            $totals = $this->totals($query);
            $page = $filters['page'];
            $perPage = $filters['per_page'];
            $orderCount = $totals['order_count'];
            $lastPage = $orderCount === 0 ? 1 : intdiv($orderCount - 1, $perPage) + 1;

            return [
                'orders' => $orderCount === 0 || $page > $lastPage ? [] : $this->page($query, $page, $perPage),
                'totals' => $totals,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'last_page' => $lastPage,
                    'total' => $orderCount,
                ],
                'snapshot_at' => $snapshotAt,
            ];
        });
    }

    /**
     * @param  OrderFulfillmentFilters  $filters
     * @return Builder<Order>
     */
    private function filteredOrders(array $filters): Builder
    {
        $query = Order::query()->where('orders.order_date', '>=', $filters['date_from']);

        if ($filters['date_to'] !== '9999-12-31') {
            // A half-open bound includes SQLite's timestamp-formatted business dates on the final day.
            $until = CarbonImmutable::parse($filters['date_to'], 'UTC')->addDay()->toDateString();
            $query->where('orders.order_date', '<', $until);
        }

        foreach ([
            'orders.status' => $filters['status']?->value,
            'orders.customer_id' => $filters['customer_id'],
            'orders.assignee_id' => $filters['assignee_id'],
        ] as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    /**
     * @param  Builder<Order>  $query
     * @return OrderFulfillmentTotals
     */
    private function totals(Builder $query): array
    {
        $totals = $this->reportTotals->calculate($query, ['grand_total_minor']);
        $byCurrency = [];
        foreach ($totals['by_currency'] as $total) {
            $byCurrency[] = [
                'currency' => $total['currency'],
                'order_count' => $total['order_count'],
                'grand_total_minor' => $total['amounts']['grand_total_minor']->toString(),
            ];
        }

        return ['order_count' => $totals['order_count'], 'by_currency' => $byCurrency];
    }

    /**
     * @param  Builder<Order>  $query
     * @return list<OrderFulfillmentRow>
     */
    private function page(Builder $query, int $page, int $perPage): array
    {
        $orders = $this->orderPage->query($query, 'orders.order_date', $page, $perPage)
            ->with(['customer:id,name', 'assignee:id,name'])
            ->get([
                'orders.id',
                'orders.order_number',
                'orders.order_date',
                'orders.customer_id',
                'orders.assignee_id',
                'orders.priority',
                'orders.status',
                'orders.currency',
                'orders.grand_total_minor',
            ]);

        $rows = [];
        foreach ($orders as $order) {
            $customer = $order->customer;
            $assignee = $order->assignee;
            if ($customer === null) {
                throw new UnexpectedValueException('The fulfillment report query returned an order without a customer.');
            }

            $rows[] = [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_date' => $order->order_date->toDateString(),
                'customer' => ['id' => $customer->id, 'name' => $customer->name],
                'priority' => $order->priority->value,
                'status' => $order->status->value,
                'assignee' => $assignee === null ? null : ['id' => $assignee->id, 'name' => $assignee->name],
                'currency' => $order->currency,
                'grand_total_minor' => $order->grand_total_minor,
            ];
        }

        return $rows;
    }
}
