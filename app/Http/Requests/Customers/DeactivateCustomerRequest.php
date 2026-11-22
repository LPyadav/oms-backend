<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class DeactivateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('deactivate', Customer::class);
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
