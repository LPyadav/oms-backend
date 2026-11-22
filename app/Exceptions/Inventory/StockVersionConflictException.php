<?php

declare(strict_types=1);

namespace App\Exceptions\Inventory;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class StockVersionConflictException extends DomainException implements ShouldntReport
{
    public function __construct(
        public readonly int $currentStockVersion,
    ) {
        parent::__construct('The submitted stock version is stale.');
    }
}
