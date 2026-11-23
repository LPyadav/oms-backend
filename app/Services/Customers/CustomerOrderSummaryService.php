<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Reports\OrderReportTotals;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type RecentOrder array{id: int, order_number: string, order_date: string, status: string, currency: string, grand_total_minor: int}
 * @phpstan-type CurrencyTotal array{currency: string, total_minor: string}
 * @phpstan-type OrderSummary array{
 *     total_orders: int,
 *     pending_orders: int,
 *     completed_orders: int,
 *     cancelled_orders: int,
 *     total_value_by_currency: list<CurrencyTotal>,
 *     recent_orders: list<RecentOrder>,
 * }
 */
final readonly class CustomerOrderSummaryService
{
    private const int RECENT_ORDER_LIMIT = 5;

    public function __construct(
        private CustomerService $customers,
        private OrderService $orders,
        private OrderReportTotals $reportTotals,
    ) {}

    /**
     * @return OrderSummary
     */
    public function summarize(User $actor, string $customerId): array
    {
        return DB::transaction(function () use ($actor, $customerId): array {
            $orders = $this->orders->visibleOrders($actor);
            $customer = $this->customers->find($actor, $customerId);
            $orders->where('customer_id', $customer->id);

            $totalsQuery = (clone $orders)->toBase()
                ->select('currency')
                ->selectRaw('COUNT(*) AS total_orders')
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_orders', [OrderStatus::Pending->value])
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_orders', [OrderStatus::Completed->value])
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled_orders', [OrderStatus::Cancelled->value])
                ->groupBy('currency')
                ->orderBy('currency');
            $this->reportTotals->selectAmountSums($totalsQuery, ['grand_total_minor']);

            /** @var Collection<int, stdClass&object{currency: string, total_orders: int|string, pending_orders: int|string, completed_orders: int|string, cancelled_orders: int|string}> $totals */
            $totals = $totalsQuery->get();

            $summary = [
                'total_orders' => 0,
                'pending_orders' => 0,
                'completed_orders' => 0,
                'cancelled_orders' => 0,
                'total_value_by_currency' => [],
            ];

            foreach ($totals as $total) {
                $summary['total_orders'] += (int) $total->total_orders;
                $summary['pending_orders'] += (int) $total->pending_orders;
                $summary['completed_orders'] += (int) $total->completed_orders;
                $summary['cancelled_orders'] += (int) $total->cancelled_orders;
                $summary['total_value_by_currency'][] = [
                    'currency' => $total->currency,
                    'total_minor' => $this->reportTotals->readAmountSum($totalsQuery, $total, 'grand_total_minor')->toString(),
                ];
            }

            $recentOrders = $orders->orderByDesc('order_date')
                ->orderByDesc('id')
                ->limit(self::RECENT_ORDER_LIMIT)
                ->get(['id', 'order_number', 'order_date', 'status', 'currency', 'grand_total_minor'])
                ->map(static fn (Order $order): array => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_date' => $order->order_date->toDateString(),
                    'status' => $order->status->value,
                    'currency' => $order->currency,
                    'grand_total_minor' => $order->grand_total_minor,
                ])
                ->values()
                ->all();

            return [...$summary, 'recent_orders' => array_values($recentOrders)];
        });
    }
}
