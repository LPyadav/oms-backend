<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Auth\LoginEmail;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PasswordRecoveryEmailRequest extends FormRequest
{
    /** @return array<string, list<string|Password|Closure>> */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'max:254', 'email'],
        ];
    }

    /** @return array<array-key, mixed> */
    public function validationData(): array
    {
        $body = $this->isJson() ? $this->json()->all() : $this->request->all();
        $email = $body['email'] ?? null;

        if (is_string($email)) {
            $body['email'] = LoginEmail::normalize($email);
        }

        return $body;
    }

    public function email(): string
    {
        return $this->validated('email');
    }
}
