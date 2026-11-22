<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('create', Customer::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'nullable', 'string', 'max:254', 'email'],
            'phone' => ['bail', 'nullable', 'string', 'max:32'],
            'address' => ['bail', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        return $this->isJson()
            ? $this->json()->all()
            : $this->request->all();
    }

    /**
     * @return array{name: string, email: string|null, phone: string|null, address: string|null}
     */
    public function customerData(): array
    {
        return [
            'name' => $this->validated('name'),
            'email' => $this->validated('email'),
            'phone' => $this->validated('phone'),
            'address' => $this->validated('address'),
        ];
    }
}
