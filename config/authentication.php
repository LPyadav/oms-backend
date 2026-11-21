<?php

declare(strict_types=1);

$defaultTokenName = env('AUTH_DEFAULT_TOKEN_NAME', 'OMS client');

if (! is_string($defaultTokenName) || trim($defaultTokenName) === '') {
    throw new InvalidArgumentException(
        'Environment variable [AUTH_DEFAULT_TOKEN_NAME] must be a non-empty string.',
    );
}

return [
    'tokens' => [
        'default_name' => trim($defaultTokenName),
    ],
];
