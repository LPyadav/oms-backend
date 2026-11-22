<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class DeleteOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('delete', Order::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'expected_version' => ['bail', 'required', 'integer', 'min:1'],
        ];
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated('expected_version');
    }
}
