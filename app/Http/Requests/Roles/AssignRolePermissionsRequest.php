<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Role;
use stdClass;

final class AssignRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('assignPermissions', Role::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['bail', 'present', 'array', 'list'],
            'permissions.*' => ['bail', 'required', 'string', 'max:255', 'distinct:strict'],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        if (! $this->isJson()) {
            return $this->request->all();
        }

        // Laravel's associative decoding can turn JSON objects into PHP lists.
        $payload = json_decode($this->getContent(), associative: false);

        if (! $payload instanceof stdClass) {
            return [];
        }

        $data = $this->json()->all();
        $permissions = $payload->permissions ?? null;

        if ($permissions instanceof stdClass) {
            $data['permissions'] = $permissions;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->validated('permissions');
    }
}
