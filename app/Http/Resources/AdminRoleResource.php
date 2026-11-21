<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @mixin Role */
final class AdminRoleResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, permissions: list<string>}
     */
    public function toArray(Request $request): array
    {
        $permissions = $this->permissions
            ->whereStrict('guard_name', $this->guard_name)
            ->map(static fn (Permission $permission): string => $permission->name)
            ->sort(SORT_STRING);

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'permissions' => array_values($permissions->all()),
        ];
    }
}
