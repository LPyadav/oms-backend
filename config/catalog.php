<?php

declare(strict_types=1);

$boundedInteger = static function (string $variable, int $default, int $maximum): int {
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

return [
    'write_attempts' => $boundedInteger('CATALOG_WRITE_ATTEMPTS', 2, 5),
    'retry_after_seconds' => $boundedInteger('CATALOG_WRITE_RETRY_AFTER_SECONDS', 1, 60),
];
