<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\LoginEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class ApiRateLimitServiceProvider extends ServiceProvider
{
    private const int MAX_EMAIL_INPUT_BYTES = 1024;

    public function boot(): void
    {
        RateLimiter::for('api', $this->apiLimit(...));
        RateLimiter::for('login', fn (Request $request): array => $this->publicAuthLimits($request, 'login'));
        RateLimiter::for('password-link', fn (Request $request): array => $this->publicAuthLimits($request, 'password_link'));
        RateLimiter::for('password-reset', fn (Request $request): array => $this->publicAuthLimits($request, 'password_reset'));
        RateLimiter::for('password-change', $this->passwordChangeLimit(...));
    }

    private function apiLimit(Request $request): Limit
    {
        return $this->limitFor(
            'rate_limits.api.per_ip',
            'api-ip',
            $this->clientIp($request),
        );
    }

    /**
     * @return list<Limit>
     */
    private function publicAuthLimits(Request $request, string $action): array
    {
        $bucket = str_replace('_', '-', $action);
        $limits = [
            $this->limitFor(
                "rate_limits.{$action}.per_ip",
                "{$bucket}-ip",
                $this->clientIp($request),
            ),
        ];

        $body = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();

        $input = $body['email'] ?? null;
        $email = is_string($input) ? trim($input) : '';

        if ($email !== '' && strlen($email) <= self::MAX_EMAIL_INPUT_BYTES) {
            $limits[] = $this->limitFor(
                "rate_limits.{$action}.per_email",
                "{$bucket}-email",
                LoginEmail::normalize($email),
            );
        }

        return $limits;
    }

    private function passwordChangeLimit(Request $request): Limit
    {
        // Resolve the Bearer-only guard before authentication sets the default guard.
        $account = $request->user('sanctum');

        if (! $account instanceof User) {
            return Limit::none();
        }

        return $this->limitFor(
            'rate_limits.password_change.per_account',
            'password-change-account',
            (string) $account->id,
            Config::integer('rate_limits.password_change.window_minutes'),
        );
    }

    private function limitFor(
        string $configKey,
        string $bucket,
        string $identifier,
        int $windowMinutes = 1,
    ): Limit {
        $attempts = Config::integer($configKey);

        if ($attempts < 1) {
            throw new LogicException(
                "Rate limit [{$configKey}] must be a positive integer.",
            );
        }

        $secret = Config::string('app.key', '');

        if ($secret === '') {
            throw new LogicException(
                'The application key is required for rate limiting.',
            );
        }

        $key = hash_hmac(
            'sha256',
            'oms:rate-limit:'.$bucket.':'.$identifier,
            $secret,
        );

        return Limit::perMinute($attempts, $windowMinutes)->by($key);
    }

    private function clientIp(Request $request): string
    {
        $ip = $request->ip();

        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'unresolved';
        }

        $packedIp = inet_pton($ip);

        return $packedIp === false ? 'unresolved' : $packedIp;
    }
}
