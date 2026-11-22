<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderPriority;
use App\Enums\OrderSortField;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/** @phpstan-import-type OrderListCriteria from OrderListQuery */
final class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('viewAny', Order::class);
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
            'sort' => OrderSortField::OrderDate->value,
            'direction' => 'desc',
            'page' => 1,
            'per_page' => Config::integer('pagination.orders.default_per_page'),
        ], $query);
    }

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        $maxPerPage = Config::integer('pagination.orders.max_per_page');
        $maxPage = intdiv(PHP_INT_MAX, $maxPerPage);
        $dateRules = [
            'bail', 'sometimes', 'required', 'date_format:Y-m-d',
            'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31',
        ];

        return [
            'search' => ['bail', 'nullable', 'string', 'max:255'],
            'status' => ['bail', 'sometimes', 'required', 'string', Rule::enum(OrderStatus::class)],
            'priority' => ['bail', 'sometimes', 'required', 'string', Rule::enum(OrderPriority::class)],
            'customer_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
            'assignee_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
            'date_from' => $dateRules,
            'date_to' => [
                ...$dateRules,
                ...($this->query->has('date_from') ? ['after_or_equal:date_from'] : []),
            ],
            'sort' => ['bail', 'required', 'string', Rule::enum(OrderSortField::class)],
            'direction' => ['bail', 'required', 'string', 'in:asc,desc'],
            'page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPage],
            'per_page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPerPage],
        ];
    }

    /**
     * @return OrderListCriteria
     */
    public function criteria(): array
    {
        $status = $this->validated('status');
        $priority = $this->validated('priority');
        $customerId = $this->validated('customer_id');
        $assigneeId = $this->validated('assignee_id');

        return [
            'search' => $this->validated('search'),
            'status' => $status === null ? null : OrderStatus::from($status),
            'priority' => $priority === null ? null : OrderPriority::from($priority),
            'customer_id' => $customerId === null ? null : (int) $customerId,
            'assignee_id' => $assigneeId === null ? null : (int) $assigneeId,
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
            'sort' => OrderSortField::from($this->validated('sort')),
            'direction' => $this->validated('direction') === 'asc' ? 'asc' : 'desc',
            'per_page' => (int) $this->validated('per_page'),
            'page' => (int) $this->validated('page'),
        ];
    }
}
