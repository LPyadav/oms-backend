<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @mixin AuditLog */
final class OperationalAuditLogResource extends JsonResource
{
    private const array SNAPSHOT_FIELDS = [
        'order' => [
            'order_number', 'customer_id', 'assignee_id', 'currency', 'subtotal_minor',
            'order_discount_minor', 'discount_total_minor', 'order_tax_rate_basis_points', 'tax_total_minor',
            'grand_total_minor', 'version', 'order_date', 'priority', 'status', 'deleted_at',
            'completed_at', 'note_id',
        ],
        'customer' => ['name', 'email', 'phone', 'address', 'is_active'],
        'product' => ['sku', 'name', 'price', 'tax_rate', 'stock_quantity', 'stock_version', 'is_active'],
    ];

    private const array CONTACT_FIELDS = ['email', 'phone', 'address'];

    private const array ITEM_FIELDS = [
        'position', 'product_id', 'product_name', 'product_sku', 'quantity', 'unit_price_minor',
        'subtotal_minor', 'item_discount_minor', 'allocated_order_discount_minor',
        'base_tax_rate_basis_points', 'tax_rate_basis_points', 'tax_minor', 'line_total_minor',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor?->name,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'action' => $this->action,
            'created_at' => $this->created_at->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Request $request): array
    {
        return [
            ...$this->toArray($request),
            'before_values' => $this->before_values === null ? null : $this->snapshot($this->before_values),
            'after_values' => $this->snapshot($this->after_values),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function snapshot(array $values): object
    {
        $snapshot = $this->scalarFields($values, self::SNAPSHOT_FIELDS[$this->target_type] ?? []);
        if ($this->target_type === 'customer') {
            foreach (self::CONTACT_FIELDS as $field) {
                if (isset($snapshot[$field])) {
                    $snapshot[$field] = '[redacted]';
                }
            }
        }

        if ($this->target_type === 'order' && is_array($values['items'] ?? null)) {
            $items = [];
            foreach ($values['items'] as $item) {
                if (is_array($item)) {
                    $items[] = (object) $this->scalarFields($item, self::ITEM_FIELDS);
                }
            }
            $snapshot['items'] = $items;
        }

        return (object) $snapshot;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function scalarFields(array $values, array $fields): array
    {
        return array_filter(
            Arr::only($values, $fields),
            static fn (mixed $value): bool => $value === null || is_scalar($value),
        );
    }
}
