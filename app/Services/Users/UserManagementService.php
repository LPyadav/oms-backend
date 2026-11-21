<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use App\Services\Auth\LoginEmail;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

final readonly class UserManagementService
{
    public function __construct(
        private Hasher $hasher,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $perPage, int $page): LengthAwarePaginator
    {
        return User::query()
            ->with('roles:id,name,guard_name')
            ->orderBy('id')
            ->paginate(
                perPage: $perPage,
                columns: ['id', 'name', 'email', 'is_active', 'created_at', 'updated_at'],
                page: $page,
            );
    }

    public function create(
        string $name,
        string $email,
        #[SensitiveParameter] string $password,
    ): User {
        $user = new User([
            'name' => $name,
            'email' => LoginEmail::normalize($email),
            'password' => $this->hasher->make($password),
        ]);

        $this->save($user);

        return $user->refresh();
    }

    public function update(User $user, ?string $name = null, ?string $email = null): User
    {
        return DB::transaction(function () use ($user, $name, $email): User {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);

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
