<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Auth\Throttle;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

use function Pest\Laravel\freezeSecond;
use function Pest\Laravel\travel;

// Spec 07, 5.7.

beforeEach(function (): void {
    Notification::fake();
});

/**
 * @return TestResponse<Response>
 */
function forgot(string $email): TestResponse
{
    return AuthApi::send('POST', 'expenses.auth.password.forgot', ['email' => $email]);
}

/**
 * @return TestResponse<Response>
 */
function resetPassword(string $email, string $token, string $password = 'new horse battery'): TestResponse
{
    return AuthApi::send('POST', 'expenses.auth.password.reset', ['email' => $email, 'token' => $token, 'password' => $password]);
}

/**
 * The reset notification the user got last.
 */
function resetNotification(User $user): ResetPassword
{
    $sent = Notification::sent($user, ResetPassword::class)->last();

    return $sent instanceof ResetPassword ? $sent : throw new RuntimeException('No reset mail was sent.');
}

it('answers 202 for an unknown address and sends nothing', function (): void {
    forgot('nobody@example.com')->assertStatus(202);

    Notification::assertNothingSent();
});

it('mails a reset link built from the configured template', function (): void {
    $user = AuthApi::user(AuthApi::registerOk(['email' => 'anna@example.com']));

    forgot(' Anna@Example.com ')->assertStatus(202);

    $notification = resetNotification($user);

    expect($notification->toMail($user)->actionUrl)
        ->toBe('expenses://reset-password?token='.rawurlencode($notification->token).'&email=anna%40example.com');
});

it('sets the new password and revokes every token and device of the account', function (): void {
    $first = AuthApi::registerOk(['email' => 'anna@example.com']);
    $other = AuthApi::loginOk('anna@example.com', deviceId: AuthApi::OTHER_DEVICE);
    $user = AuthApi::user($first);
    forgot('anna@example.com');

    resetPassword('anna@example.com', resetNotification($user)->token)->assertNoContent();

    foreach ([$first, $other] as $answer) {
        SyncApi::assertError(AuthApi::me(AuthApi::accessToken($answer)), 401, 'unauthenticated');
    }

    SyncApi::assertError(AuthApi::refresh(AuthApi::refreshToken($first)), 401, 'refresh_token_invalid');
    SyncApi::assertError(AuthApi::login('anna@example.com'), 401, 'invalid_credentials');
    AuthApi::loginOk('anna@example.com', 'new horse battery');

    expect(DB::table(PackageConfig::table(AccountStore::DEVICES))->pluck('device_id')->all())->toBe([AuthApi::DEVICE]);
});

it('refuses a wrong or an expired token, and an unknown address', function (): void {
    $user = AuthApi::user(AuthApi::registerOk(['email' => 'anna@example.com']));
    forgot('anna@example.com');
    $token = resetNotification($user)->token;

    SyncApi::assertError(resetPassword('anna@example.com', 'not-the-token'), 422, 'reset_token_invalid');
    SyncApi::assertError(resetPassword('nobody@example.com', $token), 422, 'reset_token_invalid');

    travel(61)->minutes();
    SyncApi::assertError(resetPassword('anna@example.com', $token), 422, 'reset_token_invalid');
});

it('refuses a used token', function (): void {
    $user = AuthApi::user(AuthApi::registerOk(['email' => 'anna@example.com']));
    forgot('anna@example.com');
    $token = resetNotification($user)->token;
    resetPassword('anna@example.com', $token)->assertNoContent();

    SyncApi::assertError(resetPassword('anna@example.com', $token, 'third horse battery'), 422, 'reset_token_invalid');
});

it('checks the new password as the registration does', function (string $password, string $key): void {
    $user = AuthApi::user(AuthApi::registerOk(['email' => 'anna@example.com']));
    forgot('anna@example.com');

    $error = SyncApi::assertError(resetPassword('anna@example.com', resetNotification($user)->token, $password), 422, 'validation_failed');

    expect(SyncApi::issueKeys($error))->toBe([$key]);
})->with([
    'too short' => ['short12', '/password minLength'],
    'over 72 bytes' => [str_repeat('é', 37), '/password maxLength'],
]);

it('brakes the reset mails asked for from one IP', function (): void {
    freezeSecond();

    for ($i = 0; $i < Throttle::PASSWORD_FORGOT_PER_IP; $i++) {
        forgot("nobody{$i}@example.com")->assertStatus(202);
    }

    SyncApi::assertError(forgot('somebody@example.com'), 429, 'too_many_attempts');
});
