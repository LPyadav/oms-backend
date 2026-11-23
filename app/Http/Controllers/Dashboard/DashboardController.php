<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardRequest;
use App\Http\Requests\Dashboard\DashboardRevenueTrendRequest;
use App\Http\Responses\Dashboard\DashboardResponse;
use App\Policies\DashboardPolicy;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly DashboardPolicy $policy,
        private readonly DashboardResponse $response,
    ) {}

    public function show(DashboardRequest $request): JsonResponse
    {
        $actor = $request->actor();

        return $this->response->overview($this->dashboard->overview(
            $this->policy->viewCustomerTotals($actor),
            $this->policy->viewProductTotals($actor),
        ));
    }

    public function revenueTrend(DashboardRevenueTrendRequest $request): JsonResponse
    {
        [$from, $to] = $request->dateRange();

        return $this->response->trend($this->dashboard->revenueTrend($request->group(), $from, $to));
    }
}
