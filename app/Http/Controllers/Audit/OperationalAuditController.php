<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListOperationalAuditLogsRequest;
use App\Http\Resources\OperationalAuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Services\Audit\OperationalAuditLogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;

final class OperationalAuditController extends Controller
{
    public function __construct(
        private readonly OperationalAuditLogReader $reader,
        private readonly ApiResponse $response,
    ) {}

    public function index(ListOperationalAuditLogsRequest $request): JsonResponse
    {
        $logs = $this->reader->paginate($request->filters(), $request->perPage(), $request->page());

        return $this->response->success(
            message: Lang::string('api.operational_audit.list_retrieved'),
            data: ['logs' => OperationalAuditLogResource::collection($logs->getCollection())->resolve($request)],
            meta: [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        );
    }

    public function show(Request $request, string $auditLog): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $log = $this->reader->find($auditLog);

        return $this->response->success(
            message: Lang::string('api.operational_audit.retrieved'),
            data: ['log' => (new OperationalAuditLogResource($log))->detail($request)],
        );
    }
}
