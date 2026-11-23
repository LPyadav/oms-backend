<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\OrderItem;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class OrderItemSearchCache
{
    private const string STORE = 'order_item_search';

    private const string GENERATION_KEY = 'orders:item-search:v1:generation';

    private const string OVERSIZED = 'oversized';

    /**
     * @param  Builder<OrderItem>  $matches
     * @return Builder<OrderItem>|list<int>
     */
    public function matchingOrderIds(Builder $matches, string $pattern): Builder|array
    {
        if (! Config::boolean('orders.item_search_cache.enabled')
            || $matches->getModel()->getConnection()->transactionLevel() > 0) {
            return $matches;
        }

        $secret = Config::get('app.key');
        if (! is_string($secret) || $secret === '') {
            return $matches;
        }

        try {
            $cache = Cache::store(self::STORE);
            $generation = $this->generation($cache);
            if ($generation === null) {
                return $matches;
            }

            $key = 'orders:item-search:v1:'.$generation.':'.hash_hmac('sha256', $pattern, $secret);
            $cached = $cache->get($key);
        } catch (Throwable) {
            return $matches;
        }

        if ($cached === self::OVERSIZED) {
            return $matches;
        }

        $limit = Config::integer('orders.item_search_cache.max_ids');
        $cachedIds = $this->ids($cached, $limit);
        if ($cachedIds !== null) {
            return $cachedIds;
        }

        $rows = (clone $matches)->distinct()->limit($limit + 1)->pluck('order_items.order_id')->all();
        $ids = $this->ids($rows, $limit);

        try {
            $cache->put($key, $ids ?? self::OVERSIZED, Config::integer('orders.item_search_cache.ttl_seconds'));
        } catch (Throwable) {
            // The current request can still use the IDs read from the database.
        }

        return $ids ?? $matches;
    }

    public function invalidateAfterCommit(): void
    {
        if (! Config::boolean('orders.item_search_cache.enabled')) {
            return;
        }

        DB::afterCommit(static function (): void {
            try {
                Cache::store(self::STORE)->forget(self::GENERATION_KEY);
            } catch (Throwable) {
                // Existing entries expire automatically when Redis is available again.
            }
        });
    }

    private function generation(Repository $cache): ?string
    {
        $generation = $cache->get(self::GENERATION_KEY);
        if (is_string($generation)) {
            return $generation;
        }

        $newGeneration = (string) Str::uuid();
        if ($cache->add(self::GENERATION_KEY, $newGeneration, 3600)) {
            return $newGeneration;
        }

        $generation = $cache->get(self::GENERATION_KEY);

        return is_string($generation) ? $generation : null;
    }

    /** @return list<int>|null */
    private function ids(mixed $value, int $limit): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $limit) {
            return null;
        }

        $ids = [];
        foreach ($value as $id) {
            $parsed = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($parsed)) {
                return null;
            }

            $ids[] = $parsed;
        }

        return $ids;
    }
}
