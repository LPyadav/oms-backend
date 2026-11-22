<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RuntimeException;

final class AuditLogWriter
{
    /**
     * @param  array<string, mixed>|null  $beforeValues
     * @param  array<string, mixed>  $afterValues
     */
    public function record(
        User $actor,
        Model $target,
        string $targetType,
        string $action,
        ?array $beforeValues,
        array $afterValues,
    ): void {
        $actorId = $actor->getKey();
        $targetId = $target->getKey();

        if (! $actor->exists || $actorId === null || ! $target->exists || $targetId === null) {
            throw new LogicException('An audit entry requires a persisted actor and target.');
        }

        $connection = $target->getConnection();

        if ($actor->getConnection() !== $connection || $connection->transactionLevel() === 0) {
            throw new LogicException('Audit entries must be written in the active transaction for the change.');
        }

        $log = new AuditLog([
            'actor_id' => $actorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'before_values' => $beforeValues,
            'after_values' => $afterValues,
        ]);
        $log->setConnection($connection->getName());

        if ($log->getConnection() !== $connection) {
            throw new LogicException('The audit log must use the same database connection as the change.');
        }

        if (! $log->save()) {
            throw new RuntimeException('The audit entry could not be saved.');
        }
    }
}
