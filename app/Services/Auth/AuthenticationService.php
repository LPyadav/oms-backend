<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Timebox;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use SensitiveParameter;

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

        $user = $this->verifyCredentials($credentials);
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
        $dummyHash = $this->dummyPasswordHash();

        return $this->timebox->dontReturnEarly()->call(
            function (Timebox $timebox) use ($credentials, $dummyHash): User {
                $user = User::query()
                    ->where('email', $credentials['email'])
                    ->first();

                $passwordHash = $user === null
                    ? $dummyHash
                    : $user->getAuthPassword();

                $passwordMatches = $this->hasher->check(
                    $credentials['password'],
                    $passwordHash,
                );

                if ($user === null || ! $passwordMatches) {
                    throw new InvalidCredentialsException;
                }

                $timebox->returnEarly();

                return $user;
            },
            Config::integer('auth.timebox_duration', 200_000),
        );
    }

    private function dummyPasswordHash(): string
    {
        $hash = Config::string('authentication.login.dummy_password_hash', '');

        if ($hash === '' || $this->hasher->needsRehash($hash)) {
            throw new LogicException(
                'The login dummy hash must match the configured password hashing settings.',
            );
        }

        return $hash;
    }
}
