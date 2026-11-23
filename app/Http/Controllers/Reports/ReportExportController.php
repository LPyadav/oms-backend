<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\OrderFulfillmentReportRequest;
use App\Http\Requests\Reports\SalesTaxReportRequest;
use App\Http\Responses\CsvDownload;
use App\Services\Reports\OrderFulfillmentReport;
use App\Services\Reports\SalesTaxReport;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportExportController extends Controller
{
    public function __construct(private readonly CsvDownload $csv) {}

    public function orderFulfillment(
        OrderFulfillmentReportRequest $request,
        OrderFulfillmentReport $report,
    ): StreamedResponse {
        $limit = Config::integer('reports.export_max_rows');
        $rows = $report->exportRows($request->filters(), $limit + 1);
        $this->ensureWithinLimit(count($rows), $limit);

        return $this->csv->download('order-fulfillment.csv', [
            'Order number', 'Order date', 'Customer', 'Priority', 'Status', 'Assigned employee', 'Currency', 'Total',
        ], array_map(static fn (array $row): array => [
            CsvDownload::safeText($row['order_number']),
            $row['order_date'],
            CsvDownload::safeText($row['customer']['name']),
            $row['priority'],
            $row['status'],
            CsvDownload::safeText($row['assignee']['name'] ?? ''),
            $row['currency'],
            CsvDownload::decimalAmount($row['grand_total_minor']),
        ], $rows));
    }

    public function salesTax(SalesTaxReportRequest $request, SalesTaxReport $report): StreamedResponse
    {
        $limit = Config::integer('reports.export_max_rows');
        $rows = $report->exportRows($request->filters(), $limit + 1);
        $this->ensureWithinLimit(count($rows), $limit);

        return $this->csv->download('sales-tax.csv', [
            'Order number', 'Completed at (UTC)', 'Customer', 'Currency', 'Subtotal', 'Item discount',
            'Order discount', 'Net before tax', 'Tax', 'Total',
        ], array_map(static fn (array $row): array => [
            CsvDownload::safeText($row['order_number']),
            $row['completed_at'],
            CsvDownload::safeText($row['customer']['name']),
            $row['currency'],
            CsvDownload::decimalAmount($row['subtotal_minor']),
            CsvDownload::decimalAmount($row['item_discount_minor']),
            CsvDownload::decimalAmount($row['order_discount_minor']),
            CsvDownload::decimalAmount($row['net_total_minor']),
            CsvDownload::decimalAmount($row['tax_total_minor']),
            CsvDownload::decimalAmount($row['grand_total_minor']),
        ], $rows));
    }

    private function ensureWithinLimit(int $rows, int $limit): void
    {
        if ($rows > $limit) {
            throw ValidationException::withMessages([
                'export' => Lang::string('api.orders.export_limit', ['limit' => $limit]),
            ]);
        }
    }
}
