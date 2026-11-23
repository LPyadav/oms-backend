<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/** @mixin User */
final class OrderAssigneeResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string, roles: list<string>}
     */
    public function toArray(Request $request): array
    {
        $roles = $this->roles
            ->whereInstanceOf(Role::class)
            ->whereStrict('guard_name', Guard::getDefaultName(User::class))
            ->map(static fn (Role $role): string => $role->name)
            ->sort(SORT_STRING);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => array_values($roles->all()),
        ];
    }
}
