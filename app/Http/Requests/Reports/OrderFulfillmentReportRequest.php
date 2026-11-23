<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Enums\OrderStatus;
use App\Services\Reports\OrderFulfillmentReport;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\RequiredIf;

/** @phpstan-import-type OrderFulfillmentFilters from OrderFulfillmentReport */
final class OrderFulfillmentReportRequest extends ReportRequest
{
    /** @return array<string, list<string|Enum|RequiredIf>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['bail', 'sometimes', 'required', 'string', Rule::enum(OrderStatus::class)],
            'customer_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
            'assignee_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
        ];
    }

    /** @return OrderFulfillmentFilters */
    public function filters(): array
    {
        $status = $this->validated('status');
        $customerId = $this->validated('customer_id');
        $assigneeId = $this->validated('assignee_id');

        return [
            ...$this->dateAndPageFilters(),
            'status' => $status === null ? null : OrderStatus::from($status),
            'customer_id' => $customerId === null ? null : (int) $customerId,
            'assignee_id' => $assigneeId === null ? null : (int) $assigneeId,
        ];
    }
}
