<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccessAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccessAuditLog */
final class AccessAuditLogResource extends JsonResource
{
    /**
     * @return array{id: int, actor_id: int, actor_email: string|null, target_type: string, target_id: int, action: string, created_at: string, before_values: object|null, after_values: object}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'actor_email' => $this->actor?->email,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'action' => $this->action,
            'created_at' => $this->created_at->toImmutable()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'before_values' => $this->before_values === null ? null : (object) $this->before_values,
            'after_values' => (object) $this->after_values,
        ];
    }
}
