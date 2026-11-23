<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
