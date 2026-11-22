<?php

declare(strict_types=1);

namespace App\Http\Controllers\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\AssignRolePermissionsRequest;
use App\Http\Requests\Roles\ListRolesRequest;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Responses\Roles\AdminRoleResponse;
use App\Models\User;
use App\Services\Roles\RoleManagementService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminRoleController extends Controller
{
    public function __construct(
        private readonly RoleManagementService $roleManagementService,
        private readonly AdminRoleResponse $adminRoleResponse,
    ) {}

    public function index(ListRolesRequest $request): JsonResponse
    {
        $roles = $this->roleManagementService->paginate(
            $this->actor($request),
            perPage: $request->perPage(),
            page: $request->page(),
        );

        return $this->adminRoleResponse->list($roles, $request);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleManagementService->create(
            $this->actor($request),
            $request->roleName(),
        );

        return $this->adminRoleResponse->create($role, $request);
    }

    public function update(UpdateRoleRequest $request, string $role): JsonResponse
    {
        $updated = $this->roleManagementService->update(
            $this->actor($request),
            $role,
            $request->roleName(),
        );

        return $this->adminRoleResponse->update($updated, $request);
    }

    public function assignPermissions(AssignRolePermissionsRequest $request, string $role): JsonResponse
    {
        $updated = $this->roleManagementService->replacePermissions(
            $this->actor($request),
            $role,
            $request->permissionNames(),
        );

        return $this->adminRoleResponse->permissionsUpdated($updated, $request);
    }

    public function permissions(Request $request): JsonResponse
    {
        $catalog = $this->roleManagementService->permissions($this->actor($request));

        return $this->adminRoleResponse->permissions($catalog);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        return $actor;
    }
}
