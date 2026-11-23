<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderCustomerContactScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
final class OrderResource extends JsonResource
{
    public function __construct(Order $resource, private readonly OrderCustomerContactScope $contactScope)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'customer_id' => $this->customer_id,
            'assignee_id' => $this->assignee_id,
            'order_date' => $this->order_date->toDateString(),
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'order_discount_minor' => $this->order_discount_minor,
            'discount_total_minor' => $this->discount_total_minor,
            'order_tax_rate_basis_points' => $this->order_tax_rate_basis_points,
            'tax_total_minor' => $this->tax_total_minor,
            'grand_total_minor' => $this->grand_total_minor,
            'version' => $this->version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'customer' => $this->whenLoaded('customer', fn (Customer $customer): array => $this->customerData($customer, $request)),
            'assignee' => $this->whenLoaded('assignee', static fn (User $assignee): array => [
                'id' => $assignee->id,
                'name' => $assignee->name,
            ]),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'notes' => OrderNoteResource::collection($this->whenLoaded('notes')),
            'status_history' => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            'assignment_history' => OrderAssignmentHistoryResource::collection($this->whenLoaded('assignmentHistory')),
            'system_activity' => OrderSystemActivityResource::collection($this->whenLoaded('systemActivity')),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string|null, phone: string|null, address: string|null, is_active: bool}
     */
    private function customerData(Customer $customer, Request $request): array
    {
        $data = (new CustomerResource($customer))->toArray($request);

        if ($this->contactScope->allows($this->assignee_id)) {
            return $data;
        }

        return [...$data, 'email' => null, 'phone' => null, 'address' => null];
    }
}
