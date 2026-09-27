<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TamasLabs\LaravelExpenses\Auth\Accounts;
use TamasLabs\LaravelExpenses\Auth\AuthError;
use TamasLabs\LaravelExpenses\Auth\AuthRequest;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * `POST {prefix}/auth/login` (spec 07, 5.2).
 */
final class LoginController
{
    /**
     * @throws AuthError
     */
    public function __invoke(Request $request, AuthRequest $parser, Accounts $accounts): JsonResponse
    {
        $body = $parser->body($request, 'login-request', ['email' => AuthRequest::email(...)]);

        $signedIn = $accounts->login(
            AuthRequest::string($body, 'email'),
            AuthRequest::string($body, 'password'),
            AuthRequest::string($body, 'deviceId'),
            (string) $request->ip(),
        );

        return new JsonResponse($accounts->payload($signedIn->user, $signedIn->tokens), 200, [], Json::ENCODE_FLAGS);
    }
}
