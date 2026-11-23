<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\OrderFulfillmentReportRequest;
use App\Http\Requests\Reports\SalesTaxReportRequest;
use App\Http\Responses\Reports\OrderFulfillmentReportResponse;
use App\Http\Responses\Reports\SalesTaxReportResponse;
use App\Services\Reports\OrderFulfillmentReport;
use App\Services\Reports\SalesTaxReport;
use Illuminate\Http\JsonResponse;

final class ReportController extends Controller
{
    public function __construct(
        private readonly OrderFulfillmentReport $orderFulfillmentReport,
        private readonly OrderFulfillmentReportResponse $orderFulfillmentResponse,
        private readonly SalesTaxReport $salesTaxReport,
        private readonly SalesTaxReportResponse $salesTaxResponse,
    ) {}

    public function orderFulfillment(OrderFulfillmentReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return $this->orderFulfillmentResponse->orders($this->orderFulfillmentReport->generate($filters), $filters);
    }

    public function salesTax(SalesTaxReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return $this->salesTaxResponse->orders($this->salesTaxReport->generate($filters), $filters);
    }
}
