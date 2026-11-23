<?php

declare(strict_types=1);

$positiveInteger = static function (string $variable, int $default, int $maximum): int {
    $value = env($variable, $default);
    $number = is_int($value) || is_string($value)
        ? filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $maximum],
        ])
        : false;

    if ($number === false) {
        throw new InvalidArgumentException(
            "Environment variable [{$variable}] must be an integer between 1 and {$maximum}.",
        );
    }

    return $number;
};

$timezone = env('DASHBOARD_TIMEZONE', 'UTC');
if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
    throw new InvalidArgumentException('Environment variable [DASHBOARD_TIMEZONE] must be an IANA time zone.');
}

$maximumDays = $positiveInteger('DASHBOARD_REVENUE_MAX_DAYS', 366, 366);
$defaultDays = $positiveInteger('DASHBOARD_REVENUE_DEFAULT_DAYS', 30, $maximumDays);

return [
    'timezone' => $timezone,
    'recent_orders_limit' => $positiveInteger('DASHBOARD_RECENT_ORDERS_LIMIT', 10, 100),
    'revenue_default_days' => $defaultDays,
    'revenue_max_days' => $maximumDays,
];
