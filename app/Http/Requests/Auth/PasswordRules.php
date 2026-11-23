<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

final class PasswordRules
{
    private const int BCRYPT_MAX_BYTES = 72;

    /**
     * @return list<string|Password|Closure>
     */
    public static function newPassword(): array
    {
        $usesBcrypt = Config::string('hashing.driver') === 'bcrypt';
        $configuredLimit = (int) Config::get('hashing.bcrypt.limit');
        $bcryptLimit = $configuredLimit > 0
            ? min(self::BCRYPT_MAX_BYTES, $configuredLimit)
            : self::BCRYPT_MAX_BYTES;

        return [
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
        ];
    }
}
