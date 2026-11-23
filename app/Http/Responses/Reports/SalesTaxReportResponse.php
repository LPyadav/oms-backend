<?php

declare(strict_types=1);

namespace App\Http\Responses\Reports;

use App\Http\Responses\ApiResponse;
use App\Services\Reports\SalesTaxReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;

/**
 * @phpstan-import-type SalesTaxReportFilters from SalesTaxReport
 * @phpstan-import-type SalesTaxReportResult from SalesTaxReport
 */
final readonly class SalesTaxReportResponse
{
    public function __construct(private ApiResponse $apiResponse) {}

    /**
     * @param  SalesTaxReportResult  $report
     * @param  SalesTaxReportFilters  $filters
     */
    public function orders(array $report, array $filters): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.reports.sales_tax_retrieved'),
            data: [
                'orders' => $report['orders'],
                'totals' => $report['totals'],
            ],
            meta: [
                ...$report['pagination'],
                'snapshot_at' => $report['snapshot_at'],
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'timezone' => Config::string('dashboard.timezone'),
            ],
        );
    }
}
