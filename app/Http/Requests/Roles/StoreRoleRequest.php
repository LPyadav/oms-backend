<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Role;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('create', Role::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        return $this->isJson()
            ? $this->json()->all()
            : $this->request->all();
    }

    public function roleName(): string
    {
        return $this->validated('name');
    }
}
