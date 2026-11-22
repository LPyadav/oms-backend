<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $actor_id
 * @property string $target_type
 * @property int $target_id
 * @property string $action
 * @property array<string, mixed>|null $before_values
 * @property array<string, mixed> $after_values
 */
#[Fillable([
    'actor_id',
    'target_type',
    'target_id',
    'action',
    'before_values',
    'after_values',
])]
final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'target_id' => 'integer',
            'before_values' => 'array',
            'after_values' => 'array',
        ];
    }
}
