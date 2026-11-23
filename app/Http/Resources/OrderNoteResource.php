<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderNote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderNote */
final class OrderNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_at' => $this->created_at->toISOString(),
            'author' => $this->whenLoaded('author', static fn (User $author): array => [
                'id' => $author->id,
                'name' => $author->name,
            ]),
        ];
    }
}
