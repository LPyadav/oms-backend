<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Notifications\OrderNotificationController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Reports\ReportController;
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

    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard.show');
    Route::get('/dashboard/revenue-trend', [DashboardController::class, 'revenueTrend'])
        ->name('dashboard.revenue-trend');

    Route::get('/reports/orders', [ReportController::class, 'orderFulfillment'])
        ->name('reports.orders');
    Route::get('/reports/sales-tax', [ReportController::class, 'salesTax'])
        ->name('reports.sales-tax');

    Route::prefix('customers')->name('customers.')->group(static function (): void {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::post('/', [CustomerController::class, 'store'])->name('store');

        Route::get('/{customer}', [CustomerController::class, 'show'])
            ->whereNumber('customer')
            ->name('show');

        Route::patch('/{customer}', [CustomerController::class, 'update'])
            ->whereNumber('customer')
            ->name('update');

        Route::patch('/{customer}/deactivate', [CustomerController::class, 'deactivate'])
            ->whereNumber('customer')
            ->name('deactivate');
    });

    Route::prefix('notifications')->name('notifications.')->group(static function (): void {
        Route::get('/', [OrderNotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [OrderNotificationController::class, 'unreadCount'])->name('unread-count');
        Route::patch('/read-all', [OrderNotificationController::class, 'markAllRead'])->name('read-all');
        Route::patch('/{notification}/read', [OrderNotificationController::class, 'markRead'])
            ->whereNumber('notification')
            ->name('read');
    });

    Route::prefix('orders')->name('orders.')->group(static function (): void {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::post('/', [OrderController::class, 'store'])->name('store');

        Route::get('/assignees', [OrderController::class, 'assignees'])->name('assignees');
        Route::post('/preview', [OrderController::class, 'preview'])->name('preview');

        Route::get('/{order}', [OrderController::class, 'show'])
            ->whereNumber('order')
            ->name('show');

        Route::patch('/{order}', [OrderController::class, 'update'])
            ->whereNumber('order')
            ->name('update');

        Route::post('/{order}/status', [OrderController::class, 'changeStatus'])
            ->whereNumber('order')
            ->name('status.update');

        Route::post('/{order}/assign', [OrderController::class, 'assign'])
            ->whereNumber('order')
            ->name('assign');

        Route::post('/{order}/notes', [OrderController::class, 'addNote'])
            ->whereNumber('order')
            ->name('notes.store');

        Route::delete('/{order}', [OrderController::class, 'destroy'])
            ->whereNumber('order')
            ->name('destroy');
    });

    Route::prefix('products')->name('products.')->group(static function (): void {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::post('/', [ProductController::class, 'store'])->name('store');

        Route::get('/{product}', [ProductController::class, 'show'])
            ->whereNumber('product')
            ->name('show');

        Route::patch('/{product}', [ProductController::class, 'update'])
            ->whereNumber('product')
            ->name('update');

        Route::patch('/{product}/deactivate', [ProductController::class, 'deactivate'])
            ->whereNumber('product')
            ->name('deactivate');

        Route::patch('/{product}/stock', [ProductController::class, 'adjustStock'])
            ->whereNumber('product')
            ->name('stock.update');
    });

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

        Route::get('/{user}/permissions', [AdminUserController::class, 'showPermissions'])
            ->whereNumber('user')
            ->name('permissions.show');

        Route::patch('/{user}/permissions', [AdminUserController::class, 'assignPermissions'])
            ->whereNumber('user')
            ->name('permissions.update');
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
