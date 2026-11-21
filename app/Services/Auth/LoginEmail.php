<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Str;

final class LoginEmail
{
    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }
}
