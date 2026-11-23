<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;

final class OrderCompletionDateFilter
{
    // Cover the UTC instants that can represent the boundary dates at any IANA offset.
    private const int TRANSITION_PADDING_SECONDS = 172_800;

    /**
     * Both bounds are UTC midnights representing local calendar date labels.
     */
    public function apply(Builder $orders, CarbonImmutable $from, CarbonImmutable $until, DateTimeZone $timezone): void
    {
        $intervals = $this->utcIntervals($from->getTimestamp(), $until->getTimestamp(), $timezone);
        if ($intervals === []) {
            $orders->whereRaw('0 = 1');

            return;
        }

        if (count($intervals) === 1) {
            $this->applyInterval($orders, $intervals[0]);

            return;
        }

        $orders->where(function (Builder $matching) use ($intervals): void {
            foreach ($intervals as $interval) {
                $matching->orWhere(function (Builder $range) use ($interval): void {
                    $this->applyInterval($range, $interval);
                });
            }
        });
    }

    /** @return list<array{start: int, end: int}> */
    private function utcIntervals(int $from, int $until, DateTimeZone $timezone): array
    {
        if ($timezone->getName() === 'UTC') {
            return [['start' => $from, 'end' => $until]];
        }

        $scanStart = $from - self::TRANSITION_PADDING_SECONDS;
        $scanEnd = $until + self::TRANSITION_PADDING_SECONDS;
        $transitions = $timezone->getTransitions($scanStart, $scanEnd);
        if (! $transitions) {
            $offset = $timezone->getOffset(CarbonImmutable::createFromTimestampUTC($from));

            return [['start' => $from - $offset, 'end' => $until - $offset]];
        }

        $intervals = [];
        foreach ($transitions as $index => $transition) {
            // Intersect calendar bounds with the UTC segment that uses this offset.
            $offset = $transition['offset'];
            $segmentEnd = $transitions[$index + 1]['ts'] ?? $scanEnd;
            $start = max($transition['ts'], $from - $offset);
            $end = min($segmentEnd, $until - $offset);
            if ($start >= $end) {
                continue;
            }

            $previous = array_key_last($intervals);
            if ($previous !== null && $intervals[$previous]['end'] === $start) {
                $intervals[$previous]['end'] = $end;
            } else {
                $intervals[] = ['start' => $start, 'end' => $end];
            }
        }

        return $intervals;
    }

    /** @param  array{start: int, end: int}  $interval */
    private function applyInterval(Builder $orders, array $interval): void
    {
        $orders->where('orders.completed_at', '>=', CarbonImmutable::createFromTimestampUTC($interval['start']))
            ->where('orders.completed_at', '<', CarbonImmutable::createFromTimestampUTC($interval['end']));
    }
}
