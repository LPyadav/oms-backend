<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

final class ListOrderNotificationsRequest extends FormRequest
{
    /**
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        return array_replace([
            'page' => 1,
            'per_page' => Config::integer('pagination.notifications.default_per_page'),
        ], $this->query->all());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $maxPerPage = Config::integer('pagination.notifications.max_per_page');
        $maxPage = intdiv(PHP_INT_MAX, $maxPerPage);

        return [
            'status' => ['bail', 'sometimes', 'required', 'string', 'in:read,unread'],
            'page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPage],
            'per_page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maxPerPage],
        ];
    }

    /**
     * @return 'read'|'unread'|null
     */
    public function status(): ?string
    {
        return $this->validated('status');
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
