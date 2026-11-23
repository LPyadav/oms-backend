<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Models\User;
use App\Policies\DashboardPolicy;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

class DashboardRequest extends FormRequest
{
    public function authorize(DashboardPolicy $policy): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $policy->view($actor);
    }

    public function actor(): User
    {
        $actor = $this->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        return $actor;
    }
}
