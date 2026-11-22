<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

abstract class ProductMetadataRequest extends FormRequest
{
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
     * @return array<string, list<string>>
     */
    protected function metadataRules(bool $creating): array
    {
        $presence = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'sku' => ['bail', ...$presence, 'string', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'name' => ['bail', ...$presence, 'string', 'max:255'],
            'price' => ['bail', ...$presence, 'string', 'regex:/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/'],
            'tax_rate' => ['bail', 'sometimes', 'required', 'string', 'regex:/\A(?:[0-9]{1,2}(?:\.[0-9]{1,2})?|100(?:\.0{1,2})?)\z/'],
            'is_active' => ['missing'],
            'stock_quantity' => ['missing'],
            'stock_version' => ['missing'],
            'opening_stock' => ['missing'],
        ];
    }
}
