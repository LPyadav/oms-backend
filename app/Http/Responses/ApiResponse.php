<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Constants\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string|int|list<string>>  $headers
     */
    public function success(
        string $message,
        ?array $data = null,
        int $status = Response::HTTP_OK,
        ?array $meta = null,
        array $headers = [],
    ): JsonResponse {
        if (
            $status < Response::HTTP_OK
            || $status >= Response::HTTP_MULTIPLE_CHOICES
            || $status === Response::HTTP_NO_CONTENT
            || $status === Response::HTTP_RESET_CONTENT
        ) {
            throw new InvalidArgumentException(
                'A JSON success response requires a 2xx status that permits a body.',
            );
        }

        return $this->build(
            success: true,
            message: $message,
            data: $data,
            errors: null,
            meta: $meta,
            status: $status,
            headers: $headers,
        );
    }

    /**
     * @param  ApiErrorCode::*  $code
     * @param  array<string, list<string>>  $fields
     * @param  array<string, string|int|list<string>>  $headers
     * @param  array<string, mixed>|null  $meta
     */
    public function error(
        string $message,
        string $code,
        int $status,
        array $fields = [],
        array $headers = [],
        ?array $meta = null,
    ): JsonResponse {
        if ($status < Response::HTTP_BAD_REQUEST || $status > 599) {
            throw new InvalidArgumentException(
                'A JSON error response requires a 4xx or 5xx status.',
            );
        }

        return $this->build(
            success: false,
            message: $message,
            data: null,
            errors: [
                'code' => $code,
                'fields' => (object) $fields,
            ],
            meta: $meta,
            status: $status,
            headers: $headers,
        );
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     * @param  array{code: string, fields: stdClass}|null  $errors
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string|int|list<string>>  $headers
     */
    private function build(
        bool $success,
        string $message,
        ?array $data,
        ?array $errors,
        ?array $meta,
        int $status,
        array $headers,
    ): JsonResponse {
        $response = new JsonResponse([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta === null ? null : (object) $meta,
        ], $status, $headers);

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
