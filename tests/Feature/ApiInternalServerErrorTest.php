<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ApiErrorCode;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

final class ApiInternalServerErrorTest extends TestCase
{
    #[DataProvider('failures')]
    public function test_api_failure_returns_and_logs_the_same_server_request_id(Throwable $failure): void
    {
        Config::set('app.debug', true);
        Route::get('/api/test-internal-error', static function () use ($failure): never {
            throw $failure;
        });

        Str::freezeUuids(function (UuidInterface $uuid) use ($failure): void {
            $requestId = $uuid->toString();
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('error')->with('An API exception was reported.', [
                'exception_type' => $failure::class,
                'request_id' => $requestId,
            ]);
            $this->app->instance(LoggerInterface::class, $logger);

            $this->getJson('/api/test-internal-error', [
                'X-Request-ID' => '71b7c75f-c900-4fc8-91e3-367819fa18d9',
            ])
                ->assertInternalServerError()
                ->assertHeader('X-Request-ID', $requestId)
                ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR)
                ->assertJsonPath('meta.request_id', $requestId)
                ->assertDontSee('raw-exception-fixture');
        });
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function failures(): array
    {
        return [
            'runtime failure' => [new RuntimeException('raw-exception-fixture')],
            'explicit HTTP 500' => [new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'raw-exception-fixture')],
        ];
    }
}
