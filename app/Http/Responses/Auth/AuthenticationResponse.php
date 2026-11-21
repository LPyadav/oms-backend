<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use SensitiveParameter;

final readonly class AuthenticationResponse
{
    public function __construct(
        private ApiResponse $apiResponse,
    ) {}

    /**
     * @param  array{user: User, access_token: string, expires_at: CarbonImmutable}  $result
     */
    public function login(
        #[SensitiveParameter] array $result,
        Request $request,
    ): JsonResponse {
        return $this->apiResponse->success(
            message: Lang::string('api.auth.logged_in'),
            data: [
                'access_token' => $result['access_token'],
                'expires_at' => $result['expires_at']->utc()->toIso8601String(),
                'user' => (new UserResource($result['user']))->resolve($request),
            ],
        );
    }

    public function currentUser(User $user, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.auth.current_user_retrieved'),
            data: [
                'user' => (new UserResource($user))->resolve($request),
            ],
        );
    }

    public function logout(): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.auth.logged_out'),
        );
    }
}
