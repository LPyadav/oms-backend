<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Models\User;
use App\Services\Auth\LoginEmail;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        return $actor instanceof User
            && $target instanceof User
            && $actor->can('update', $target);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required_without:email', 'filled', 'string', 'max:255'],
            'email' => ['bail', 'required_without:name', 'filled', 'string', 'max:254', 'email'],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        $body = $this->isJson()
            ? $this->json()->all()
            : $this->request->all();

        $email = $body['email'] ?? null;

        if (is_string($email)) {
            $body['email'] = LoginEmail::normalize($email);
        }

        return $body;
    }

    /**
     * @return array{name: string|null, email: string|null}
     */
    public function userData(): array
    {
        return [
            'name' => $this->validated('name'),
            'email' => $this->validated('email'),
        ];
    }
}
