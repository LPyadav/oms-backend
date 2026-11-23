<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

final class ReportOrderPage
{
    /**
     * @param  Builder<Order>  $orders
     * @param  'orders.order_date'|'orders.completed_at'  $dateColumn
     * @return Builder<Order>
     */
    public function query(Builder $orders, string $dateColumn, int $page, int $perPage): Builder
    {
        $pageQuery = (clone $orders)
            ->orderByDesc($dateColumn)
            ->orderByDesc('orders.id')
            ->forPage($page, $perPage);

        if ($page === 1) {
            return $pageQuery;
        }

        // Apply the offset to the filtered keys before loading the full report rows.
        $pageKeys = $pageQuery->select(['orders.id', $dateColumn.' as sort_date']);

        return $orders->getModel()->newQuery()
            ->joinSub($pageKeys->toBase(), 'report_page', 'report_page.id', '=', 'orders.id')
            ->orderByDesc('report_page.sort_date')
            ->orderByDesc('report_page.id');
    }
}
