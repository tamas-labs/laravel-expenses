<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TamasLabs\LaravelExpenses\Auth\Accounts;
use TamasLabs\LaravelExpenses\Auth\AuthError;
use TamasLabs\LaravelExpenses\Auth\AuthRequest;
use TamasLabs\LaravelExpenses\Auth\EmailVerification;
use TamasLabs\LaravelExpenses\Auth\Throttle;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * `POST {prefix}/auth/register` (spec 07, 5.1).
 */
final class RegisterController
{
    /**
     * @throws AuthError
     */
    public function __invoke(Request $request, AuthRequest $parser, Accounts $accounts, EmailVerification $verification): JsonResponse
    {
        Throttle::register((string) $request->ip());

        $body = $parser->body($request, 'register-request', [
            'email' => AuthRequest::email(...),
            'displayName' => AuthRequest::trim(...),
        ]);

        $signedIn = $accounts->register(
            AuthRequest::string($body, 'email'),
            AuthRequest::string($body, 'password'),
            AuthRequest::string($body, 'displayName'),
            AuthRequest::string($body, 'deviceId'),
            AuthRequest::nullableString($body, 'defaultCurrencyId'),
        );

        // The account exists: a mail that cannot be sent is reported, and
        // the client may ask for another one.
        rescue(static fn () => $verification->send($request, $signedIn->user));

        return new JsonResponse($accounts->payload($signedIn->user, $signedIn->tokens), 201, [], Json::ENCODE_FLAGS);
    }
}
