<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Exceptions\Orders\OrderWriteContentionException;
use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use PDOException;

final readonly class OrderWriteTransaction
{
    public function __construct(
        private ConcurrencyErrorDetector $concurrencyErrors,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        $attempts = Config::integer('orders.write_attempts');
        if ($attempts < 1) {
            throw new LogicException('Order writes require at least one transaction attempt.');
        }

        try {
            return DB::transaction($operation, attempts: $attempts);
        } catch (PDOException $exception) {
            if (! $this->concurrencyErrors->causedByConcurrencyError($exception->getPrevious() ?? $exception)) {
                throw $exception;
            }

            $requestId = Str::uuid()->toString();
            Log::warning('Order write could not acquire its database locks.', [
                'request_id' => $requestId,
            ]);

            throw new OrderWriteContentionException($requestId);
        }
    }
}
