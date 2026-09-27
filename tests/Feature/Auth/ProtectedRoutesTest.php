<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 07, 4.2 and 8; the roadmap's "done when" of phase 07.

beforeEach(function (): void {
    Notification::fake();
});

dataset('protected routes', [
    'push' => ['POST', 'expenses.sync.push'],
    'pull' => ['GET', 'expenses.sync.pull'],
    'me' => ['GET', 'expenses.me.show'],
    'me update' => ['PATCH', 'expenses.me.update'],
    'me delete' => ['DELETE', 'expenses.me.destroy'],
    'logout' => ['POST', 'expenses.auth.logout'],
    'email resend' => ['POST', 'expenses.auth.email.resend'],
]);

it('answers a missing token with 401 unauthenticated', function (string $method, string $route): void {
    SyncApi::assertError(AuthApi::send($method, $route, '{}'), 401, 'unauthenticated');
})->with('protected routes');

it('answers a refresh token in place of the access token with 401', function (string $method, string $route): void {
    $answer = AuthApi::registerOk();

    SyncApi::assertError(AuthApi::send($method, $route, '{}', AuthApi::refreshToken($answer)), 401, 'unauthenticated');
})->with('protected routes');

it('answers a Sanctum token without the sync ability with 403 forbidden', function (string $method, string $route): void {
    $user = AuthApi::user(AuthApi::registerOk());
    $token = $user->createToken('host-app', ['reports:read'])->plainTextToken;

    SyncApi::assertError(AuthApi::send($method, $route, '{}', $token), 403, 'forbidden');
})->with('protected routes');

it('lets a new user register, push and pull, and never reach another user\'s data', function (): void {
    $anna = AuthApi::registerOk(['email' => 'anna@example.com']);
    $bela = AuthApi::registerOk(['email' => 'bela@example.com', 'deviceId' => AuthApi::OTHER_DEVICE]);
    $category = Records::make('category');

    $pushed = AuthApi::ok(AuthApi::send('POST', 'expenses.sync.push', ['records' => ['category' => [$category]]], AuthApi::accessToken($anna)), 200, 'protocol/push-response');
    $annasPage = AuthApi::ok(AuthApi::send('GET', 'expenses.sync.pull', token: AuthApi::accessToken($anna)), 200, 'protocol/pull-response');
    $belasPage = AuthApi::ok(AuthApi::send('GET', 'expenses.sync.pull', token: AuthApi::accessToken($bela)), 200, 'protocol/pull-response');

    expect(SyncApi::result(AuthApi::results($pushed), 'category')->status)->toBe('accepted')
        ->and(SyncApi::group(SyncApi::records($annasPage), 'category'))->toEqual([$category])
        ->and(SyncApi::group(SyncApi::records($annasPage), 'user'))->toEqual([AuthApi::record($anna)])
        ->and(SyncApi::group(SyncApi::records($belasPage), 'category'))->toBe([])
        ->and(SyncApi::group(SyncApi::records($belasPage), 'user'))->toEqual([AuthApi::record($bela)]);
});
