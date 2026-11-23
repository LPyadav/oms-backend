<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

final class OperationalAuditLogReader
{
    /**
     * @param  array<string, int|string>  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = $this->operationalLogs()->where(Arr::except($filters, ['date_from', 'date_to']));

        if (is_string($dateFrom = $filters['date_from'] ?? null)) {
            $query->where('created_at', '>=', $dateFrom.' 00:00:00');
        }

        if (is_string($dateTo = $filters['date_to'] ?? null)) {
            $query->where('created_at', '<=', $dateTo.' 23:59:59');
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: $perPage,
                columns: ['id', 'actor_id', 'target_type', 'target_id', 'action', 'created_at'],
                page: $page,
            );
    }

    public function find(string $id): AuditLog
    {
        return $this->operationalLogs()->findOrFail($id);
    }

    /** @return Builder<AuditLog> */
    private function operationalLogs(): Builder
    {
        return AuditLog::query()
            ->whereIn('target_type', AuditLog::OPERATIONAL_TARGETS)
            ->with('actor:id,name');
    }
}
