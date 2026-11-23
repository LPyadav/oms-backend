<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum DashboardRevenueGroup: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function startOfPeriod(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this) {
            self::Day => $date->startOfDay(),
            self::Week => $date->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $date->startOfMonth(),
        };
    }

    public function nextPeriodStart(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::Day => $start->addDay()->startOfDay(),
            self::Week => $start->addWeek()->startOfDay(),
            self::Month => $start->addMonthNoOverflow()->startOfDay(),
        };
    }
}
