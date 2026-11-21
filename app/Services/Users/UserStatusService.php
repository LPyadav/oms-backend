<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Exceptions\Users\LastActiveAdminException;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class UserStatusService
{
    public function __construct(
        private UserPolicy $userPolicy,
        private AdminUserWriteLock $writeLock,
    ) {}

    public function disable(User $actor, int|string $userId): User
    {
        return DB::transaction(function () use ($actor, $userId): User {
            ['adminRole' => $adminRole, 'actor' => $lockedActor] = $this->writeLock->acquire($actor);

            if (! $this->userPolicy->changeStatus($lockedActor)) {
                throw new AuthorizationException;
            }

            $target = User::query()
                ->lockForUpdate()
                ->findOrFail($userId);

            if ($target->is_active && $target->hasRole($adminRole)) {
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

            $target->is_active = false;

            if (! $target->save()) {
                throw new RuntimeException('The account status could not be saved.');
            }

            $target->tokens()->delete();

            return $target;
        });
    }
}
