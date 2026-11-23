<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final readonly class AuditLogPolicy
{
    public function __construct(private AdminAuthorization $authorization) {}

    public function viewAny(User $actor): bool
    {
        return $this->authorization->allows($actor, 'audit.view');
    }
}
