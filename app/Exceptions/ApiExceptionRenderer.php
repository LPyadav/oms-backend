<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Constants\ApiErrorCode;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Users\LastActiveAdminException;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ApiExceptionRenderer
{
    private const array FORWARDED_HEADERS = [
        'allow',
        'retry-after',
        'www-authenticate',
        'x-ratelimit-limit',
        'x-ratelimit-remaining',
        'x-ratelimit-reset',
    ];

    public function __construct(
        private ApiResponse $apiResponse,
    ) {}

    public function __invoke(
        Response $response,
        Throwable $exception,
        Request $request,
    ): Response {
        if (! $request->is('api', 'api/*')) {
            return $response;
        }

        $status = match (true) {
            $exception instanceof InvalidCredentialsException,
            $exception instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $exception instanceof LastActiveAdminException => Response::HTTP_CONFLICT,
            $exception instanceof ValidationException && ! $response->isClientError() => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => $response->getStatusCode(),
        };

        if ($status < Response::HTTP_BAD_REQUEST) {
            return $response;
        }

        [$code, $messageKey] = match (true) {
            $exception instanceof InvalidCredentialsException => [
                ApiErrorCode::AUTH_INVALID_CREDENTIALS,
                'api.auth.invalid_credentials',
            ],
            $exception instanceof LastActiveAdminException => [
                ApiErrorCode::LAST_ACTIVE_ADMIN,
                'api.users.last_active_admin',
            ],
            $exception instanceof ValidationException => [
                ApiErrorCode::VALIDATION_FAILED,
                'api.errors.validation_failed',
            ],
            default => $this->errorForStatus($status),
        };

        $headers = $this->forwardedHeaders($response);

        if ($code === ApiErrorCode::AUTH_UNAUTHENTICATED) {
            $headers['www-authenticate'] ??= ['Bearer'];
        }

        return $this->apiResponse->error(
            message: Lang::string($messageKey),
            code: $code,
            status: $status,
            fields: $exception instanceof ValidationException
                ? $exception->errors()
                : [],
            headers: $headers,
        );
    }

    /**
     * @return array{ApiErrorCode::*, string}
     */
    private function errorForStatus(int $status): array
    {
        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            return [
                ApiErrorCode::INTERNAL_SERVER_ERROR,
                'api.errors.internal_server_error',
            ];
        }

        return match ($status) {
            Response::HTTP_UNAUTHORIZED => [
                ApiErrorCode::AUTH_UNAUTHENTICATED,
                'api.auth.unauthenticated',
            ],
            Response::HTTP_FORBIDDEN => [
                ApiErrorCode::ACCESS_FORBIDDEN,
                'api.errors.access_forbidden',
            ],
            Response::HTTP_NOT_FOUND => [
                ApiErrorCode::RESOURCE_NOT_FOUND,
                'api.errors.resource_not_found',
            ],
            Response::HTTP_METHOD_NOT_ALLOWED => [
                ApiErrorCode::METHOD_NOT_ALLOWED,
                'api.errors.method_not_allowed',
            ],
            Response::HTTP_TOO_MANY_REQUESTS => [
                ApiErrorCode::RATE_LIMIT_EXCEEDED,
                'api.errors.rate_limit_exceeded',
            ],
            default => [
                ApiErrorCode::REQUEST_FAILED,
                'api.errors.request_failed',
            ],
        };
    }

    /**
     * @return array<string, list<string>>
     */
    private function forwardedHeaders(Response $response): array
    {
        $headers = [];

        foreach (self::FORWARDED_HEADERS as $name) {
            $values = array_values(array_filter(
                $response->headers->all($name),
                static fn (?string $value): bool => $value !== null,
            ));

            if ($values !== []) {
                $headers[$name] = $values;
            }
        }

        return $headers;
    }
}
