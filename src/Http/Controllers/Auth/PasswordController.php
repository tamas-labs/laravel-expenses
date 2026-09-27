<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use TamasLabs\LaravelExpenses\Auth\AuthError;
use TamasLabs\LaravelExpenses\Auth\AuthRequest;
use TamasLabs\LaravelExpenses\Auth\MailLocale;
use TamasLabs\LaravelExpenses\Auth\Passwords;
use TamasLabs\LaravelExpenses\Auth\Throttle;

/**
 * `POST {prefix}/auth/password/forgot` and `…/reset` (spec 07, 5.7).
 */
final class PasswordController
{
    /**
     * Always 202, whether the account exists or not.
     *
     * @throws AuthError
     */
    public function forgot(Request $request, AuthRequest $parser, Passwords $passwords): Response
    {
        Throttle::passwordForgot((string) $request->ip());

        $body = $parser->body($request, 'password-forgot-request', ['email' => AuthRequest::email(...)]);

        MailLocale::send($request, static fn () => $passwords->sendResetLink(AuthRequest::string($body, 'email')));

        return new Response(status: 202);
    }

    /**
     * @throws AuthError
     */
    public function reset(Request $request, AuthRequest $parser, Passwords $passwords): Response
    {
        $body = $parser->body($request, 'password-reset-request', ['email' => AuthRequest::email(...)]);

        $passwords->reset(
            AuthRequest::string($body, 'email'),
            AuthRequest::string($body, 'token'),
            AuthRequest::string($body, 'password'),
        );

        return new Response(status: 204);
    }
}
