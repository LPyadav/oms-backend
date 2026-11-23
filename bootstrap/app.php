<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\ApiExceptionReporter;
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

        $exceptions->report(static function (Throwable $exception): ?bool {
            $request = app()->bound('request') ? app('request') : null;

            if ($request instanceof Request && $request->is('api', 'api/*')) {
                app(ApiExceptionReporter::class)->reportApi($request, $exception);

                return false;
            }

            if ($exception instanceof PDOException) {
                app(ApiExceptionReporter::class)->reportDatabase($exception);

                return false;
            }

            return null;
        });

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
