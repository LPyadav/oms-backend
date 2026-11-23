<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AssignUserPermissionsRequest;
use App\Http\Requests\Users\AssignUserRolesRequest;
use App\Http\Requests\Users\DisableUserRequest;
use App\Http\Requests\Users\EnableUserRequest;
use App\Http\Requests\Users\ListUsersRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Responses\Users\AdminUserResponse;
use App\Models\User;
use App\Services\Users\UserManagementService;
use App\Services\Users\UserPermissionAssignmentService;
use App\Services\Users\UserRoleAssignmentService;
use App\Services\Users\UserStatusService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AdminUserController extends Controller
{
    public function __construct(
        private readonly UserManagementService $userManagementService,
        private readonly AdminUserResponse $adminUserResponse,
    ) {}

    public function index(ListUsersRequest $request): JsonResponse
    {
        $users = $this->userManagementService->paginate(
            perPage: $request->perPage(),
            page: $request->page(),
            search: $request->search(),
            isActive: $request->isActive(),
            role: $request->role(),
        );

        return $this->adminUserResponse->list($users, $request);
    }

    public function show(Request $request, string $user): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        return $this->adminUserResponse->show($this->userManagementService->find($user), $request);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userManagementService->create($this->actor($request), ...$request->userData());

        return $this->adminUserResponse->create($user, $request);
    }

    public function update(UpdateUserRequest $request, string $user): JsonResponse
    {
        $updated = $this->userManagementService->update($this->actor($request), $user, ...$request->userData());

        return $this->adminUserResponse->update($updated, $request);
    }

    public function disable(
        DisableUserRequest $request,
        string $user,
        UserStatusService $userStatusService,
    ): JsonResponse {
        $disabled = $userStatusService->disable($this->actor($request), $user);

        return $this->adminUserResponse->update($disabled, $request);
    }

    public function enable(
        EnableUserRequest $request,
        string $user,
        UserStatusService $userStatusService,
    ): JsonResponse {
        $enabled = $userStatusService->enable($this->actor($request), $user);

        return $this->adminUserResponse->enable($enabled, $request);
    }

    public function assignRoles(
        AssignUserRolesRequest $request,
        string $user,
        UserRoleAssignmentService $userRoleAssignmentService,
    ): JsonResponse {
        $actor = $this->actor($request);
        $target = User::query()->findOrFail($user);
        $updated = $userRoleAssignmentService->replaceRoles(
            actor: $actor,
            target: $target,
            roleNames: $request->roleNames(),
        );

        return $this->adminUserResponse->update($updated, $request);
    }

    public function showPermissions(
        Request $request,
        string $user,
        UserPermissionAssignmentService $userPermissionAssignmentService,
    ): JsonResponse {
        $permissions = $userPermissionAssignmentService->show($this->actor($request), $user);

        return $this->adminUserResponse->permissions($permissions);
    }

    public function assignPermissions(
        AssignUserPermissionsRequest $request,
        string $user,
        UserPermissionAssignmentService $userPermissionAssignmentService,
    ): JsonResponse {
        $permissions = $userPermissionAssignmentService->replace(
            actor: $this->actor($request),
            targetId: $user,
            permissionNames: $request->permissionNames(),
        );

        return $this->adminUserResponse->permissions($permissions, updated: true);
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
