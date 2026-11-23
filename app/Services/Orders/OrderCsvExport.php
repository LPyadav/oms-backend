<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Http\Responses\CsvDownload;
use App\Models\Order;
use App\Models\User;
use Generator;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @phpstan-import-type OrderListCriteria from OrderListQuery */
final readonly class OrderCsvExport
{
    public function __construct(
        private CsvDownload $csv,
        private OrderReader $reader,
        private OrderExportRows $exportRows,
    ) {}

    /** @param OrderListCriteria $criteria */
    public function download(User $actor, array $criteria): StreamedResponse
    {
        $limit = Config::integer('orders.export_max_rows');
        $query = $this->reader->forExport($actor, $criteria)->limit($limit + 1);
        $orders = $this->exportRows->fromQuery($query);

        return $this->csv->download('orders.csv', [
            'Order number', 'Customer', 'Order date', 'Status', 'Priority', 'Assigned employee',
            'Currency', 'Subtotal', 'Discount', 'Tax', 'Total',
        ], $this->rows($orders), $limit);
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
                CsvDownload::safeText((string) $order->getAttribute('export_customer_name')),
                $order->order_date->toDateString(),
                $order->status->value,
                $order->priority->value,
                CsvDownload::safeText((string) $order->getAttribute('export_assignee_name')),
                $order->currency,
                CsvDownload::decimalAmount($order->subtotal_minor),
                CsvDownload::decimalAmount($order->discount_total_minor),
                CsvDownload::decimalAmount($order->tax_total_minor),
                CsvDownload::decimalAmount($order->grand_total_minor),
            ];
        }
    }
}
