<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderWriteOperation;
use App\Exceptions\Orders\OrderActivityContentionException;
use App\Exceptions\Orders\OrderLifecycleContentionException;
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
    public function run(Closure $operation, OrderWriteOperation $writeOperation = OrderWriteOperation::Crud): mixed
    {
        $attempts = Config::integer('orders.write_attempts');
        if ($attempts < 1) {
            throw new LogicException(match ($writeOperation) {
                OrderWriteOperation::Crud => 'Order writes require at least one transaction attempt.',
                OrderWriteOperation::Lifecycle => 'Order status writes require at least one transaction attempt.',
                OrderWriteOperation::Activity => 'Order activity writes require at least one transaction attempt.',
            });
        }

        try {
            return DB::transaction($operation, attempts: $attempts);
        } catch (PDOException $exception) {
            $cause = $writeOperation === OrderWriteOperation::Crud
                ? $exception->getPrevious() ?? $exception
                : $exception;
            if (! $this->concurrencyErrors->causedByConcurrencyError($cause)) {
                throw $exception;
            }

            $requestId = Str::uuid()->toString();
            [$message, $contention] = match ($writeOperation) {
                OrderWriteOperation::Crud => [
                    'Order write could not acquire its database locks.',
                    new OrderWriteContentionException($requestId),
                ],
                OrderWriteOperation::Lifecycle => [
                    'Order status change could not acquire its database locks.',
                    new OrderLifecycleContentionException($requestId),
                ],
                OrderWriteOperation::Activity => [
                    'Order activity could not acquire its database locks.',
                    new OrderActivityContentionException($requestId),
                ],
            };
            Log::warning($message, ['request_id' => $requestId]);

            throw $contention;
        }
    }
}
