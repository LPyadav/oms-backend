<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Models\User;
use App\Services\Auth\LoginEmail;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

final class StoreUserRequest extends FormRequest
{
    private const int BCRYPT_MAX_BYTES = 72;

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
        $usesBcrypt = Config::string('hashing.driver') === 'bcrypt';
        $configuredLimit = (int) Config::get('hashing.bcrypt.limit');
        $bcryptLimit = $configuredLimit > 0
            ? min(self::BCRYPT_MAX_BYTES, $configuredLimit)
            : self::BCRYPT_MAX_BYTES;

        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'max:254', 'email'],
            'password' => [
                'bail',
                'required',
                'string',
                'max:1024',
                Password::defaults(),
                static function (
                    string $attribute,
                    #[SensitiveParameter] string $value,
                    Closure $fail,
                ) use ($usesBcrypt, $bcryptLimit): void {
                    if (str_contains($value, "\0")) {
                        $fail('The password cannot contain null bytes.');

                        return;
                    }

                    if ($usesBcrypt && strlen($value) > $bcryptLimit) {
                        $fail("The password must not exceed {$bcryptLimit} bytes.");
                    }
                },
            ],
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
