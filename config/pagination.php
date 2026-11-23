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
    'customers' => $pagination(
        'PAGINATION_CUSTOMERS_DEFAULT_PER_PAGE',
        'PAGINATION_CUSTOMERS_MAX_PER_PAGE',
    ),
    'orders' => $pagination(
        'PAGINATION_ORDERS_DEFAULT_PER_PAGE',
        'PAGINATION_ORDERS_MAX_PER_PAGE',
    ),
    'order_assignees' => $pagination(
        'PAGINATION_ORDER_ASSIGNEES_DEFAULT_PER_PAGE',
        'PAGINATION_ORDER_ASSIGNEES_MAX_PER_PAGE',
    ),
    'notifications' => $pagination(
        'PAGINATION_NOTIFICATIONS_DEFAULT_PER_PAGE',
        'PAGINATION_NOTIFICATIONS_MAX_PER_PAGE',
    ),
    'products' => $pagination(
        'PAGINATION_PRODUCTS_DEFAULT_PER_PAGE',
        'PAGINATION_PRODUCTS_MAX_PER_PAGE',
    ),
    'access_audit_logs' => $pagination(
        'PAGINATION_ACCESS_AUDIT_LOGS_DEFAULT_PER_PAGE',
        'PAGINATION_ACCESS_AUDIT_LOGS_MAX_PER_PAGE',
    ),
    'operational_audit_logs' => $pagination(
        'PAGINATION_OPERATIONAL_AUDIT_LOGS_DEFAULT_PER_PAGE',
        'PAGINATION_OPERATIONAL_AUDIT_LOGS_MAX_PER_PAGE',
    ),
];
