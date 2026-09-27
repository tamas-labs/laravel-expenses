<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\LaravelExpenses\Auth\AuthError;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * Holds the sync and the profile changes back until the email address is
 * verified — only when `expenses.auth.require_verified_email` asks for it
 * (spec 07, 5.8). By default an unverified user syncs: an offline-first app
 * must neither lose the user's data nor keep it stuck on the phone.
 */
final class EnsureEmailIsVerified
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (PackageConfig::requireVerifiedEmail() && $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return AuthError::emailNotVerified()->render();
        }

        return $next($request);
    }
}
