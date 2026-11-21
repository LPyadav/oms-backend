<?php

declare(strict_types=1);

use App\Providers\ApiRateLimitServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ApiRateLimitServiceProvider::class,
];
