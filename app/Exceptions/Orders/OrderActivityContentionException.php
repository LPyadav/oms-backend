<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class OrderActivityContentionException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $requestId,
    ) {
        parent::__construct('Order activity write contention exhausted the transaction attempts.');
    }
}
