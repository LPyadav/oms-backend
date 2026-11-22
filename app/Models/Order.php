<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;

/**
 * @property-read CarbonImmutable $order_date
 * @property-write CarbonImmutable|string $order_date
 * @property OrderPriority $priority
 * @property OrderStatus $status
 * @property CarbonImmutable|null $completed_at
 * @property int $subtotal_minor
 * @property int $order_discount_minor
 * @property int $discount_total_minor
 * @property int $tax_total_minor
 * @property int $grand_total_minor
 * @property int $version
 */
#[Fillable(['customer_id', 'order_date', 'priority'])]
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<OrderNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'target_id')
            ->where('target_type', 'order')
            ->where('action', 'order.assigned')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function systemActivity(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'target_id')
            ->select(['id', 'target_id', 'actor_id', 'action', 'created_at'])
            ->where('target_type', 'order')
            ->whereIn('action', ['order.created', 'order.updated'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(Config::integer('orders.system_activity_limit'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'immutable_date',
            'priority' => OrderPriority::class,
            'status' => OrderStatus::class,
            'subtotal_minor' => 'integer',
            'order_discount_minor' => 'integer',
            'discount_total_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'grand_total_minor' => 'integer',
            'version' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
