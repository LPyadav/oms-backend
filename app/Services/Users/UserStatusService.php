<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Exceptions\Users\LastActiveAdminException;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Audit\AccessAuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class UserStatusService
{
    public function __construct(
        private UserPolicy $userPolicy,
        private AdminUserWriteLock $writeLock,
        private AccessAuditLogWriter $auditLogWriter,
    ) {}

    public function disable(User $actor, int|string $userId): User
    {
        return $this->changeStatus($actor, $userId, false);
    }

    public function enable(User $actor, int|string $userId): User
    {
        return $this->changeStatus($actor, $userId, true);
    }

    private function changeStatus(User $actor, int|string $userId, bool $isActive): User
    {
        return DB::transaction(function () use ($actor, $userId, $isActive): User {
            ['adminRole' => $adminRole, 'actor' => $lockedActor] = $this->writeLock->acquire($actor);

            if (! $this->userPolicy->changeStatus($lockedActor)) {
                throw new AuthorizationException;
            }

            $target = User::query()
                ->lockForUpdate()
                ->findOrFail($userId);

            $wasActive = $target->is_active;
            if ($wasActive === $isActive) {
                if (! $isActive) {
                    $target->tokens()->delete();
                }

                return $target;
            }

            if (! $isActive && $target->hasRole($adminRole)) {
                $anotherAdmin = User::query()
                    ->role($adminRole)
                    ->where('is_active', true)
                    ->whereKeyNot($target->id)
                    ->lockForUpdate()
                    ->first(['users.id']);

                if ($anotherAdmin === null) {
                    throw new LastActiveAdminException;
                }
            }

            $target->is_active = $isActive;

            if (! $target->save()) {
                throw new RuntimeException('The account status could not be saved.');
            }

            $target->tokens()->delete();
            $this->auditLogWriter->record(
                $lockedActor,
                $target,
                $isActive ? 'user.enabled' : 'user.disabled',
                ['is_active' => $wasActive],
                ['is_active' => $isActive],
            );

            return $target;
        });
    }
}
