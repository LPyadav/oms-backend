<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderSortField: string
{
    case OrderDate = 'order_date';
    case Total = 'total';
    case Status = 'status';
    case Priority = 'priority';
    case Customer = 'customer';
}
