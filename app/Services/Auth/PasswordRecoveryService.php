<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class PasswordRecoveryService
{
    public function __construct(
        private Hasher $hasher,
        private Timebox $timebox,
    ) {}

    public function sendLink(string $email): void
    {
        DB::transaction(function () use ($email): void {
            $account = $this->lockActiveAccount($email);

            if ($account === null) {
                return;
            }

            Password::sendResetLink(
                ['email' => $account->email, 'is_active' => true],
                static function (User $recipient, #[SensitiveParameter] string $token): void {
                    DB::afterCommit(static function () use ($recipient, $token): void {
                        $recipient->sendPasswordResetNotification($token);
                        event(new PasswordResetLinkSent($recipient));
                    });
                },
            );
        });
    }

    public function reset(
        string $email,
        #[SensitiveParameter] string $token,
        #[SensitiveParameter] string $password,
    ): void {
        $status = $this->timebox->dontReturnEarly()->call(
            function (Timebox $timebox) use ($email, $token, $password): string {
                $status = $this->consumeToken($email, $token, $password);
                if ($status === Password::PASSWORD_RESET) {
                    $timebox->returnEarly();
                }

                return $status;
            },
            Config::integer('auth.timebox_duration', 200_000),
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => Lang::string('api.auth.password_reset_link_invalid'),
            ]);
        }
    }

    private function consumeToken(
        string $email,
        #[SensitiveParameter] string $token,
        #[SensitiveParameter] string $password,
    ): string {
        return DB::transaction(function () use ($email, $token, $password): string {
            // Serialize broker validation and token consumption with other account writers.
            $account = $this->lockActiveAccount($email);

            if ($account === null) {
                return Password::INVALID_USER;
            }

            return Password::reset(
                ['email' => $account->email, 'is_active' => true, 'token' => $token, 'password' => $password],
                function (User $user, #[SensitiveParameter] string $newPassword): void {
                    $user->password = $this->hasher->make($newPassword);
                    $user->setRememberToken(Str::random(60));
                    $user->saveOrFail();
                    $user->tokens()->delete();
                    DB::afterCommit(static fn () => event(new PasswordReset($user)));
                },
            );
        });
    }

    private function lockActiveAccount(string $email): ?User
    {
        $account = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        return $account !== null && LoginEmail::normalize($account->email) === $email
            ? $account
            : null;
    }
}
