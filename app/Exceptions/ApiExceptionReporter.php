<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;
use WeakMap;

final readonly class ApiExceptionReporter
{
    private const string REQUEST_ID_ATTRIBUTE = 'oms.exception_request_id';

    private const string REPORTED_EXCEPTIONS_ATTRIBUTE = 'oms.reported_api_exceptions';

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function reportApi(Request $request, Throwable $exception): string
    {
        $requestId = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        if (! is_string($requestId)) {
            $requestId = Str::uuid()->toString();
            $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);
        }

        $reported = $request->attributes->get(self::REPORTED_EXCEPTIONS_ATTRIBUTE);

        if (! $reported instanceof WeakMap) {
            $reported = new WeakMap;
            $request->attributes->set(self::REPORTED_EXCEPTIONS_ATTRIBUTE, $reported);
        }

        if (! isset($reported[$exception])) {
            $this->log($exception, $requestId);
            $reported[$exception] = true;
        }

        return $requestId;
    }

    public function reportDatabase(PDOException $exception): void
    {
        $this->log($exception, null);
    }

    private function log(Throwable $exception, ?string $requestId): void
    {
        $context = ['exception_type' => $exception::class];

        if ($requestId !== null) {
            $context['request_id'] = $requestId;
        }

        if ($exception instanceof PDOException) {
            $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();
            $context['sqlstate'] = is_string($sqlState) && preg_match('/\A[A-Z0-9]{5}\z/', $sqlState) === 1
                ? $sqlState
                : null;

            $this->logger->error('A database operation failed.', $context);

            return;
        }

        $this->logger->error('An API exception was reported.', $context);
    }
}
