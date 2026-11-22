<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderPriority;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderPricingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;
use stdClass;

abstract class OrderWriteRequest extends FormRequest
{
    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        if (! $this->isJson()) {
            return [];
        }

        $payload = json_decode($this->getContent(), associative: false);
        if (! $payload instanceof stdClass) {
            return [];
        }

        $data = $this->json()->all();
        $items = $payload->items ?? null;

        // Decode the original shape so a numeric-key object cannot pass the list rule.
        if ($items instanceof stdClass) {
            $data['items'] = $items;
        }

        return $data;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (array_diff_key($this->validationData(), $validator->getRules()) !== []) {
                    $validator->errors()->add('body', Lang::string('api.orders.unexpected_fields'));
                }
            },
        ];
    }

    protected function actorCan(string $ability): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can($ability, Order::class);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function itemRules(bool $required): array
    {
        return [
            'items' => [
                'bail',
                $required ? 'required' : 'sometimes',
                'array',
                'list',
                'min:1',
                'max:'.Config::integer('orders.max_items'),
            ],
            'items.*' => ['bail', 'required', 'array:product_id,quantity,item_discount_minor'],
            'items.*.product_id' => ['bail', 'required', 'integer:strict', 'min:1', 'distinct:strict'],
            'items.*.quantity' => ['bail', 'required', 'integer:strict', 'min:1', 'max:'.Product::MAX_STOCK_QUANTITY],
            'items.*.item_discount_minor' => $this->discountRules(),
        ];
    }

    /**
     * @return list<string>
     */
    protected function discountRules(): array
    {
        return ['bail', 'sometimes', 'integer:strict', 'min:0', 'max:'.OrderPricingService::MAX_MINOR_UNITS];
    }

    /**
     * @return list<string|Enum>
     */
    protected function priorityRules(): array
    {
        return ['bail', 'sometimes', 'required', 'string', Rule::enum(OrderPriority::class)];
    }
}
