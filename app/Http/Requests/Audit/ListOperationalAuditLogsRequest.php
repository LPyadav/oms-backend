<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListOperationalAuditLogsRequest extends FormRequest
{
    private const int MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('viewAny', AuditLog::class);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        return array_replace(['page' => 1, 'per_page' => 25], $this->query->all());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'nullable', 'string', 'max:64'],
            'actor_id' => ['bail', 'nullable', 'integer', 'min:1'],
            'target_type' => ['bail', 'nullable', 'string', Rule::in(AuditLog::OPERATIONAL_TARGETS)],
            'target_id' => ['bail', 'nullable', 'integer', 'min:1'],
            'date_from' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'date_to' => [
                'bail', 'nullable', 'date_format:Y-m-d',
                Rule::when($this->query('date_from') !== null, 'after_or_equal:date_from'),
            ],
            'page' => ['bail', 'required', 'integer', 'min:1', 'max:'.intdiv(PHP_INT_MAX, self::MAX_PER_PAGE)],
            'per_page' => ['bail', 'required', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function page(): int
    {
        return (int) $this->validated('page');
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page');
    }
}
