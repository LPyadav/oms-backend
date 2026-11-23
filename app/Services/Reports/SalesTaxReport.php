<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * @phpstan-type SalesTaxReportFilters array{
 *     date_from: string,
 *     date_to: string,
 *     customer_id: ?int,
 *     product_id: ?int,
 *     page: int,
 *     per_page: int
 * }
 * @phpstan-type SalesTaxReportRow array{
 *     id: int,
 *     order_number: string,
 *     completed_at: string,
 *     customer: array{id: int, name: string},
 *     currency: string,
 *     subtotal_minor: int,
 *     item_discount_minor: int,
 *     order_discount_minor: int,
 *     net_total_minor: int,
 *     tax_total_minor: int,
 *     grand_total_minor: int
 * }
 * @phpstan-type SalesTaxCurrencyTotal array{
 *     currency: string,
 *     order_count: int,
 *     subtotal_minor: string,
 *     item_discount_minor: string,
 *     order_discount_minor: string,
 *     net_total_minor: string,
 *     tax_total_minor: string,
 *     grand_total_minor: string
 * }
 * @phpstan-type SalesTaxReportTotals array{order_count: int, by_currency: list<SalesTaxCurrencyTotal>}
 * @phpstan-type SalesTaxReportResult array{
 *     orders: list<SalesTaxReportRow>,
 *     totals: SalesTaxReportTotals,
 *     pagination: array{current_page: int, per_page: int, last_page: int, total: int},
 *     snapshot_at: string
 * }
 */
final readonly class SalesTaxReport
{
    private const string PRODUCT_TOTALS_INDEXES = 'PRIMARY,orders_status_deleted_at_completed_at_index';

    private const array MONEY_COLUMNS = [
        'subtotal_minor',
        'discount_total_minor',
        'order_discount_minor',
        'tax_total_minor',
        'grand_total_minor',
    ];

    public function __construct(
        private OrderReportTotals $reportTotals,
        private OrderCompletionDateFilter $completionDates,
        private ReportOrderPage $orderPage,
    ) {}

    /**
     * @param  SalesTaxReportFilters  $filters
     * @return SalesTaxReportResult
     */
    public function generate(array $filters): array
    {
        return DB::transaction(function () use ($filters): array {
            $query = $this->filteredOrders($filters);
            // The following aggregate read establishes the MySQL repeatable-read snapshot.
            $snapshotAt = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z');
            $totals = $this->totals($query, $filters);
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
     * @param  SalesTaxReportFilters  $filters
     * @return list<SalesTaxReportRow>
     */
    public function exportRows(array $filters, int $maximumRows): array
    {
        return DB::transaction(fn (): array => $this->page($this->filteredOrders($filters), 1, $maximumRows));
    }

    /**
     * @param  SalesTaxReportFilters  $filters
     * @return Builder<Order>
     */
    private function filteredOrders(array $filters): Builder
    {
        $timezone = new DateTimeZone(Config::string('dashboard.timezone'));
        $from = CarbonImmutable::parse($filters['date_from'], 'UTC');
        $until = CarbonImmutable::parse($filters['date_to'], 'UTC')->addDay();

        $query = Order::query()->where('orders.status', OrderStatus::Completed->value);
        $this->completionDates->apply($query->getQuery(), $from, $until, $timezone);

        if ($filters['customer_id'] !== null) {
            $query->where('orders.customer_id', $filters['customer_id']);
        }

        $productId = $filters['product_id'];
        if ($productId !== null) {
            $query->whereExists(static function (QueryBuilder $items) use ($productId): void {
                $items->selectRaw('1')
                    ->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->where('order_items.product_id', $productId);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Order>  $query
     * @param  SalesTaxReportFilters  $filters
     * @return SalesTaxReportTotals
     */
    private function totals(Builder $query, array $filters): array
    {
        if ($filters['product_id'] !== null
            && $filters['customer_id'] === null
            && $query->getQuery()->getConnection() instanceof MySqlConnection
        ) {
            $query = clone $query;
            // The completion index supports range scans and PRIMARY supports product-first lookups.
            $query->getQuery()->useIndex(self::PRODUCT_TOTALS_INDEXES);
        }

        $totals = $this->reportTotals->calculate($query, self::MONEY_COLUMNS);
        $byCurrency = [];
        foreach ($totals['by_currency'] as $total) {
            $amounts = $total['amounts'];
            $byCurrency[] = [
                'currency' => $total['currency'],
                'order_count' => $total['order_count'],
                'subtotal_minor' => $amounts['subtotal_minor']->toString(),
                'item_discount_minor' => $amounts['discount_total_minor']->minus($amounts['order_discount_minor'])->toString(),
                'order_discount_minor' => $amounts['order_discount_minor']->toString(),
                'net_total_minor' => $amounts['subtotal_minor']->minus($amounts['discount_total_minor'])->toString(),
                'tax_total_minor' => $amounts['tax_total_minor']->toString(),
                'grand_total_minor' => $amounts['grand_total_minor']->toString(),
            ];
        }

        return ['order_count' => $totals['order_count'], 'by_currency' => $byCurrency];
    }

    /**
     * @param  Builder<Order>  $query
     * @return list<SalesTaxReportRow>
     */
    private function page(Builder $query, int $page, int $perPage): array
    {
        $orders = $this->orderPage->query($query, 'orders.completed_at', $page, $perPage)
            ->with('customer:id,name')
            ->get([
                'orders.id',
                'orders.order_number',
                'orders.customer_id',
                'orders.completed_at',
                'orders.currency',
                'orders.subtotal_minor',
                'orders.discount_total_minor',
                'orders.order_discount_minor',
                'orders.tax_total_minor',
                'orders.grand_total_minor',
            ]);

        $rows = [];
        foreach ($orders as $order) {
            $customer = $order->customer;
            $completedAt = $order->completed_at;
            if ($customer === null || $completedAt === null) {
                throw new UnexpectedValueException('The sales and tax report query returned an incomplete order.');
            }

            $rows[] = [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'completed_at' => $completedAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'customer' => ['id' => $customer->id, 'name' => $customer->name],
                'currency' => $order->currency,
                'subtotal_minor' => $order->subtotal_minor,
                'item_discount_minor' => $order->discount_total_minor - $order->order_discount_minor,
                'order_discount_minor' => $order->order_discount_minor,
                'net_total_minor' => $order->subtotal_minor - $order->discount_total_minor,
                'tax_total_minor' => $order->tax_total_minor,
                'grand_total_minor' => $order->grand_total_minor,
            ];
        }

        return $rows;
    }
}
