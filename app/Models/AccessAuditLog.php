<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'actor_id',
    'target_type',
    'target_id',
    'action',
    'before_values',
    'after_values',
])]
final class AccessAuditLog extends Model
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
