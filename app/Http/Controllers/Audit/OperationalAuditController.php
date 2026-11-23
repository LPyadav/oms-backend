<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListOperationalAuditLogsRequest;
use App\Http\Responses\Audit\AuditLogResponse;
use App\Models\AuditLog;
use App\Services\Audit\OperationalAuditLogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OperationalAuditController extends Controller
{
    public function __construct(
        private readonly OperationalAuditLogReader $reader,
        private readonly AuditLogResponse $response,
    ) {}

    public function index(ListOperationalAuditLogsRequest $request): JsonResponse
    {
        $logs = $this->reader->paginate($request->filters(), $request->perPage(), $request->page());

        return $this->response->listOperationalLogs($logs, $request);
    }

    public function show(Request $request, string $auditLog): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        return $this->response->showOperationalLog($this->reader->find($auditLog), $request);
    }
}
