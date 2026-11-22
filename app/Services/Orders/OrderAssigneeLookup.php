<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

final class OrderAssigneeLookup
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(?string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $query = User::query()->where('is_active', true);

        if ($search !== null) {
            $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereRaw("lower(name) like lower(?) escape '!'", [$pattern]);
        }

        return $query->orderByRaw('lower(name)')
            ->orderBy('id')
            ->paginate(perPage: $perPage, columns: ['id', 'name'], page: $page);
    }
}
