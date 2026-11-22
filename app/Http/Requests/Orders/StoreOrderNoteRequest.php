<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Support\Facades\Config;

final class StoreOrderNoteRequest extends OrderWriteRequest
{
    public function authorize(): bool
    {
        return $this->actorCan('addNote');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['bail', 'required', 'string', 'max:'.Config::integer('orders.max_note_length')],
        ];
    }

    public function body(): string
    {
        return $this->validated('body');
    }
}
