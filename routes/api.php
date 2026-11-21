<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

Route::get('/health', static function (ApiResponse $apiResponse): JsonResponse {
    return $apiResponse->success(
        message: Lang::string('api.health.ok'),
        data: ['status' => 'ok'],
    );
})->name('api.health');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(static function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::get('/me', [AuthController::class, 'me'])
        ->name('auth.me');
});
