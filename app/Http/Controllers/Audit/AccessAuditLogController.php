<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAccessAuditLogsRequest;
use App\Http\Responses\Audit\AuditLogResponse;
use App\Models\AccessAuditLog;
use App\Services\Audit\AccessAuditLogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AccessAuditLogController extends Controller
{
    public function __construct(
        private readonly AccessAuditLogReader $reader,
        private readonly AuditLogResponse $response,
    ) {}

    public function index(ListAccessAuditLogsRequest $request): JsonResponse
    {
        $logs = $this->reader->paginate($request->filters(), $request->perPage(), $request->page());

        return $this->response->listAccessLogs($logs, $request);
    }

    public function show(Request $request, string $accessAuditLog): JsonResponse
    {
        Gate::authorize('viewAny', AccessAuditLog::class);

        return $this->response->showAccessLog($this->reader->find($accessAuditLog), $request);
    }
}
