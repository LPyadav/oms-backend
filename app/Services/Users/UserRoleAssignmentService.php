<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Exceptions\Users\LastActiveAdminException;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Audit\AccessAuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

final readonly class UserRoleAssignmentService
{
    public function __construct(
        private UserPolicy $userPolicy,
        private AccessAuditLogWriter $auditLogWriter,
    ) {}

    /**
     * @param  list<string>  $roleNames
     */
    public function replaceRoles(User $actor, User $target, array $roleNames): User
    {
        return DB::transaction(function () use ($actor, $target, $roleNames): User {
            $guardName = Guard::getDefaultName(User::class);
            $adminRole = Role::query()
                ->where('name', 'admin')
                ->where('guard_name', $guardName)
                ->lockForUpdate()
                ->first();

            if ($adminRole === null || $adminRole->name !== 'admin' || $adminRole->guard_name !== $guardName) {
                throw new LogicException('The Admin role must be seeded before replacing user roles.');
            }

            $lockedUsers = User::query()
                ->whereKey([$actor->id, $target->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedActor = $lockedUsers->get($actor->id);
            $lockedTarget = $lockedUsers->get($target->id);

            if (! $lockedActor instanceof User) {
                throw new AuthorizationException;
            }

            if (! $lockedTarget instanceof User) {
                throw (new ModelNotFoundException)->setModel(User::class, [$target->id]);
            }

            if (! $this->userPolicy->assignRoles($lockedActor, $lockedTarget)) {
                throw new AuthorizationException;
            }

            $roles = Role::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', $roleNames)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(
                    static fn (Role $role): bool => $role->guard_name === $guardName
                        && in_array($role->name, $roleNames, true),
                )
                ->values();

            if ($roles->count() !== count($roleNames)) {
                throw ValidationException::withMessages([
                    'roles' => Lang::string('validation.exists', ['attribute' => 'roles']),
                ]);
            }

            $currentRoles = $lockedTarget->roles->sortBy('id')->values();

            if ($currentRoles->modelKeys() === $roles->modelKeys()) {
                return $lockedTarget;
            }

            if ($lockedTarget->is_active && $lockedTarget->hasRole($adminRole) && ! $roles->contains($adminRole)) {
                $anotherAdmin = User::query()
                    ->role($adminRole)
                    ->where('is_active', true)
                    ->whereKeyNot($lockedTarget->id)
                    ->lockForUpdate()
                    ->first(['users.id']);

                if ($anotherAdmin === null) {
                    throw new LastActiveAdminException;
                }
            }

            $beforeRoles = $this->roleSnapshot($currentRoles);

            $lockedTarget->syncRoles(...$roles->all());
            $lockedTarget->load('roles');

            $this->auditLogWriter->record(
                $lockedActor,
                $lockedTarget,
                'user.roles.replaced',
                ['roles' => $beforeRoles],
                ['roles' => $this->roleSnapshot($lockedTarget->roles)],
            );

            return $lockedTarget;
        });
    }

    /**
     * @param  Collection<int, Model>  $roles
     * @return list<array{id: int|string, name: string}>
     */
    private function roleSnapshot(Collection $roles): array
    {
        $snapshot = [];

        foreach ($roles->ensure(Role::class)->sortBy('id') as $role) {
            $snapshot[] = [
                'id' => $role->id,
                'name' => $role->name,
            ];
        }

        return $snapshot;
    }
}
