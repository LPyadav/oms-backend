<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Http\Requests\Auth\PasswordRules;
use App\Models\User;
use App\Services\Auth\LoginEmail;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('create', User::class);
    }

    /**
     * @return array<string, list<string|Password|Closure>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'max:254', 'email'],
            'password' => PasswordRules::newPassword(),
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
     * @return array{name: string, email: string, password: string}
     */
    public function userData(): array
    {
        return [
            'name' => $this->validated('name'),
            'email' => $this->validated('email'),
            'password' => $this->validated('password'),
        ];
    }
}
