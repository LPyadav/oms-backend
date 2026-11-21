<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AssignUserRolesRequest;
use App\Http\Requests\Users\DisableUserRequest;
use App\Http\Requests\Users\ListUsersRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Responses\Users\AdminUserResponse;
use App\Models\User;
use App\Services\Users\UserManagementService;
use App\Services\Users\UserRoleAssignmentService;
use App\Services\Users\UserStatusService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

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
        );

        return $this->adminUserResponse->list($users, $request);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userManagementService->create(...$request->userData());

        return $this->adminUserResponse->create($user, $request);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->userManagementService->update($user, ...$request->userData());

        return $this->adminUserResponse->update($updated, $request);
    }

    public function disable(
        DisableUserRequest $request,
        User $user,
        UserStatusService $userStatusService,
    ): JsonResponse {
        $disabled = $userStatusService->disable($user);

        return $this->adminUserResponse->update($disabled, $request);
    }

    public function assignRoles(
        AssignUserRolesRequest $request,
        string $user,
        UserRoleAssignmentService $userRoleAssignmentService,
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $target = User::query()->findOrFail($user);
        $updated = $userRoleAssignmentService->replaceRoles(
            actor: $actor,
            target: $target,
            roleNames: $request->roleNames(),
        );

        return $this->adminUserResponse->update($updated, $request);
    }
}
