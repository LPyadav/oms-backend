<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ShowOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('viewAny', Order::class);
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
