<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class DisableUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('changeStatus', User::class);
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
