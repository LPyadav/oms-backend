<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Order;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\SQLiteConnection;
use stdClass;
use UnexpectedValueException;

final class OrderReportTotals
{
    private const int SQLITE_SUM_CHUNK = 1_000_000_000;

    private const array MONEY_COLUMNS = [
        'subtotal_minor' => 'orders.subtotal_minor',
        'order_discount_minor' => 'orders.order_discount_minor',
        'discount_total_minor' => 'orders.discount_total_minor',
        'tax_total_minor' => 'orders.tax_total_minor',
        'grand_total_minor' => 'orders.grand_total_minor',
    ];

    /**
     * @template TColumn of 'subtotal_minor'|'order_discount_minor'|'discount_total_minor'|'tax_total_minor'|'grand_total_minor'
     *
     * @param  Builder<Order>  $orders
     * @param  non-empty-list<TColumn>  $columns
     * @return array{order_count: int, by_currency: list<array{currency: string, order_count: int, amounts: array<TColumn, BigInteger>}>}
     */
    public function calculate(Builder $orders, array $columns): array
    {
        $aggregate = (clone $orders)->toBase()
            ->select('orders.currency')
            ->selectRaw('COUNT(*) AS order_count')
            ->groupBy('orders.currency')
            ->orderBy('orders.currency');

        $this->selectAmountSums($aggregate, $columns);

        $count = 0;
        $byCurrency = [];
        foreach ($aggregate->get() as $row) {
            if (! is_string($row->currency)) {
                throw new UnexpectedValueException('The report query returned an invalid currency.');
            }

            $currencyCount = $this->aggregateInteger($row->order_count)->toInt();
            $amounts = [];
            foreach ($columns as $column) {
                $amounts[] = $this->readAmountSum($aggregate, $row, $column);
            }

            $count += $currencyCount;
            $byCurrency[] = [
                'currency' => $row->currency,
                'order_count' => $currencyCount,
                'amounts' => array_combine($columns, $amounts),
            ];
        }

        return ['order_count' => $count, 'by_currency' => $byCurrency];
    }

    /** @param  non-empty-list<key-of<self::MONEY_COLUMNS>>  $columns */
    public function selectAmountSums(QueryBuilder $aggregate, array $columns): void
    {
        $isSqlite = $aggregate->getConnection() instanceof SQLiteConnection;
        foreach ($columns as $column) {
            $source = self::MONEY_COLUMNS[$column];
            if ($isSqlite) {
                // Sum integral parts separately to keep large totals exact in SQLite.
                $aggregate->selectRaw(
                    "SUM({$source} / ?) AS {$column}_chunks, SUM({$source} % ?) AS {$column}",
                    [self::SQLITE_SUM_CHUNK, self::SQLITE_SUM_CHUNK],
                );
            } else {
                $aggregate->selectRaw("SUM({$source}) AS {$column}");
            }
        }
    }

    /** @param  key-of<self::MONEY_COLUMNS>  $column */
    public function readAmountSum(QueryBuilder $aggregate, stdClass $row, string $column): BigInteger
    {
        $amount = $this->aggregateInteger($row->{$column});
        if ($aggregate->getConnection() instanceof SQLiteConnection) {
            $chunks = $this->aggregateInteger($row->{$column.'_chunks'});

            return $amount->plus($chunks->multipliedBy(self::SQLITE_SUM_CHUNK));
        }

        return $amount;
    }

    private function aggregateInteger(mixed $value): BigInteger
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new UnexpectedValueException('The report query returned a non-integer aggregate.');
        }

        return BigInteger::of($value);
    }
}
