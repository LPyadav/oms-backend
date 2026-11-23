<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
final class OrderSystemActivityResource extends JsonResource
{
    /**
     * @return array{id: int, action: string, actor_id: int, actor_name: ?string, created_at: string}
     */
    public function toArray(Request $request): array
    {
        $actor = $this->relationLoaded('actor') ? $this->actor : null;

        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor_id' => $this->actor_id,
            'actor_name' => $actor?->name,
            'created_at' => $this->created_at->toImmutable()->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
