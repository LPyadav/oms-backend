<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Guard;

final class OrderAssigneeLookup
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(?string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->assignableUsers()->with('roles:id,name,guard_name');

        if ($search !== null) {
            $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereRaw("lower(name) like lower(?) escape '!'", [$pattern]);
        }

        return $query->orderByRaw('lower(name)')
            ->orderBy('id')
            ->paginate(perPage: $perPage, columns: ['id', 'name', 'email'], page: $page);
    }

    public function lockAssignable(int $assigneeId): User
    {
        $assignee = $this->assignableUsers()->sharedLock()->find($assigneeId);
        if ($assignee === null) {
            throw ValidationException::withMessages([
                'assignee_id' => Lang::string('api.orders.assignee_unavailable'),
            ]);
        }

        return $assignee;
    }

    /** @return Builder<User> */
    private function assignableUsers(): Builder
    {
        $guardName = Guard::getDefaultName(User::class);

        return User::query()->where('is_active', true)
            ->whereDoesntHave('roles', static fn (Builder $roles): Builder => $roles
                ->where('name', 'admin')
                ->where('guard_name', $guardName));
    }
}
