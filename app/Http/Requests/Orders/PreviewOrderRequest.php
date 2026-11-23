<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Services\Orders\OrderService;

/** @phpstan-import-type OrderInputItem from OrderService */
final class PreviewOrderRequest extends OrderWriteRequest
{
    public function authorize(): bool
    {
        return $this->actorCan('create') || $this->actorCan('update');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['bail', 'sometimes', 'required', 'integer:strict', 'min:1'],
            'order_discount_minor' => $this->discountRules(),
            ...$this->orderTaxRules(),
            ...$this->itemRules(required: true),
        ];
    }

    /**
     * @return list<OrderInputItem>
     */
    public function items(): array
    {
        return $this->validated('items');
    }

    public function orderDiscountMinor(): ?int
    {
        return $this->validated('order_discount_minor');
    }

    public function orderId(): ?int
    {
        return $this->validated('order_id');
    }
}
