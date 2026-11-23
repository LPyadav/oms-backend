<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\DashboardRevenueGroup;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Reports\OrderCompletionDateFilter;
use App\Services\Reports\OrderReportTotals;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * @phpstan-type FinancialMetric array{currency: string, completed_orders: int, total_revenue_minor: string, average_order_value_minor: int}
 * @phpstan-type RecentOrder array{id: int, order_number: string, order_date: string, status: string, priority: string, currency: string, grand_total_minor: int}
 * @phpstan-type DashboardOverview array{total_orders: int, orders_by_status: array<string, int>, total_customers: int|null, total_products: int|null, financial_metrics: list<FinancialMetric>, recent_orders: list<RecentOrder>}
 * @phpstan-type Bucket array{start: CarbonImmutable, end: CarbonImmutable}
 * @phpstan-type BucketTotals array{revenue_minor: string, completed_orders: int}
 * @phpstan-type TrendPoint array{period_start: string, period_end: string, revenue_minor: string, completed_orders: int}
 * @phpstan-type DashboardRevenueTrend array{group_by: string, timezone: string, date_from: string, date_to: string, series: list<array{currency: string, points: list<TrendPoint>}>}
 */
final readonly class DashboardService
{
    private const int MAX_BUCKET_QUERY_BINDINGS = 999;

    public function __construct(
        private OrderReportTotals $reportTotals,
        private OrderCompletionDateFilter $completionDates,
    ) {}

    /**
     * @return DashboardOverview
     */
    public function overview(bool $canViewCustomers, bool $canViewProducts): array
    {
        return DB::transaction(function () use ($canViewCustomers, $canViewProducts): array {
            $counts = $this->countsByStatus();

            return [
                'total_orders' => array_sum($counts),
                'orders_by_status' => $counts,
                'total_customers' => $canViewCustomers ? Customer::query()->count() : null,
                'total_products' => $canViewProducts ? Product::query()->count() : null,
                'financial_metrics' => $this->financialMetrics(),
                'recent_orders' => $this->recentOrders(),
            ];
        });
    }

    /**
     * The dates are UTC midnights representing calendar dates in the reporting time zone.
     *
     * @return DashboardRevenueTrend
     */
    public function revenueTrend(DashboardRevenueGroup $group, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = Config::string('dashboard.timezone');
        $buckets = $this->buckets($group, $from, $to->addDay());

        return DB::transaction(fn (): array => [
            'group_by' => $group->value,
            'timezone' => $timezone,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'series' => $this->trendSeries($buckets, new DateTimeZone($timezone)),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function countsByStatus(): array
    {
        $counts = [];
        foreach (OrderStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $rows = DB::table('orders')
            ->whereNull('deleted_at')
            ->select('status')
            ->selectRaw('COUNT(*) AS order_count')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            if (! is_string($row->status) || ! array_key_exists($row->status, $counts)) {
                throw new UnexpectedValueException('The dashboard query returned an unknown order status.');
            }

            $counts[$row->status] = $this->aggregateInteger($row->order_count);
        }

        return $counts;
    }

    /**
     * @return list<FinancialMetric>
     */
    private function financialMetrics(): array
    {
        $totals = $this->reportTotals->calculate($this->completedOrders(), ['grand_total_minor']);
        $metrics = [];
        foreach ($totals['by_currency'] as $total) {
            $revenue = $total['amounts']['grand_total_minor'];
            $count = $total['order_count'];

            $metrics[] = [
                'currency' => $total['currency'],
                'completed_orders' => $count,
                'total_revenue_minor' => $revenue->toString(),
                'average_order_value_minor' => $revenue->dividedBy($count, RoundingMode::HalfUp)->toInt(),
            ];
        }

        if ($metrics === []) {
            return [[
                'currency' => Config::string('orders.currency'),
                'completed_orders' => 0,
                'total_revenue_minor' => '0',
                'average_order_value_minor' => 0,
            ]];
        }

        return $metrics;
    }

    /**
     * @return list<RecentOrder>
     */
    private function recentOrders(): array
    {
        $orders = Order::query()
            ->select(['id', 'order_number', 'order_date', 'status', 'priority', 'currency', 'grand_total_minor'])
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(Config::integer('dashboard.recent_orders_limit'))
            ->get();

        $recent = [];
        foreach ($orders as $order) {
            $recent[] = [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_date' => $order->order_date->toDateString(),
                'status' => $order->status->value,
                'priority' => $order->priority->value,
                'currency' => $order->currency,
                'grand_total_minor' => $order->grand_total_minor,
            ];
        }

        return $recent;
    }

    /**
     * @return list<Bucket>
     */
    private function buckets(DashboardRevenueGroup $group, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $buckets = [];
        for ($start = $group->startOfPeriod($from); $start->lessThan($until); $start = $next) {
            $next = $group->nextPeriodStart($start);
            $buckets[] = [
                'start' => $start->max($from),
                'end' => $next->min($until),
            ];
        }

        return $buckets;
    }

    /**
     * @param  list<Bucket>  $buckets
     * @return DashboardRevenueTrend['series']
     */
    private function trendSeries(array $buckets, DateTimeZone $timezone): array
    {
        /** @var array<string, array<int, BucketTotals>> $totals */
        $totals = [];

        foreach ($this->bucketQueries($buckets, $timezone) as $query) {
            foreach ($query->get() as $row) {
                $currency = $this->currencyCode($row->currency);
                $index = $this->aggregateInteger($row->bucket_index);
                $totals[$currency][$index] = [
                    'revenue_minor' => $this->reportTotals->readAmountSum($query, $row, 'grand_total_minor')->toString(),
                    'completed_orders' => $this->aggregateInteger($row->completed_orders),
                ];
            }
        }

        if ($totals === []) {
            $totals[Config::string('orders.currency')] = [];
        }

        ksort($totals, SORT_STRING);

        $series = [];
        foreach ($totals as $currency => $currencyTotals) {
            $points = [];
            foreach ($buckets as $index => $bucket) {
                $points[] = [
                    'period_start' => $bucket['start']->toDateString(),
                    'period_end' => $bucket['end']->subDay()->toDateString(),
                    ...($currencyTotals[$index] ?? ['revenue_minor' => '0', 'completed_orders' => 0]),
                ];
            }

            $series[] = ['currency' => $currency, 'points' => $points];
        }

        return $series;
    }

    /**
     * @param  list<Bucket>  $buckets
     * @return iterable<QueryBuilder>
     */
    private function bucketQueries(array $buckets, DateTimeZone $timezone): iterable
    {
        $query = null;
        $bindings = 0;
        foreach ($buckets as $index => $bucket) {
            $range = $this->completedOrders()->toBase()
                ->select('currency')
                ->selectRaw('? AS bucket_index, COUNT(*) AS completed_orders', [$index])
                ->groupBy('currency');
            $this->completionDates->apply($range, $bucket['start'], $bucket['end'], $timezone);
            $this->reportTotals->selectAmountSums($range, ['grand_total_minor']);

            // Historical transitions can add extra bounds to a bucket. Count the actual SQL bindings.
            $rangeBindings = count($range->getBindings());
            if ($query !== null && $bindings + $rangeBindings > self::MAX_BUCKET_QUERY_BINDINGS) {
                yield $query;
                $query = null;
                $bindings = 0;
            }

            $query = $query === null ? $range : $query->unionAll($range);
            $bindings += $rangeBindings;
        }

        if ($query !== null) {
            yield $query;
        }
    }

    /** @return Builder<Order> */
    private function completedOrders(): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::Completed->value)
            ->whereNotNull('completed_at');
    }

    private function aggregateInteger(mixed $value): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new UnexpectedValueException('The dashboard query returned a non-integer aggregate.');
        }

        return BigInteger::of($value)->toInt();
    }

    private function currencyCode(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('The dashboard query returned an invalid currency.');
        }

        return $value;
    }
}
