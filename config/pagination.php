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

$pagination = static function (string $defaultVariable, string $maxVariable) use ($positiveInteger): array {
    $defaultPerPage = $positiveInteger($defaultVariable, 25);
    $maxPerPage = $positiveInteger($maxVariable, 100);

    if ($defaultPerPage > $maxPerPage) {
        throw new InvalidArgumentException(
            "[{$defaultVariable}] must not exceed [{$maxVariable}].",
        );
    }

    return [
        'default_per_page' => $defaultPerPage,
        'max_per_page' => $maxPerPage,
    ];
};

return [
    'users' => $pagination(
        'PAGINATION_USERS_DEFAULT_PER_PAGE',
        'PAGINATION_USERS_MAX_PER_PAGE',
    ),
    'roles' => $pagination(
        'PAGINATION_ROLES_DEFAULT_PER_PAGE',
        'PAGINATION_ROLES_MAX_PER_PAGE',
    ),
];
