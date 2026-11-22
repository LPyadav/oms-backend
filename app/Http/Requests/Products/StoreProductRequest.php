<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Models\User;

final class StoreProductRequest extends ProductMetadataRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('create', Product::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->metadataRules(creating: true);
    }

    /**
     * @return array{sku: string, name: string, price: string, tax_rate: string}
     */
    public function productData(): array
    {
        return [
            'sku' => $this->validated('sku'),
            'name' => $this->validated('name'),
            'price' => $this->validated('price'),
            'tax_rate' => $this->validated('tax_rate', '0.00'),
        ];
    }
}
