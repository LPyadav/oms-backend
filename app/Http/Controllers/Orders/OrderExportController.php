<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\ListOrdersRequest;
use App\Models\User;
use App\Services\Orders\OrderCsvExport;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderExportController extends Controller
{
    public function __invoke(
        ListOrdersRequest $request,
        OrderCsvExport $csv,
    ): StreamedResponse {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        return $csv->download($actor, $request->criteria());
    }
}
