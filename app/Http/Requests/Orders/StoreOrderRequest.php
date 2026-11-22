<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderPriority;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rules\Enum;

/** @phpstan-import-type CreateOrderData from OrderService */
final class StoreOrderRequest extends OrderWriteRequest
{
    public function authorize(): bool
    {
        return $this->actorCan('create');
    }

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['bail', 'required', 'integer:strict', 'min:1'],
            'order_date' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
            'priority' => $this->priorityRules(),
            'assignee_id' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'min:1'],
            'note' => ['bail', 'sometimes', 'nullable', 'string', 'max:'.Config::integer('orders.max_note_length')],
            'order_discount_minor' => $this->discountRules(),
            ...$this->itemRules(required: true),
        ];
    }

    /**
     * @return CreateOrderData
     */
    public function orderData(): array
    {
        return [
            'customer_id' => $this->validated('customer_id'),
            'order_date' => $this->validated('order_date'),
            'items' => $this->validated('items'),
            'priority' => $this->validated('priority', OrderPriority::Normal->value),
            'order_discount_minor' => $this->validated('order_discount_minor', 0),
            'assignee_id' => $this->validated('assignee_id'),
            'note' => $this->validated('note'),
        ];
    }
}
