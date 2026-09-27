<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * Email verification with Laravel's `MustVerifyEmail` (spec 07, 5.8). The
 * link is a signed, expiring URL that carries the contract id (`uuid`)
 * instead of the table's own key.
 */
final class EmailVerification
{
    /**
     * The name of the route the mail links to.
     */
    public const string ROUTE = 'expenses.auth.email.verify';

    public function __construct(private readonly AccountStore $accounts) {}

    /**
     * Sends the verification mail in the request's language, through the
     * user model (the host may replace the notification there).
     */
    public function send(Request $request, MustVerifyEmail $user): void
    {
        MailLocale::send($request, static fn () => $user->sendEmailVerificationNotification());
    }

    /**
     * Verifies the address of an opened link.
     *
     * @return bool Whether the link was valid: signed, not expired, for an
     *              existing account and its current address.
     */
    public function verify(Request $request, string $uuid, string $hash): bool
    {
        if (! $request->hasValidSignature()) {
            return false;
        }

        $user = $this->accounts->findByUuid($uuid);

        if ($user === null || ! hash_equals(self::hash($user), $hash)) {
            return false;
        }

        if ($this->accounts->markEmailVerified($user)) {
            Event::dispatch(new Verified($user));
        }

        return true;
    }

    /**
     * Where the browser goes once the link is opened.
     */
    public static function redirectUrl(bool $verified): string
    {
        $url = PackageConfig::emailVerifiedUrl();

        return $verified ? $url : $url.(str_contains($url, '?') ? '&' : '?').'status=invalid';
    }

    /**
     * The link of the verification mail ({@see VerifyEmail::createUrlUsing()}).
     */
    public static function url(mixed $notifiable): string
    {
        $user = UserModel::of($notifiable);

        return URL::temporarySignedRoute(
            self::ROUTE,
            Date::now()->addMinutes(Config::integer('auth.verification.expire', 60)),
            ['uuid' => $user->getAttribute('uuid'), 'hash' => self::hash($user)],
        );
    }

    /**
     * Binds the link to the address it was sent to: a changed address makes
     * an earlier link invalid.
     */
    private static function hash(MustVerifyEmail $user): string
    {
        return hash('sha256', $user->getEmailForVerification());
    }
}
