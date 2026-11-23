<?php

declare(strict_types=1);

namespace App\Http\Responses\Reports;

use App\Http\Responses\ApiResponse;
use App\Services\Reports\OrderFulfillmentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;

/**
 * @phpstan-import-type OrderFulfillmentFilters from OrderFulfillmentReport
 * @phpstan-import-type OrderFulfillmentResult from OrderFulfillmentReport
 */
final readonly class OrderFulfillmentReportResponse
{
    public function __construct(private ApiResponse $apiResponse) {}

    /**
     * @param  OrderFulfillmentResult  $report
     * @param  OrderFulfillmentFilters  $filters
     */
    public function orders(array $report, array $filters): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.reports.order_fulfillment_retrieved'),
            data: [
                'orders' => $report['orders'],
                'totals' => $report['totals'],
            ],
            meta: [
                ...$report['pagination'],
                'snapshot_at' => $report['snapshot_at'],
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
            ],
        );
    }
}
