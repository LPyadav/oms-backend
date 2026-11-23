<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Auth\PasswordRecoveryService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendPasswordResetLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // The broker allows a replacement token after 60 seconds.
    public int $backoff = 65;

    public function __construct(public readonly string $email) {}

    public function handle(PasswordRecoveryService $passwordRecovery): void
    {
        $passwordRecovery->sendLink($this->email);
    }
}
