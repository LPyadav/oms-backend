<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AccessAuditLog;
use Illuminate\Pagination\LengthAwarePaginator;

final class AccessAuditLogReader
{
    /**
     * @param  array<string, int|string>  $filters
     * @return LengthAwarePaginator<int, AccessAuditLog>
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return AccessAuditLog::query()
            ->where($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    public function find(string $id): AccessAuditLog
    {
        return AccessAuditLog::query()->findOrFail($id);
    }
}
