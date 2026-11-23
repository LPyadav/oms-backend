<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Auth\LoginEmail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;
use Spatie\Permission\Guard;
use Symfony\Component\HttpFoundation\Response;

final readonly class UserManagementService
{
    public function __construct(
        private Hasher $hasher,
        private UserPolicy $userPolicy,
        private AdminUserWriteLock $writeLock,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(
        int $perPage,
        int $page,
        ?string $search = null,
        ?bool $isActive = null,
        ?string $role = null,
    ): LengthAwarePaginator {
        $query = User::query()->with('roles:id,name,guard_name');

        if ($search !== null) {
            $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(static function (Builder $query) use ($pattern): void {
                $query->whereRaw("name like ? escape '!'", [$pattern])
                    ->orWhereRaw("email like ? escape '!'", [$pattern]);
            });
        }

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        if ($role !== null) {
            $guardName = Guard::getDefaultName(User::class);
            $query->whereHas('roles', static fn (Builder $roles): Builder => $roles
                ->where('name', $role)
                ->where('guard_name', $guardName));
        }

        return $query->orderBy('id')->paginate(
            perPage: $perPage,
            columns: ['id', 'name', 'email', 'is_active', 'created_at', 'updated_at'],
            page: $page,
        );
    }

    public function find(string $id): User
    {
        return User::query()
            ->with('roles:id,name,guard_name')
            ->findOrFail($id);
    }

    public function create(
        User $actor,
        string $name,
        string $email,
        #[SensitiveParameter] string $password,
    ): User {
        $user = new User([
            'name' => $name,
            'email' => LoginEmail::normalize($email),
            'password' => $this->hasher->make($password),
        ]);

        return DB::transaction(function () use ($actor, $user): User {
            ['actor' => $lockedActor] = $this->writeLock->acquire($actor);

            if (! $this->userPolicy->create($lockedActor)) {
                throw new AuthorizationException;
            }

            $this->save($user);

            return $user->refresh();
        });
    }

    public function update(User $actor, int|string $userId, ?string $name = null, ?string $email = null): User
    {
        return DB::transaction(function () use ($actor, $userId, $name, $email): User {
            ['actor' => $lockedActor] = $this->writeLock->acquire($actor);

            if (! $this->userPolicy->update($lockedActor)) {
                throw new AuthorizationException;
            }

            $target = User::query()->lockForUpdate()->findOrFail($userId);

            if ($name !== null) {
                $target->name = $name;
            }

            if ($email !== null) {
                $normalizedEmail = LoginEmail::normalize($email);

                if (LoginEmail::normalize($target->email) !== $normalizedEmail) {
                    $target->email_verified_at = null;
                }

                $target->email = $normalizedEmail;
            }

            $this->save($target);

            return $target;
        });
    }

    private function save(User $user): void
    {
        try {
            $saved = $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => Lang::string('validation.unique', ['attribute' => 'email']),
            ])->status(Response::HTTP_CONFLICT);
        }

        if (! $saved) {
            throw new RuntimeException('The user could not be saved.');
        }
    }
}
