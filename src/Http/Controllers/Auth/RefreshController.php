<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TamasLabs\LaravelExpenses\Auth\AuthError;
use TamasLabs\LaravelExpenses\Auth\AuthRequest;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * `POST {prefix}/auth/refresh` (spec 07, 5.3). The refresh token comes in
 * the body, never in the `Authorization` header, so no Sanctum middleware
 * takes it for an access token.
 */
final class RefreshController
{
    /**
     * @throws AuthError
     */
    public function __invoke(Request $request, AuthRequest $parser, DeviceSessions $sessions): JsonResponse
    {
        $body = $parser->body($request, 'refresh-request');

        $tokens = $sessions->rotate(AuthRequest::string($body, 'refreshToken'), AuthRequest::string($body, 'deviceId'));

        return new JsonResponse(['tokens' => $tokens], 200, [], Json::ENCODE_FLAGS);
    }
}
