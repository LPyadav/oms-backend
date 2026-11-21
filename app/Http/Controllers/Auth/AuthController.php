<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\Auth\AuthenticationResponse;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function login(
        LoginRequest $request,
        AuthenticationService $authenticationService,
        AuthenticationResponse $authenticationResponse,
    ): JsonResponse {
        $result = $authenticationService->login(
            credentials: $request->credentials(),
            deviceName: $request->deviceName(),
        );

        return $authenticationResponse->login($result, $request);
    }

    public function logout(
        Request $request,
        AuthenticationService $authenticationService,
        AuthenticationResponse $authenticationResponse,
    ): JsonResponse {
        $authenticationService->logout($this->authenticatedUser($request));

        return $authenticationResponse->logout();
    }

    public function me(
        Request $request,
        AuthenticationService $authenticationService,
        AuthenticationResponse $authenticationResponse,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);

        return $authenticationResponse->currentUser(
            user: $user,
            request: $request,
            authorization: $authenticationService->currentUserAuthorization($user),
        );
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
