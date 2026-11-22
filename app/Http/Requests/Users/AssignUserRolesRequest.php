<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Validator;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;
use stdClass;

final class AssignUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('assignRoles', User::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'roles' => ['bail', 'present', 'array', 'list'],
            'roles.*' => ['bail', 'required', 'string', 'max:255', 'distinct:strict'],
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
        $roles = $payload->roles ?? null;

        if ($roles instanceof stdClass) {
            $data['roles'] = $roles;
        }

        return $data;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            static function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $roleNames = $validator->validated()['roles'];

                if ($roleNames === []) {
                    return;
                }

                $guardName = Guard::getDefaultName(User::class);
                $existingRoleNames = Role::query()
                    ->where('guard_name', $guardName)
                    ->whereIn('name', $roleNames)
                    ->get(['name', 'guard_name'])
                    ->whereStrict('guard_name', $guardName)
                    ->pluck('name')
                    ->all();

                foreach (array_keys(array_diff($roleNames, $existingRoleNames)) as $index) {
                    $attribute = "roles.{$index}";
                    $validator->errors()->add(
                        $attribute,
                        Lang::string('validation.exists', ['attribute' => $attribute]),
                    );
                }
            },
        ];
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return $this->validated('roles');
    }
}
