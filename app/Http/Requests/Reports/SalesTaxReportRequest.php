<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Services\Reports\SalesTaxReport;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\RequiredIf;

/** @phpstan-import-type SalesTaxReportFilters from SalesTaxReport */
final class SalesTaxReportRequest extends ReportRequest
{
    protected const string MINIMUM_DATE = '1000-01-02';

    protected const string MAXIMUM_DATE = '9999-12-30';

    /** @return array<string, list<string|Enum|RequiredIf>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'customer_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
            'product_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
        ];
    }

    /** @return SalesTaxReportFilters */
    public function filters(): array
    {
        $customerId = $this->validated('customer_id');
        $productId = $this->validated('product_id');

        return [
            ...$this->dateAndPageFilters(),
            'customer_id' => $customerId === null ? null : (int) $customerId,
            'product_id' => $productId === null ? null : (int) $productId,
        ];
    }
}
