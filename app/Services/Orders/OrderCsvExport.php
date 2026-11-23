<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Http\Responses\CsvDownload;
use App\Models\Order;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class OrderCsvExport
{
    public function __construct(private CsvDownload $csv) {}

    /** @param iterable<Order> $orders */
    public function download(iterable $orders): StreamedResponse
    {
        return $this->csv->download('orders.csv', [
            'Order number', 'Customer', 'Order date', 'Status', 'Priority', 'Assigned employee',
            'Currency', 'Subtotal', 'Discount', 'Tax', 'Total',
        ], $this->rows($orders));
    }

    /**
     * @param  iterable<Order>  $orders
     * @return Generator<int, list<string>>
     */
    private function rows(iterable $orders): Generator
    {
        foreach ($orders as $order) {
            yield [
                CsvDownload::safeText($order->order_number),
                CsvDownload::safeText($order->customer->name ?? ''),
                $order->order_date->toDateString(),
                $order->status->value,
                $order->priority->value,
                CsvDownload::safeText($order->assignee->name ?? ''),
                $order->currency,
                CsvDownload::decimalAmount($order->subtotal_minor),
                CsvDownload::decimalAmount($order->discount_total_minor),
                CsvDownload::decimalAmount($order->tax_total_minor),
                CsvDownload::decimalAmount($order->grand_total_minor),
            ];
        }
    }
}
