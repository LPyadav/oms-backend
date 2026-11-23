<?php

declare(strict_types=1);

namespace App\Http\Responses\Audit;

use App\Http\Resources\AccessAuditLogResource;
use App\Http\Resources\OperationalAuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AccessAuditLog;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;

final readonly class AuditLogResponse
{
    public function __construct(private ApiResponse $apiResponse) {}

    /**
     * @param  LengthAwarePaginator<int, AccessAuditLog>  $logs
     */
    public function listAccessLogs(LengthAwarePaginator $logs, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.access_audit.list_retrieved'),
            data: ['logs' => AccessAuditLogResource::collection($logs->getCollection())->resolve($request)],
            meta: $this->paginationMeta($logs),
        );
    }

    public function showAccessLog(AccessAuditLog $log, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.access_audit.retrieved'),
            data: ['log' => (new AccessAuditLogResource($log))->resolve($request)],
        );
    }

    /**
     * @param  LengthAwarePaginator<int, AuditLog>  $logs
     */
    public function listOperationalLogs(LengthAwarePaginator $logs, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.operational_audit.list_retrieved'),
            data: ['logs' => OperationalAuditLogResource::collection($logs->getCollection())->resolve($request)],
            meta: $this->paginationMeta($logs),
        );
    }

    public function showOperationalLog(AuditLog $log, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.operational_audit.retrieved'),
            data: ['log' => (new OperationalAuditLogResource($log))->detail($request)],
        );
    }

    /**
     * @template TLog of AccessAuditLog|AuditLog
     *
     * @param  LengthAwarePaginator<int, TLog>  $logs
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    private function paginationMeta(LengthAwarePaginator $logs): array
    {
        return [
            'current_page' => $logs->currentPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
            'last_page' => $logs->lastPage(),
        ];
    }
}
