<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListOperationalAuditLogsRequest;
use App\Http\Resources\OperationalAuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;

final class OperationalAuditController extends Controller
{
    public function __construct(private readonly ApiResponse $response) {}

    public function index(ListOperationalAuditLogsRequest $request): JsonResponse
    {
        $criteria = $request->validated();
        $filters = array_filter(
            Arr::only($criteria, ['action', 'actor_id', 'target_type', 'target_id']),
            static fn (mixed $value): bool => $value !== null,
        );

        $query = $this->operationalLogs()->where($filters);
        if (is_string($from = $criteria['date_from'] ?? null)) {
            $query->where('created_at', '>=', $from.' 00:00:00');
        }
        if (is_string($to = $criteria['date_to'] ?? null)) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(
            perPage: $request->perPage(),
            columns: ['id', 'actor_id', 'target_type', 'target_id', 'action', 'created_at'],
            page: $request->page(),
        );

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

        $log = $this->operationalLogs()->findOrFail($auditLog);

        return $this->response->success(
            message: Lang::string('api.operational_audit.retrieved'),
            data: ['log' => (new OperationalAuditLogResource($log))->detail($request)],
        );
    }

    /**
     * @return Builder<AuditLog>
     */
    private function operationalLogs(): Builder
    {
        return AuditLog::query()->whereIn('target_type', AuditLog::OPERATIONAL_TARGETS)->with('actor:id,name');
    }
}
