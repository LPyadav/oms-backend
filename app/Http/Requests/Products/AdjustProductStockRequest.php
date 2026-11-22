<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class AdjustProductStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('adjustStock', Product::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'stock_quantity' => ['bail', 'required', 'integer:strict', 'min:0', 'max:'.Product::MAX_STOCK_QUANTITY],
            'expected_stock_version' => ['bail', 'required', 'integer:strict', 'min:0'],
            'reason' => ['bail', 'required', 'string', 'max:'.StockMovement::MAX_REASON_LENGTH],
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

    public function stockQuantity(): int
    {
        return $this->validated('stock_quantity');
    }

    public function expectedStockVersion(): int
    {
        return $this->validated('expected_stock_version');
    }

    public function reason(): string
    {
        return $this->validated('reason');
    }
}
