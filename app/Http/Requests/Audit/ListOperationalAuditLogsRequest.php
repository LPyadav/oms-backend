<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

final class ListOperationalAuditLogsRequest extends FormRequest
{
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
        return array_replace([
            'page' => 1,
            'per_page' => Config::integer('pagination.operational_audit_logs.default_per_page'),
        ], $this->query->all());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $maxPerPage = Config::integer('pagination.operational_audit_logs.max_per_page');
        $maxPage = intdiv(PHP_INT_MAX, $maxPerPage);

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
            'page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPage],
            'per_page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPerPage],
        ];
    }

    /** @return array<string, int|string> */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only(['action', 'actor_id', 'target_type', 'target_id', 'date_from', 'date_to']),
            static fn (mixed $value): bool => is_int($value) || is_string($value),
        );
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
