<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/** @phpstan-import-type UpdateOrderData from OrderService */
final class UpdateOrderRequest extends OrderWriteRequest
{
    public function authorize(): bool
    {
        return $this->actorCan('update');
    }

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'expected_version' => ['bail', 'required', 'integer:strict', 'min:1'],
            'customer_id' => ['bail', 'sometimes', 'required', 'integer:strict', 'min:1'],
            'order_date' => ['bail', 'sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
            'priority' => $this->priorityRules(),
            'order_discount_minor' => $this->discountRules(),
            ...$this->orderTaxRules(),
            ...$this->itemRules(required: false),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            static function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && count($validator->validated()) === 1) {
                    $validator->errors()->add('body', Lang::string('api.orders.fields_required'));
                }
            },
        ];
    }

    /**
     * @return UpdateOrderData
     */
    public function orderData(): array
    {
        return [
            'expected_version' => $this->validated('expected_version'),
            ...array_filter([
                'customer_id' => $this->validated('customer_id'),
                'order_date' => $this->validated('order_date'),
                'priority' => $this->validated('priority'),
                'items' => $this->validated('items'),
                'order_discount_minor' => $this->validated('order_discount_minor'),
            ], static fn (mixed $value): bool => $value !== null),
            ...$this->taxOverride(),
        ];
    }
}
