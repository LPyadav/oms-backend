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

$maximumDays = $positiveInteger('REPORTS_MAX_DAYS', 366, 366);
$maximumPerPage = $positiveInteger('PAGINATION_REPORTS_MAX_PER_PAGE', 100, 100);

return [
    'default_days' => $positiveInteger('REPORTS_DEFAULT_DAYS', 30, $maximumDays),
    'max_days' => $maximumDays,
    'default_per_page' => $positiveInteger('PAGINATION_REPORTS_DEFAULT_PER_PAGE', 25, $maximumPerPage),
    'max_per_page' => $maximumPerPage,
    'export_max_rows' => $positiveInteger('REPORTS_EXPORT_MAX_ROWS', 50_000, 50_000),
];
