<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MOVEMENT_TYPE = 'opening_balance';

    private const REASON = 'Existing balance captured when stock movement recording was introduced.';

    public function up(): void
    {
        $openingBalances = DB::table('products')
            ->select('products.id')
            ->selectRaw(
                'null, ?, 0, products.stock_quantity, products.stock_quantity, products.stock_version, ?',
                [self::MOVEMENT_TYPE, self::REASON],
            )
            ->where('products.stock_quantity', '>', 0)
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('stock_movements')
                    ->whereColumn('stock_movements.product_id', 'products.id')
                    ->whereColumn('stock_movements.stock_version', 'products.stock_version');
            });

        DB::table('stock_movements')->insertUsing([
            'product_id',
            'actor_id',
            'type',
            'quantity_before',
            'quantity_after',
            'quantity_delta',
            'stock_version',
            'reason',
        ], $openingBalances);
    }

    public function down(): void
    {
        // Opening balances are permanent ledger entries that later movements build on.
    }
};
