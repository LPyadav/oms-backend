<?php

declare(strict_types=1);

namespace App\Http\Responses\Dashboard;

use App\Http\Responses\ApiResponse;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;

/**
 * @phpstan-import-type DashboardOverview from DashboardService
 * @phpstan-import-type DashboardRevenueTrend from DashboardService
 */
final readonly class DashboardResponse
{
    public function __construct(private ApiResponse $apiResponse) {}

    /** @param DashboardOverview $overview */
    public function overview(array $overview): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.dashboard.overview_retrieved'),
            data: $overview,
        );
    }

    /** @param DashboardRevenueTrend $trend */
    public function trend(array $trend): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.dashboard.revenue_trend_retrieved'),
            data: $trend,
        );
    }
}
