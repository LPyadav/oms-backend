<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class OrderVersionConflictException extends DomainException implements ShouldntReport
{
    public function __construct(
        public readonly int $currentVersion,
    ) {
        parent::__construct('The submitted order version is stale.');
    }
}
