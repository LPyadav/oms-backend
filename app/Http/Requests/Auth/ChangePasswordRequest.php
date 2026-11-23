<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
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

    /**
     * @return array<string, list<string|Password|Closure>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'max:1024'],
            'password' => [...PasswordRules::newPassword(), 'different:current_password', 'confirmed'],
        ];
    }

    public function currentPassword(): string
    {
        return $this->validated('current_password');
    }

    public function newPassword(): string
    {
        return $this->validated('password');
    }
}
