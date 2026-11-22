<?php

declare(strict_types=1);

namespace App\Exceptions\Catalog;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class CatalogWriteContentionException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $requestId,
    ) {
        parent::__construct('Catalog write contention exhausted the transaction attempts.');
    }
}
