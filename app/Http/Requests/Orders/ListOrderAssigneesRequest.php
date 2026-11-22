<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

final class ListOrderAssigneesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('assign', Order::class);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        $query = $this->query->all();
        if (is_string($query['search'] ?? null)) {
            $search = trim($query['search']);
            $query['search'] = $search === '' ? null : $search;
        }

        return array_replace([
            'page' => 1,
            'per_page' => Config::integer('pagination.order_assignees.default_per_page'),
        ], $query);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $maxPerPage = Config::integer('pagination.order_assignees.max_per_page');
        $maxPage = intdiv(PHP_INT_MAX, $maxPerPage);

        return [
            'search' => ['bail', 'nullable', 'string', 'max:255'],
            'page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPage],
            'per_page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPerPage],
        ];
    }

    public function search(): ?string
    {
        return $this->validated('search');
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
