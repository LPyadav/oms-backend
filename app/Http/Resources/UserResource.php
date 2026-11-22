<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /**
     * @param  array{roles: list<string>, permissions: list<string>}|null  $authorization
     */
    public function __construct(
        User $user,
        private readonly ?array $authorization = null,
    ) {
        parent::__construct($user);
    }

    /**
     * @return array{id: int, name: string, email: string, roles?: list<string>, permissions?: list<string>}
     */
    public function toArray(Request $request): array
    {
        $profile = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];

        if ($this->authorization !== null) {
            $profile['roles'] = $this->authorization['roles'];
            $profile['permissions'] = $this->authorization['permissions'];
        }

        return $profile;
    }
}
