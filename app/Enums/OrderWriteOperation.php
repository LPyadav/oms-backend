<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderWriteOperation
{
    case Crud;
    case Lifecycle;
    case Activity;
}
