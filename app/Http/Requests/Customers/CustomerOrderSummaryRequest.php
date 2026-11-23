<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class CustomerOrderSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('view', Customer::class)
            && $actor->can('viewAny', Order::class);
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
