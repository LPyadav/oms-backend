<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Validator;

final class UpdateProductRequest extends ProductMetadataRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('update', Product::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->metadataRules(creating: false);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            static function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $validator->validated() === []) {
                    $validator->errors()->add('name', Lang::string('api.products.fields_required'));
                }
            },
        ];
    }

    /**
     * @return array{sku?: string, name?: string, price?: string, tax_rate?: string}
     */
    public function productData(): array
    {
        return $this->validated();
    }
}
