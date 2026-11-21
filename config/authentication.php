<?php

declare(strict_types=1);

$dummyPasswordHash = env('AUTH_LOGIN_DUMMY_PASSWORD_HASH', '');
$defaultTokenName = env('AUTH_DEFAULT_TOKEN_NAME', 'OMS client');

if (! is_string($dummyPasswordHash)) {
    throw new InvalidArgumentException(
        'Environment variable [AUTH_LOGIN_DUMMY_PASSWORD_HASH] must be a string.',
    );
}

if (! is_string($defaultTokenName) || trim($defaultTokenName) === '') {
    throw new InvalidArgumentException(
        'Environment variable [AUTH_DEFAULT_TOKEN_NAME] must be a non-empty string.',
    );
}

return [
    'login' => [
        'dummy_password_hash' => $dummyPasswordHash,
    ],

    'tokens' => [
        'default_name' => trim($defaultTokenName),
    ],
];
