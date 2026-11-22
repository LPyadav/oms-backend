<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use stdClass;

final class AssignUserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('assignPermissions', User::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'direct_permissions' => ['bail', 'present', 'array', 'list'],
            'direct_permissions.*' => ['bail', 'required', 'string', 'max:255', 'distinct:strict'],
            'inherited_permissions' => ['missing'],
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
        $permissions = $payload->direct_permissions ?? null;
        if ($permissions instanceof stdClass) {
            $data['direct_permissions'] = $permissions;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->validated('direct_permissions');
    }
}
