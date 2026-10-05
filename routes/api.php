<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Auth\EmailVerification;
use TamasLabs\LaravelExpenses\Http\Controllers\Auth\EmailVerificationController;
use TamasLabs\LaravelExpenses\Http\Controllers\Auth\LoginController;
use TamasLabs\LaravelExpenses\Http\Controllers\Auth\LogoutController;
use TamasLabs\LaravelExpenses\Http\Controllers\Auth\PasswordController;
use TamasLabs\LaravelExpenses\Http\Controllers\Auth\RefreshController;
use TamasLabs\LaravelExpenses\Http\Controllers\Auth\RegisterController;
use TamasLabs\LaravelExpenses\Http\Controllers\MeController;
use TamasLabs\LaravelExpenses\Http\Controllers\PullController;
use TamasLabs\LaravelExpenses\Http\Controllers\PushController;
use TamasLabs\LaravelExpenses\Http\Middleware\AssignRequestId;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureEmailIsVerified;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsurePayloadSize;
use TamasLabs\LaravelExpenses\Http\RateLimits;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/*
 * The client's endpoints (spec 06, 5.5 and spec 07, 5). The configured
 * middleware comes first, so that even a 401 carries the server's contract
 * version. Authentication is the package's own, not a setting: a Sanctum
 * token with the `expenses:access` ability, the middleware named by class so
 * the host needs no alias. There is no way to configure an unauthenticated
 * sync route. So are the request id, the body size limit and the rate
 * limits (spec 08); the provider runs the first two before anything else.
 */
Route::prefix(PackageConfig::routePrefix())
    ->middleware([AssignRequestId::class, ...PackageConfig::routeMiddleware(), EnsurePayloadSize::class])
    ->name('expenses.')
    ->group(static function (): void {
        Route::post('auth/register', RegisterController::class)->name('auth.register');
        Route::post('auth/login', LoginController::class)->name('auth.login');
        Route::post('auth/refresh', RefreshController::class)->name('auth.refresh');
        Route::post('auth/password/forgot', [PasswordController::class, 'forgot'])->name('auth.password.forgot');
        Route::post('auth/password/reset', [PasswordController::class, 'reset'])->name('auth.password.reset');

        Route::middleware(['auth:sanctum', CheckAbilities::class.':'.DeviceSessions::ABILITY])->group(static function (): void {
            // Signing out, the verification mail and deleting the account
            // work before the email is verified.
            Route::post('auth/logout', LogoutController::class)->name('auth.logout');
            Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])->name('auth.email.resend');
            // The password check of the deletion has no brake but this one.
            Route::get('me', [MeController::class, 'show'])->name('me.show')->middleware(RateLimits::middleware(RateLimits::ME));
            Route::delete('me', [MeController::class, 'destroy'])->name('me.destroy')->middleware(RateLimits::middleware(RateLimits::ME));

            Route::middleware(EnsureEmailIsVerified::class)->group(static function (): void {
                Route::patch('me', [MeController::class, 'update'])->name('me.update')->middleware(RateLimits::middleware(RateLimits::ME));
                Route::post('sync/push', PushController::class)->name('sync.push')->middleware(RateLimits::middleware(RateLimits::PUSH));
                Route::get('sync/pull', PullController::class)->name('sync.pull')->middleware(RateLimits::middleware(RateLimits::PULL));
            });
        });
    });

/*
 * The link of the verification mail, opened in a browser: it sends neither a
 * contract header nor a token, so it stays out of the middleware above. The
 * signature, checked by the controller, is its authorization.
 */
Route::prefix(PackageConfig::routePrefix())
    ->middleware([AssignRequestId::class, EnsurePayloadSize::class])
    ->get('auth/email/verify/{uuid}/{hash}', [EmailVerificationController::class, 'verify'])
    ->name(EmailVerification::ROUTE);
