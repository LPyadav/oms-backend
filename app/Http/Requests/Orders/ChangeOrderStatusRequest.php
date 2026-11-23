<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class ChangeOrderStatusRequest extends OrderWriteRequest
{
    public function authorize(): bool
    {
        return $this->actorCan('changeStatus');
    }

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', Rule::enum(OrderStatus::class)],
            'expected_version' => ['bail', 'required', 'integer:strict', 'min:1'],
            'remarks' => ['bail', 'sometimes', 'nullable', 'string', 'max:'.Config::integer('orders.max_note_length')],
        ];
    }

    public function status(): OrderStatus
    {
        return OrderStatus::from($this->validated('status'));
    }

    public function expectedVersion(): int
    {
        return $this->validated('expected_version');
    }

    public function remarks(): ?string
    {
        return $this->validated('remarks');
    }
}
