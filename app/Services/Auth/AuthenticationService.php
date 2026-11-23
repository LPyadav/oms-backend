<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use SensitiveParameter;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final readonly class AuthenticationService
{
    public function __construct(
        private Hasher $hasher,
        private Timebox $timebox,
    ) {}

    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, access_token: string, expires_at: CarbonImmutable}
     */
    public function login(
        #[SensitiveParameter] array $credentials,
        ?string $deviceName = null,
    ): array {
        $expirationMinutes = Config::integer('sanctum.expiration');

        if ($expirationMinutes < 1) {
            throw new LogicException(
                'Sanctum token expiration must be a positive number of minutes.',
            );
        }

        $tokenName = $deviceName
            ?? Config::string('authentication.tokens.default_name');

        $verifiedUser = $this->verifyCredentials($credentials);

        return DB::transaction(static function () use ($verifiedUser, $tokenName, $expirationMinutes): array {
            $user = User::query()
                ->lockForUpdate()
                ->find($verifiedUser->id);

            if (
                $user === null
                || ! $user->is_active
                || $user->getAuthPassword() !== $verifiedUser->getAuthPassword()
                || LoginEmail::normalize($user->email) !== LoginEmail::normalize($verifiedUser->email)
            ) {
                throw new InvalidCredentialsException;
            }

            $expiresAt = now()->toImmutable()->addMinutes($expirationMinutes);

            $token = $user->createToken(
                name: $tokenName,
                expiresAt: $expiresAt,
            );

            return [
                'user' => $user,
                'access_token' => $token->plainTextToken,
                'expires_at' => $expiresAt,
            ];
        });
    }

    /**
     * @return array{roles: list<string>, permissions: list<string>}
     */
    public function currentUserAuthorization(User $user): array
    {
        $user->load(['roles.permissions', 'permissions']);

        $guardName = Guard::getDefaultName($user);

        $roles = $user->roles
            ->whereInstanceOf(Role::class)
            ->whereStrict('guard_name', $guardName)
            ->map(static fn (Role $role): string => $role->name)
            ->sort(SORT_STRING);

        $permissions = $user->getAllPermissions()
            ->whereInstanceOf(Permission::class)
            ->whereStrict('guard_name', $guardName)
            ->map(static fn (Permission $permission): string => $permission->name)
            ->uniqueStrict()
            ->sort(SORT_STRING);

        return [
            'roles' => array_values($roles->all()),
            'permissions' => array_values($permissions->all()),
        ];
    }

    public function changePassword(
        User $actor,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword,
    ): void {
        /** @var HasAbilities|null $token */
        $token = $actor->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        DB::transaction(function () use ($actor, $token, $currentPassword, $newPassword): void {
            $account = User::query()->lockForUpdate()->find($actor->id);

            if (
                $account === null
                || ! $account->is_active
                || ! $account->tokens()->whereKey($token->getKey())->exists()
            ) {
                throw new AuthenticationException;
            }

            if (! $this->hasher->check($currentPassword, $account->getAuthPassword())) {
                throw ValidationException::withMessages([
                    'current_password' => Lang::string('validation.current_password'),
                ]);
            }

            $account->password = $this->hasher->make($newPassword);
            $account->saveOrFail();
            $account->tokens()->delete();
        });
    }

    public function logout(User $user): void
    {
        /** @var HasAbilities|null $token */
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $token->delete();
    }

    /**
     * @param  array{email: string, password: string}  $credentials
     */
    private function verifyCredentials(
        #[SensitiveParameter] array $credentials,
    ): User {
        return $this->timebox->dontReturnEarly()->call(
            function (Timebox $timebox) use ($credentials): User {
                $email = LoginEmail::normalize($credentials['email']);

                $user = User::query()
                    ->where('email', $email)
                    ->first();

                if ($user === null || LoginEmail::normalize($user->email) !== $email) {
                    // Keep one password hashing operation on the unknown-account path.
                    $this->hasher->make(Str::random());

                    throw new InvalidCredentialsException;
                }

                $passwordMatches = $this->hasher->check(
                    $credentials['password'],
                    $user->getAuthPassword(),
                );

                if (! $passwordMatches || ! $user->is_active) {
                    throw new InvalidCredentialsException;
                }

                $timebox->returnEarly();

                return $user;
            },
            Config::integer('auth.timebox_duration', 200_000),
        );
    }
}
