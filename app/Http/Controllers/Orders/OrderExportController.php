<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\ListOrdersRequest;
use App\Models\User;
use App\Services\Orders\OrderCsvExport;
use App\Services\Orders\OrderService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderExportController extends Controller
{
    public function __invoke(
        ListOrdersRequest $request,
        OrderService $orderService,
        OrderCsvExport $csv,
    ): StreamedResponse {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $limit = Config::integer('orders.export_max_rows');
        $orders = $orderService->paginate($actor, [
            ...$request->criteria(),
            'page' => 1,
            'per_page' => $limit,
        ]);

        if ($orders->total() > $limit) {
            throw ValidationException::withMessages([
                'export' => Lang::string('api.orders.export_limit', ['limit' => $limit]),
            ]);
        }

        return $csv->download($orders->getCollection());
    }
}
