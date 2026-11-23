<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Models\AccessAuditLog;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ListAccessAuditLogsRequest extends FormRequest
{
    private const int DEFAULT_PER_PAGE = 25;

    private const int MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('viewAny', AccessAuditLog::class);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        return array_replace([
            'page' => 1,
            'per_page' => self::DEFAULT_PER_PAGE,
        ], $this->query->all());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $maxPage = intdiv(PHP_INT_MAX, self::MAX_PER_PAGE);

        return [
            'page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPage],
            'per_page' => ['bail', 'required', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'action' => ['bail', 'nullable', 'string', 'max:64'],
            'actor_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
            'target_type' => ['bail', 'nullable', 'string', 'in:user,role'],
            'target_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, int|string>
     */
    public function filters(): array
    {
        return array_filter($this->safe()->only(['action', 'actor_id', 'target_type', 'target_id']),
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
