<?php

declare(strict_types=1);

namespace App\Policies;

final readonly class OrderCustomerContactScope
{
    public function __construct(
        public bool $viewAll = false,
        public ?int $assignedActorId = null,
    ) {}

    public function allows(?int $assigneeId): bool
    {
        return $this->viewAll
            || ($this->assignedActorId !== null && $this->assignedActorId === $assigneeId);
    }
}
