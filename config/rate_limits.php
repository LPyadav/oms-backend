<?php

declare(strict_types=1);

$positiveInteger = static function (string $variable, int $default): int {
    $value = env($variable, $default);

    $limit = is_int($value) || is_string($value)
        ? filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ])
        : false;

    if ($limit === false) {
        throw new InvalidArgumentException(
            "Environment variable [{$variable}] must be a positive integer.",
        );
    }

    return $limit;
};

return [
    'api' => [
        'per_ip' => $positiveInteger('RATE_LIMIT_API_PER_IP', 120),
    ],

    'login' => [
        'per_ip' => $positiveInteger('RATE_LIMIT_LOGIN_PER_IP', 30),
        'per_email' => $positiveInteger('RATE_LIMIT_LOGIN_PER_EMAIL', 5),
    ],

    'password_change' => [
        'per_account' => $positiveInteger('RATE_LIMIT_PASSWORD_CHANGE_PER_ACCOUNT', 6),
        'window_minutes' => $positiveInteger('RATE_LIMIT_PASSWORD_CHANGE_WINDOW_MINUTES', 1),
    ],
];
