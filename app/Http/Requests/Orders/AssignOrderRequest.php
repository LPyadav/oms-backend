<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Validator;
use stdClass;

final class AssignOrderRequest extends OrderWriteRequest
{
    public function authorize(): bool
    {
        return $this->actorCan('assign');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'assignee_id' => ['bail', 'present', 'nullable', 'integer:strict', 'min:1'],
            'expected_version' => ['bail', 'required', 'integer:strict', 'min:1'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if ($validator->errors()->has('assignee_id')) {
                    return;
                }

                // Only explicit JSON null can unassign, even if middleware normalized an empty string.
                $payload = json_decode($this->getContent());
                $assignee = $payload instanceof stdClass ? ($payload->assignee_id ?? null) : null;

                if ($assignee !== null && ! is_int($assignee)) {
                    $validator->errors()->add(
                        'assignee_id',
                        Lang::string('validation.integer', ['attribute' => 'assignee id']),
                    );
                }
            },
        ];
    }

    public function assigneeId(): ?int
    {
        return $this->validated('assignee_id');
    }

    public function expectedVersion(): int
    {
        return $this->validated('expected_version');
    }
}
