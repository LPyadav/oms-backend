<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Roles\AdminRoleController;
use App\Http\Controllers\Users\AdminUserController;
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

    Route::prefix('admin/users')->name('admin.users.')->group(static function (): void {
        Route::get('/', [AdminUserController::class, 'index'])
            ->name('index');

        Route::post('/', [AdminUserController::class, 'store'])
            ->name('store');

        Route::patch('/{user}', [AdminUserController::class, 'update'])
            ->whereNumber('user')
            ->name('update');

        Route::patch('/{user}/disable', [AdminUserController::class, 'disable'])
            ->whereNumber('user')
            ->name('disable');

        Route::patch('/{user}/enable', [AdminUserController::class, 'enable'])
            ->whereNumber('user')
            ->name('enable');

        Route::patch('/{user}/roles', [AdminUserController::class, 'assignRoles'])
            ->whereNumber('user')
            ->name('roles.update');
    });

    Route::prefix('admin/roles')->name('admin.roles.')->group(static function (): void {
        Route::get('/', [AdminRoleController::class, 'index'])
            ->name('index');

        Route::post('/', [AdminRoleController::class, 'store'])
            ->name('store');

        Route::patch('/{role}', [AdminRoleController::class, 'update'])
            ->whereNumber('role')
            ->name('update');

        Route::patch('/{role}/permissions', [AdminRoleController::class, 'assignPermissions'])
            ->whereNumber('role')
            ->name('permissions.update');
    });

    Route::get('/admin/permissions', [AdminRoleController::class, 'permissions'])
        ->name('admin.permissions.index');
});
