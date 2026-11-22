<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderNotificationType: string
{
    case Assigned = 'order.assigned';
    case Confirmed = 'order.confirmed';
    case Cancelled = 'order.cancelled';
}
