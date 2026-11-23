<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class OrderIdempotencyConflictException extends DomainException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('The order idempotency key is already bound to a different request.');
    }
}
