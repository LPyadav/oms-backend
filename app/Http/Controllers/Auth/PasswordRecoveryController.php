<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordRecoveryEmailRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Jobs\SendPasswordResetLink;
use App\Services\Auth\PasswordRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

final class PasswordRecoveryController extends Controller
{
    public function requestLink(PasswordRecoveryEmailRequest $request, ApiResponse $apiResponse): JsonResponse
    {
        SendPasswordResetLink::dispatch($request->email());

        return $apiResponse->success(
            message: Lang::string('api.auth.password_reset_link_requested'),
            status: Response::HTTP_ACCEPTED,
        );
    }

    public function reset(
        ResetPasswordRequest $request,
        PasswordRecoveryService $passwordRecovery,
        ApiResponse $apiResponse,
    ): JsonResponse {
        $passwordRecovery->reset($request->email(), $request->token(), $request->newPassword());

        return $apiResponse->success(message: Lang::string('api.auth.password_reset'));
    }
}
