<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\OrderFulfillmentReportRequest;
use App\Http\Responses\Reports\OrderFulfillmentReportResponse;
use App\Services\Reports\OrderFulfillmentReport;
use Illuminate\Http\JsonResponse;

final class ReportController extends Controller
{
    public function __construct(
        private readonly OrderFulfillmentReport $orderFulfillmentReport,
        private readonly OrderFulfillmentReportResponse $orderFulfillmentResponse,
    ) {}

    public function orderFulfillment(OrderFulfillmentReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return $this->orderFulfillmentResponse->orders($this->orderFulfillmentReport->generate($filters), $filters);
    }
}
