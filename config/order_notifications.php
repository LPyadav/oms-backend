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

$queue = env('ORDER_NOTIFICATIONS_QUEUE', 'notifications');
if (! is_string($queue) || trim($queue) === '') {
    throw new InvalidArgumentException('Environment variable [ORDER_NOTIFICATIONS_QUEUE] must specify a queue name.');
}

return [
    'queue' => $queue,
    'recovery_delay_seconds' => $positiveInteger('ORDER_NOTIFICATIONS_RECOVERY_DELAY_SECONDS', 60, 86400),
    'recovery_batch_size' => $positiveInteger('ORDER_NOTIFICATIONS_RECOVERY_BATCH_SIZE', 100, 1000),
    'job_attempts' => $positiveInteger('ORDER_NOTIFICATIONS_JOB_ATTEMPTS', 3, 10),
    'job_backoff_seconds' => $positiveInteger('ORDER_NOTIFICATIONS_JOB_BACKOFF_SECONDS', 10, 3600),
];
