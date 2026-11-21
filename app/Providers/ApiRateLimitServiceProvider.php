<?php

declare(strict_types=1);

namespace App\Providers;

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
        RateLimiter::for('login', $this->loginLimits(...));
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
    private function loginLimits(Request $request): array
    {
        $limits = [
            $this->limitFor(
                'rate_limits.login.per_ip',
                'login-ip',
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
                'rate_limits.login.per_email',
                'login-email',
                LoginEmail::normalize($email),
            );
        }

        return $limits;
    }

    private function limitFor(
        string $configKey,
        string $bucket,
        string $identifier,
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

        return Limit::perMinute($attempts)->by($key);
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
