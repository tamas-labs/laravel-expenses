<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * Password reset through Laravel's password broker (spec 07, 5.7): the
 * tokens live in the host's `password_reset_tokens` table, their lifetime
 * and throttle come from its `auth.passwords` settings.
 */
final class Passwords
{
    public function __construct(
        private readonly AccountStore $accounts,
        private readonly DeviceSessions $sessions,
    ) {}

    /**
     * Mails a reset link, if there is such an account. The caller answers the
     * same either way.
     */
    public function sendResetLink(string $email): void
    {
        Password::broker()->sendResetLink(['email' => $email]);
    }

    /**
     * Sets the new password and revokes every token and device of the
     * account: whoever forgot the password may have forgotten it because
     * someone else knows it.
     *
     * @throws AuthError When the password is too long (validation_failed), or the token is expired or wrong.
     */
    public function reset(string $email, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): void
    {
        $issues = PasswordPolicy::issues($password);

        if ($issues !== []) {
            throw AuthError::validationFailed('password-reset-request', $issues);
        }

        $status = Password::broker()->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (mixed $user, #[SensitiveParameter] string $password): void {
                $user = UserModel::of($user);
                $hash = Hash::make($password);

                DB::transaction(function () use ($user, $hash): void {
                    $this->accounts->changePassword($user, $hash);
                    $this->sessions->revokeAll($user);
                }, Accounts::ATTEMPTS);

                Event::dispatch(new PasswordReset($user));
            },
        );

        // An unknown email is a wrong token too: the answer does not tell
        // which addresses have an account.
        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw AuthError::resetTokenInvalid();
        }
    }

    /**
     * The link of the reset mail ({@see ResetPassword::createUrlUsing()}):
     * the configured template with the token and the address filled in.
     */
    public static function url(mixed $notifiable, #[SensitiveParameter] string $token): string
    {
        $email = $notifiable instanceof CanResetPassword ? $notifiable->getEmailForPasswordReset() : '';

        return strtr(PackageConfig::passwordResetUrl(), ['{token}' => rawurlencode($token), '{email}' => rawurlencode($email)]);
    }
}
