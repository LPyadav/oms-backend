<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderStatusHistory */
final class OrderStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'previous_status' => $this->previous_status->value,
            'new_status' => $this->new_status->value,
            'remarks' => $this->remarks,
            'actor' => $this->whenLoaded('actor', static fn (User $actor): array => [
                'id' => $actor->id,
                'name' => $actor->name,
            ]),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
