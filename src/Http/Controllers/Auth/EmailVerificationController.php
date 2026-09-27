<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use TamasLabs\LaravelExpenses\Auth\AuthError;
use TamasLabs\LaravelExpenses\Auth\EmailVerification;
use TamasLabs\LaravelExpenses\Auth\Throttle;
use TamasLabs\LaravelExpenses\Auth\UserModel;

/**
 * `GET {prefix}/auth/email/verify/{uuid}/{hash}` and
 * `POST {prefix}/auth/email/resend` (spec 07, 5.8).
 */
final class EmailVerificationController
{
    /**
     * The link of the mail, opened in a browser: no token and no contract
     * header, the signature authorizes it. Redirects to the configured page
     * either way, with `status=invalid` when the link is not valid.
     */
    public function verify(Request $request, EmailVerification $verification, string $uuid, string $hash): RedirectResponse
    {
        return new RedirectResponse(EmailVerification::redirectUrl($verification->verify($request, $uuid, $hash)));
    }

    /**
     * 202; a verified address gets no mail.
     *
     * @throws AuthError
     */
    public function resend(Request $request, EmailVerification $verification): Response
    {
        $user = UserModel::of($request->user());
        $key = $user->getAuthIdentifier();

        Throttle::emailResend(\is_int($key) || \is_string($key) ? $key : '');

        if (! $user->hasVerifiedEmail()) {
            $verification->send($request, $user);
        }

        return new Response(status: 202);
    }
}
