<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use TamasLabs\LaravelExpenses\Auth\EmailVerification;
use TamasLabs\LaravelExpenses\Auth\Throttle;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;
use TamasLabs\LaravelExpenses\Tests\TestCase;

use function Pest\Laravel\freezeSecond;
use function Pest\Laravel\get;
use function Pest\Laravel\travel;

// Spec 07, 5.8.

beforeEach(function (): void {
    Notification::fake();
});

/**
 * The link of the verification mail the user got last.
 */
function verificationLink(User $user): string
{
    $sent = Notification::sent($user, VerifyEmail::class)->last();

    return $sent instanceof VerifyEmail ? $sent->toMail($user)->actionUrl : throw new RuntimeException('No verification mail was sent.');
}

/**
 * A validly signed link with the given route parameters.
 */
function signedLink(string $uuid, string $hash): string
{
    return URL::temporarySignedRoute(EmailVerification::ROUTE, Date::now()->addHour(), ['uuid' => $uuid, 'hash' => $hash]);
}

it('mails a verification link after the registration', function (): void {
    $user = AuthApi::user(AuthApi::registerOk());

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);

    expect(verificationLink($user))->toContain('/api/expenses/auth/email/verify/'.$user->uuid.'/');
});

it('verifies the address through the signed link and leads to the configured page', function (): void {
    $answer = AuthApi::registerOk();
    $user = AuthApi::user($answer);

    get(verificationLink($user))->assertRedirect(TestCase::EMAIL_VERIFIED_URL);

    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue()
        ->and(AuthApi::ok(AuthApi::me(AuthApi::accessToken($answer)), 200, 'protocol/me-response')->account)->toEqual((object) ['emailVerified' => true]);

    // Opened again: still valid.
    get(verificationLink($user))->assertRedirect(TestCase::EMAIL_VERIFIED_URL);
});

it('leads to the page with status=invalid for a link that is not valid', function (Closure $link): void {
    $user = AuthApi::user(AuthApi::registerOk(['email' => 'anna@example.com']));

    get(SyncApi::string($link($user)))->assertRedirect(TestCase::EMAIL_VERIFIED_URL.'?status=invalid');

    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
})->with([
    'tampered signature' => [static fn (User $user): string => verificationLink($user).'0'],
    'tampered path' => [static fn (User $user): string => str_replace($user->uuid, '7c9e6679-7425-40de-944b-e07fc1f90ae7', verificationLink($user))],
    'unsigned' => [static fn (User $user): string => explode('?', verificationLink($user))[0]],
    'another address' => [static fn (User $user): string => signedLink($user->uuid, hash('sha256', 'other@example.com'))],
    'unknown account' => [static fn (User $user): string => signedLink('7c9e6679-7425-40de-944b-e07fc1f90ae7', hash('sha256', 'anna@example.com'))],
    'expired' => [static function (User $user): string {
        $link = verificationLink($user);
        travel(Config::integer('auth.verification.expire', 60) + 1)->minutes();

        return $link;
    }],
]);

it('appends the status to a page that has a query already', function (): void {
    Config::set('expenses.auth.email_verified_url', 'https://app.example.com/done?from=mail');

    expect(EmailVerification::redirectUrl(false))->toBe('https://app.example.com/done?from=mail&status=invalid');
});

it('mails the link again on request, but not to a verified address', function (): void {
    $answer = AuthApi::registerOk();
    $user = AuthApi::user($answer);

    AuthApi::send('POST', 'expenses.auth.email.resend', token: AuthApi::accessToken($answer))->assertStatus(202);
    Notification::assertSentToTimes($user, VerifyEmail::class, 2);

    get(verificationLink($user));
    AuthApi::send('POST', 'expenses.auth.email.resend', token: AuthApi::accessToken($answer))->assertStatus(202);
    Notification::assertSentToTimes($user, VerifyEmail::class, 2);
});

it('brakes the verification mails asked for', function (): void {
    freezeSecond();
    $token = AuthApi::accessToken(AuthApi::registerOk());

    for ($i = 0; $i < Throttle::EMAIL_RESEND_PER_USER; $i++) {
        AuthApi::send('POST', 'expenses.auth.email.resend', token: $token)->assertStatus(202);
    }

    SyncApi::assertError(AuthApi::send('POST', 'expenses.auth.email.resend', token: $token), 429, 'too_many_attempts');
});

it('lets an unverified user sync by default', function (): void {
    $token = AuthApi::accessToken(AuthApi::registerOk());

    AuthApi::send('POST', 'expenses.sync.push', ['records' => ['category' => [Records::make('category')]]], $token)->assertOk();
    AuthApi::send('GET', 'expenses.sync.pull', token: $token)->assertOk();
});

it('holds the sync and the profile changes back until the address is verified, when configured to', function (): void {
    Config::set('expenses.auth.require_verified_email', true);
    $answer = AuthApi::registerOk();
    $token = AuthApi::accessToken($answer);
    $push = ['records' => ['category' => [Records::make('category')]]];

    SyncApi::assertError(AuthApi::send('POST', 'expenses.sync.push', $push, $token), 403, 'email_not_verified');
    SyncApi::assertError(AuthApi::send('GET', 'expenses.sync.pull', token: $token), 403, 'email_not_verified');
    SyncApi::assertError(AuthApi::patchMe($token, AuthApi::record($answer)), 403, 'email_not_verified');
    AuthApi::me($token)->assertOk();
    AuthApi::send('POST', 'expenses.auth.email.resend', token: $token)->assertStatus(202);

    get(verificationLink(AuthApi::user($answer)));

    AuthApi::send('POST', 'expenses.sync.push', $push, $token)->assertOk();
    AuthApi::logout($token)->assertNoContent();
});

it('signs out and resets the password without a verified address', function (): void {
    Config::set('expenses.auth.require_verified_email', true);
    $answer = AuthApi::registerOk(['email' => 'anna@example.com']);

    AuthApi::send('POST', 'expenses.auth.password.forgot', ['email' => 'anna@example.com'])->assertStatus(202);
    AuthApi::logout(AuthApi::accessToken($answer))->assertNoContent();
});
