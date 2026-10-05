<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use TamasLabs\LaravelExpenses\Auth\Throttle;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * The general rate limits of the sync and profile endpoints (spec 08, 3.1),
 * counted per user. The account endpoints' brakes are {@see Throttle}.
 *
 * Named limiters, so the host can replace one with `RateLimiter::for()`.
 * Above the limit Laravel throws its `ThrottleRequestsException`, which the
 * package renders as 429 `too_many_requests` on its routes.
 */
final class RateLimits
{
    public const string PUSH = 'expenses-push';

    public const string PULL = 'expenses-pull';

    public const string ME = 'expenses-me';

    /**
     * Registers the limiters the host has not registered already.
     */
    public static function register(): void
    {
        foreach ([self::PUSH => 'push', self::PULL => 'pull', self::ME => 'me'] as $name => $setting) {
            if (RateLimiter::limiter($name) === null) {
                RateLimiter::for($name, static fn (Request $request): Limit => Limit::perMinute(PackageConfig::rateLimit($setting))->by(self::key($request)));
            }
        }
    }

    /**
     * The route middleware of a limiter.
     */
    public static function middleware(string $name): string
    {
        return ThrottleRequests::using($name);
    }

    /**
     * The user the request authenticated as; its IP without one (the
     * limited routes all need a token, so only a misconfigured route).
     */
    private static function key(Request $request): string
    {
        $key = $request->user()?->getAuthIdentifier();

        return \is_int($key) || \is_string($key) ? 'user:'.$key : 'ip:'.$request->ip();
    }
}
