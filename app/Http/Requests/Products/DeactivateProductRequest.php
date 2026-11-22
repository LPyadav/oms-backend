<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class DeactivateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('deactivate', Product::class);
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
