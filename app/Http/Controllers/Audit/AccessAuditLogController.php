<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAccessAuditLogsRequest;
use App\Http\Resources\AccessAuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AccessAuditLog;
use App\Services\Audit\AccessAuditLogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;

final class AccessAuditLogController extends Controller
{
    public function __construct(
        private readonly AccessAuditLogReader $reader,
        private readonly ApiResponse $apiResponse,
    ) {}

    public function index(ListAccessAuditLogsRequest $request): JsonResponse
    {
        $logs = $this->reader->paginate($request->filters(), $request->perPage(), $request->page());

        return $this->apiResponse->success(
            message: Lang::string('api.access_audit.list_retrieved'),
            data: ['logs' => AccessAuditLogResource::collection($logs->getCollection())->resolve($request)],
            meta: [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        );
    }

    public function show(Request $request, string $accessAuditLog): JsonResponse
    {
        Gate::authorize('viewAny', AccessAuditLog::class);

        $log = $this->reader->find($accessAuditLog);

        return $this->apiResponse->success(
            message: Lang::string('api.access_audit.retrieved'),
            data: ['log' => (new AccessAuditLogResource($log))->resolve($request)],
        );
    }
}
