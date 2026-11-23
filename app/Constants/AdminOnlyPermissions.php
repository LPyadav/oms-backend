<?php

declare(strict_types=1);

namespace App\Constants;

final class AdminOnlyPermissions
{
    public const array NAMES = [
        'audit.view',
        'customers.manage',
        'products.manage',
        'roles.manage',
        'users.manage',
    ];
}
