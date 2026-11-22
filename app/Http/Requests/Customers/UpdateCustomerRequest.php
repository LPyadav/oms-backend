<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Validator;

final class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('update', Customer::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'sometimes', 'required', 'string', 'max:255'],
            'email' => ['bail', 'nullable', 'string', 'max:254', 'email'],
            'phone' => ['bail', 'nullable', 'string', 'max:32'],
            'address' => ['bail', 'nullable', 'string', 'max:1000'],
            'is_active' => ['missing'],
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
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            static function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $validator->validated() === []) {
                    $validator->errors()->add('name', Lang::string('api.customers.fields_required'));
                }
            },
        ];
    }

    /**
     * @return array{name?: string, email?: string|null, phone?: string|null, address?: string|null}
     */
    public function customerData(): array
    {
        return $this->validated();
    }
}
