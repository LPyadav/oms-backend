<?php

declare(strict_types=1);

namespace App\Exceptions\Users;

use DomainException;

final class LastActiveAdminException extends DomainException
{
    public function __construct()
    {
        parent::__construct('The last active Admin cannot be disabled.');
    }
}
