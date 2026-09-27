<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Illuminate\Support\Facades\RateLimiter;

/**
 * The brakes of the account endpoints (spec 07, 4.5, 5.7 and 5.8), on
 * Laravel's rate limiter. The general rate limit is spec 08's.
 */
final class Throttle
{
    /** Failed sign-ins per minute for one email address from one IP. */
    public const int LOGIN_PER_EMAIL = 5;

    /** Failed sign-ins per minute from one IP. */
    public const int LOGIN_PER_IP = 20;

    /** Registrations per hour from one IP. */
    public const int REGISTER_PER_IP = 10;

    /** Password reset mails asked for per hour from one IP. */
    public const int PASSWORD_FORGOT_PER_IP = 5;

    /** Verification mails asked for per hour by one user. */
    public const int EMAIL_RESEND_PER_USER = 3;

    private const int MINUTE = 60;

    private const int HOUR = 3600;

    /**
     * @throws AuthError When the email or the IP failed to sign in too often (too_many_attempts).
     */
    public static function ensureLoginAllowed(string $email, string $ip): void
    {
        foreach ([[self::loginKey($email, $ip), self::LOGIN_PER_EMAIL], [self::loginIpKey($ip), self::LOGIN_PER_IP]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw AuthError::tooManyAttempts(RateLimiter::availableIn($key));
            }
        }
    }

    public static function loginFailed(string $email, string $ip): void
    {
        RateLimiter::hit(self::loginKey($email, $ip), self::MINUTE);
        RateLimiter::hit(self::loginIpKey($ip), self::MINUTE);
    }

    public static function loginSucceeded(string $email, string $ip): void
    {
        RateLimiter::clear(self::loginKey($email, $ip));
    }

    /**
     * @throws AuthError When the IP registered too often.
     */
    public static function register(string $ip): void
    {
        self::attempt('expenses:register:'.$ip, self::REGISTER_PER_IP, self::HOUR);
    }

    /**
     * @throws AuthError When the IP asked for too many reset mails.
     */
    public static function passwordForgot(string $ip): void
    {
        self::attempt('expenses:password-forgot:'.$ip, self::PASSWORD_FORGOT_PER_IP, self::HOUR);
    }

    /**
     * @throws AuthError When the user asked for too many verification mails.
     */
    public static function emailResend(int|string $userKey): void
    {
        self::attempt('expenses:email-resend:'.$userKey, self::EMAIL_RESEND_PER_USER, self::HOUR);
    }

    /**
     * Counts one attempt, refusing it when the limit is reached.
     *
     * @throws AuthError
     */
    private static function attempt(string $key, int $max, int $decaySeconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw AuthError::tooManyAttempts(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    /**
     * The address is hashed: the cache store is no place for it.
     */
    private static function loginKey(string $email, string $ip): string
    {
        return 'expenses:login:'.hash('sha256', $email.'|'.$ip);
    }

    private static function loginIpKey(string $ip): string
    {
        return 'expenses:login-ip:'.$ip;
    }
}
