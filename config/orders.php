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

$currency = env('ORDERS_CURRENCY', 'USD');
if (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
    throw new InvalidArgumentException('Environment variable [ORDERS_CURRENCY] must be a three-letter uppercase code.');
}

return [
    'currency' => $currency,
    'max_items' => $boundedInteger('ORDERS_MAX_ITEMS', 100, 1000),
    'max_note_length' => $boundedInteger('ORDERS_MAX_NOTE_LENGTH', 2000, 10000),
    'write_attempts' => $boundedInteger('ORDERS_WRITE_ATTEMPTS', 2, 5),
];
