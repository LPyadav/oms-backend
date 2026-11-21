<?php

declare(strict_types=1);

$positiveInteger = static function (string $variable, int $default): int {
    $value = env($variable, $default);
    $number = is_int($value) || is_string($value)
        ? filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ])
        : false;

    if ($number === false) {
        throw new InvalidArgumentException(
            "Environment variable [{$variable}] must be a positive integer.",
        );
    }

    return $number;
};

$defaultPerPage = $positiveInteger('PAGINATION_USERS_DEFAULT_PER_PAGE', 25);
$maxPerPage = $positiveInteger('PAGINATION_USERS_MAX_PER_PAGE', 100);

if ($defaultPerPage > $maxPerPage) {
    throw new InvalidArgumentException(
        '[PAGINATION_USERS_DEFAULT_PER_PAGE] must not exceed [PAGINATION_USERS_MAX_PER_PAGE].',
    );
}

return [
    'users' => [
        'default_per_page' => $defaultPerPage,
        'max_per_page' => $maxPerPage,
    ],
];
