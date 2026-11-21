<?php

declare(strict_types=1);

namespace App\Http\Responses\Users;

use App\Http\Resources\AdminUserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminUserResponse
{
    public function __construct(
        private ApiResponse $apiResponse,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, User>  $users
     */
    public function list(LengthAwarePaginator $users, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.users.list_retrieved'),
            data: [
                'users' => AdminUserResource::collection($users->getCollection())->resolve($request),
            ],
            meta: [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        );
    }

    public function create(User $user, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.users.created'),
            data: [
                'user' => (new AdminUserResource($user))->resolve($request),
            ],
            status: Response::HTTP_CREATED,
        );
    }

    public function update(User $user, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.users.updated'),
            data: [
                'user' => (new AdminUserResource($user))->resolve($request),
            ],
        );
    }
}
