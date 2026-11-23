<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\Auth\InvalidCredentialsException;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        $middleware->throttleApi();

        // Count API requests before checking their bearer tokens.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesRequests::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        $middleware->redirectGuestsTo(
            static fn (Request $request): ?string => $request->is('api', 'api/*')
                ? null
                : route('login'),
        );
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api', 'api/*')
                || $request->expectsJson(),
        );

        $exceptions->dontReport(InvalidCredentialsException::class);

        $exceptions->report(static function (PDOException $exception): void {
            $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();

            app(LoggerInterface::class)->error('A database operation failed.', [
                'exception_type' => $exception::class,
                'sqlstate' => is_string($sqlState) && preg_match('/\A[A-Z0-9]{5}\z/', $sqlState) === 1
                    ? $sqlState
                    : null,
            ]);
        })->stop();

        $exceptions->respond(static function (
            Response $response,
            Throwable $exception,
            Request $request,
        ): Response {
            $renderer = app(ApiExceptionRenderer::class);

            return $renderer($response, $exception, $request);
        });
    })
    ->create();
