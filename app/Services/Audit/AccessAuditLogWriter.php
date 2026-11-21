<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AccessAuditLog;
use App\Models\User;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;

final class AccessAuditLogWriter
{
    /**
     * @param  array<string, mixed>|null  $beforeValues
     * @param  array<string, mixed>  $afterValues
     */
    public function record(
        User $actor,
        User|Role $target,
        string $action,
        ?array $beforeValues,
        array $afterValues,
    ): void {
        $actorId = $actor->getKey();
        $targetId = $target->getKey();

        if (! $actor->exists || $actorId === null || ! $target->exists || $targetId === null) {
            throw new LogicException('An access audit entry requires a persisted actor and target.');
        }

        $connection = $target->getConnection();

        if ($actor->getConnection() !== $connection || $connection->transactionLevel() === 0) {
            throw new LogicException('Access audit entries must be written in the active transaction for the access change.');
        }

        $log = new AccessAuditLog([
            'actor_id' => $actorId,
            'target_type' => $target instanceof User ? 'user' : 'role',
            'target_id' => $targetId,
            'action' => $action,
            'before_values' => $beforeValues,
            'after_values' => $afterValues,
        ]);
        $log->setConnection($connection->getName());

        if ($log->getConnection() !== $connection) {
            throw new LogicException('The access audit log must use the same database connection as the access change.');
        }

        if (! $log->save()) {
            throw new RuntimeException('The access audit entry could not be saved.');
        }
    }
}
