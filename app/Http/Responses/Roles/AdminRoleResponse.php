<?php

declare(strict_types=1);

namespace App\Http\Responses\Roles;

use App\Http\Resources\AdminRoleResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminRoleResponse
{
    public function __construct(
        private ApiResponse $apiResponse,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, Role>  $roles
     */
    public function list(LengthAwarePaginator $roles, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.roles.list_retrieved'),
            data: [
                'roles' => AdminRoleResource::collection($roles->getCollection())->resolve($request),
            ],
            meta: [
                'current_page' => $roles->currentPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
                'last_page' => $roles->lastPage(),
            ],
        );
    }

    public function create(Role $role, Request $request): JsonResponse
    {
        return $this->role($role, $request, Lang::string('api.roles.created'), Response::HTTP_CREATED);
    }

    public function update(Role $role, Request $request): JsonResponse
    {
        return $this->role($role, $request, Lang::string('api.roles.updated'));
    }

    public function permissionsUpdated(Role $role, Request $request): JsonResponse
    {
        return $this->role($role, $request, Lang::string('api.roles.permissions_updated'));
    }

    /**
     * @param  array{permissions: list<string>, admin_only_permissions: list<string>}  $catalog
     */
    public function permissions(array $catalog): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.permissions.list_retrieved'),
            data: $catalog,
        );
    }

    private function role(
        Role $role,
        Request $request,
        string $message,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        return $this->apiResponse->success(
            message: $message,
            data: ['role' => (new AdminRoleResource($role))->resolve($request)],
            status: $status,
        );
    }
}
