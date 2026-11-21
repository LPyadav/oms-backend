<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
