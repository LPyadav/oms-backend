<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ApiErrorCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PDOException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Tests\TestCase;

final class DatabaseExceptionReportingTest extends TestCase
{
    public function test_query_exception_reports_only_safe_diagnostics_and_returns_a_sanitized_response(): void
    {
        Route::get('/api/test-database-failure', static function (): never {
            $driverException = new PDOException('raw-driver-fixture');
            $driverException->errorInfo = ['HY000', 1, 'raw-driver-fixture'];

            throw new QueryException('test', 'select ?', ['raw-binding-fixture'], $driverException);
        });

        Str::freezeUuids(function (UuidInterface $uuid): void {
            $requestId = $uuid->toString();
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('error')->with('A database operation failed.', [
                'exception_type' => QueryException::class,
                'request_id' => $requestId,
                'sqlstate' => 'HY000',
            ]);
            $this->app->instance(LoggerInterface::class, $logger);

            $this->getJson('/api/test-database-failure')
                ->assertInternalServerError()
                ->assertHeader('X-Request-ID', $requestId)
                ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR)
                ->assertJsonPath('meta.request_id', $requestId)
                ->assertDontSee('raw-driver-fixture')
                ->assertDontSee('raw-binding-fixture');
        });
    }

    public function test_direct_pdo_exception_reports_null_for_an_invalid_sqlstate(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('A database operation failed.', [
            'exception_type' => PDOException::class,
            'sqlstate' => null,
        ]);
        $this->app->instance(LoggerInterface::class, $logger);

        $exception = new PDOException('raw-driver-fixture');
        $exception->errorInfo = ['raw-state-fixture', 1, 'raw-driver-fixture'];

        report($exception);
    }
}
