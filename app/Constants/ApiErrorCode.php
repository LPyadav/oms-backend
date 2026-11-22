<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * Stable error identifiers returned in the API's errors.code field.
 *
 * Changing an existing value requires updating the API contract.
 */
final class ApiErrorCode
{
    public const string AUTH_INVALID_CREDENTIALS = 'AUTH_INVALID_CREDENTIALS';

    public const string AUTH_UNAUTHENTICATED = 'AUTH_UNAUTHENTICATED';

    public const string VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const string ACCESS_FORBIDDEN = 'ACCESS_FORBIDDEN';

    public const string RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';

    public const string METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';

    public const string LAST_ACTIVE_ADMIN = 'LAST_ACTIVE_ADMIN';

    public const string RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';

    public const string REQUEST_FAILED = 'REQUEST_FAILED';

    public const string WRITE_CONTENTION = 'WRITE_CONTENTION';

    public const string INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';

    private function __construct() {}
}
