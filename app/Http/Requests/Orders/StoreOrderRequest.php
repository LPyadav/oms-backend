<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderPriority;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;
use LogicException;

/** @phpstan-import-type CreateOrderData from OrderService */
final class StoreOrderRequest extends OrderWriteRequest
{
    private const string IDEMPOTENCY_HEADER = 'Idempotency-Key';

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
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $values = $this->headers->all(self::IDEMPOTENCY_HEADER);
                $key = $values[0] ?? null;
                if (count($values) !== 1 || $key === null || preg_match('/\A[A-Za-z0-9._~-]{1,128}\z/', $key) !== 1) {
                    $validator->errors()->add('idempotency_key', Lang::string('api.orders.idempotency_key_invalid'));
                }
            },
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->headers->get(self::IDEMPOTENCY_HEADER)
            ?? throw new LogicException('Order creation requires a validated idempotency key.');
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
