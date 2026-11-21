<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;

final class ListRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('viewAny', Role::class);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        return array_replace([
            'page' => 1,
            'per_page' => Config::integer('pagination.roles.default_per_page'),
        ], $this->query->all());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $maxPerPage = Config::integer('pagination.roles.max_per_page');
        // Keep the offset calculation within PHP's integer range.
        $maxPage = intdiv(PHP_INT_MAX, $maxPerPage);

        return [
            'page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPage],
            'per_page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPerPage],
        ];
    }

    public function page(): int
    {
        return (int) $this->validated('page');
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page');
    }
}
