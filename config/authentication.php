<?php

declare(strict_types=1);

$defaultTokenName = env('AUTH_DEFAULT_TOKEN_NAME', 'OMS client');

if (! is_string($defaultTokenName) || trim($defaultTokenName) === '') {
    throw new InvalidArgumentException(
        'Environment variable [AUTH_DEFAULT_TOKEN_NAME] must be a non-empty string.',
    );
}

$frontendUrl = env('AUTH_PASSWORD_RESET_FRONTEND_URL', 'http://127.0.0.1:5173');
$frontendOrigin = is_string($frontendUrl) ? parse_url($frontendUrl) : false;

if (
    $frontendOrigin === false
    || ! in_array($frontendOrigin['scheme'] ?? null, ['http', 'https'], true)
    || empty($frontendOrigin['host'])
    || array_diff(array_keys($frontendOrigin), ['scheme', 'host', 'port', 'path']) !== []
    || ! in_array($frontendOrigin['path'] ?? '', ['', '/'], true)
    || filter_var($frontendUrl, FILTER_VALIDATE_URL) === false
) {
    throw new InvalidArgumentException(
        'Environment variable [AUTH_PASSWORD_RESET_FRONTEND_URL] must be an HTTP(S) origin.',
    );
}

return [
    'password_reset_frontend_url' => rtrim($frontendUrl, '/'),
    'tokens' => [
        'default_name' => trim($defaultTokenName),
    ],
];
