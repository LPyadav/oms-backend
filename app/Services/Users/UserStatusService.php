<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Exceptions\Users\LastActiveAdminException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

final class UserStatusService
{
    public function disable(User $user): User
    {
        return DB::transaction(static function () use ($user): User {
            $adminRoleName = 'admin';
            $guardName = Guard::getDefaultName(User::class);

            $adminRole = Role::query()
                ->where('name', $adminRoleName)
                ->where('guard_name', $guardName)
                ->lockForUpdate()
                ->first();

            if (
                $adminRole === null
                || $adminRole->name !== $adminRoleName
                || $adminRole->guard_name !== $guardName
            ) {
                throw new LogicException('The Admin role must be seeded before changing account status.');
            }

            $target = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

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
