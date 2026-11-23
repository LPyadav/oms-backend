<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Closure;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends PasswordRecoveryEmailRequest
{
    /** @return array<string, list<string|Password|Closure>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'token' => ['bail', 'required', 'string', 'max:255'],
            'password' => [...PasswordRules::newPassword(), 'confirmed'],
        ];
    }

    public function token(): string
    {
        return $this->validated('token');
    }

    public function newPassword(): string
    {
        return $this->validated('password');
    }
}
