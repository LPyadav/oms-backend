<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
final class OrderAssignmentHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $previousAssignee = $this->before_values['assignee_id'] ?? null;
        $newAssignee = $this->after_values['assignee_id'] ?? null;

        return [
            'id' => $this->id,
            'previous_assignee_id' => is_int($previousAssignee) ? $previousAssignee : null,
            'new_assignee_id' => is_int($newAssignee) ? $newAssignee : null,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->whenLoaded('actor', static fn (User $actor): string => $actor->name, null),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
