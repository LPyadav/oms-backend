<?php

declare(strict_types=1);

return [
    'auth' => [
        'logged_in' => 'Logged in successfully.',
        'logged_out' => 'Logged out successfully.',
        'current_user_retrieved' => 'Authenticated user retrieved.',
        'invalid_credentials' => 'The email or password is incorrect.',
        'unauthenticated' => 'Authentication is required.',
    ],

    'users' => [
        'list_retrieved' => 'Users retrieved successfully.',
        'created' => 'User created successfully.',
        'updated' => 'User updated successfully.',
        'last_active_admin' => 'The last active Admin cannot be disabled.',
    ],

    'health' => [
        'ok' => 'The API is healthy.',
    ],

    'errors' => [
        'validation_failed' => 'The submitted data is invalid.',
        'access_forbidden' => 'You do not have permission to perform this action.',
        'resource_not_found' => 'The requested resource was not found.',
        'method_not_allowed' => 'The request method is not supported for this endpoint.',
        'rate_limit_exceeded' => 'Too many requests. Please try again later.',
        'request_failed' => 'The request could not be processed.',
        'internal_server_error' => 'An unexpected error occurred.',
    ],
];
