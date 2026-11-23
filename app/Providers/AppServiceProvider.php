<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\RolePolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use SensitiveParameter;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);

        ResetPassword::createUrlUsing(
            static fn (User $user, #[SensitiveParameter] string $token): string => Config::string('authentication.password_reset_frontend_url')
                .'/reset-password#'.http_build_query(
                    ['token' => $token, 'email' => $user->email],
                    '',
                    '&',
                    PHP_QUERY_RFC3986,
                ),
        );

        Sanctum::authenticateAccessTokensUsing(
            static function (PersonalAccessToken $accessToken, bool $isValid): bool {
                if (! $isValid) {
                    return false;
                }

                $user = $accessToken->tokenable;

                return $user instanceof User && $user->is_active === true;
            },
        );
    }
}
