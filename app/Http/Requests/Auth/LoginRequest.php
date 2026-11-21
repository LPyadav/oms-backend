<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Auth\LoginEmail;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'max:254', 'email'],
            'password' => ['bail', 'required', 'string', 'max:1024'],
            'device_name' => ['bail', 'nullable', 'string', 'max:100'],
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
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => $this->validated('email'),
            'password' => $this->validated('password'),
        ];
    }

    public function deviceName(): ?string
    {
        return $this->validated('device_name');
    }
}
