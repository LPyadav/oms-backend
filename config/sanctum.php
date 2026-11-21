<?php

declare(strict_types=1);

$value = env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 60);

$expiration = is_int($value) || is_string($value)
    ? filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;

if ($expiration === false) {
    throw new InvalidArgumentException(
        'Environment variable [SANCTUM_TOKEN_EXPIRATION_MINUTES] must be a positive integer.',
    );
}

return [
    'stateful' => [],
    'guard' => [],
    'expiration' => $expiration,
    'routes' => false,
];
