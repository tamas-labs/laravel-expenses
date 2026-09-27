<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 07, 5.4.

beforeEach(function (): void {
    Notification::fake();
});

it('revokes the device\'s tokens and unbinds it', function (): void {
    $answer = AuthApi::registerOk();
    $user = AuthApi::user($answer);

    AuthApi::logout(AuthApi::accessToken($answer))->assertNoContent();

    SyncApi::assertError(AuthApi::me(AuthApi::accessToken($answer)), 401, 'unauthenticated');
    SyncApi::assertError(AuthApi::refresh(AuthApi::refreshToken($answer)), 401, 'refresh_token_invalid');

    expect(DB::table(PackageConfig::table(AccountStore::DEVICES))->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table(PackageConfig::table(AccountStore::REFRESH_TOKENS))->where('user_id', $user->id)->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('leaves the account\'s other devices signed in', function (): void {
    $first = AuthApi::registerOk(['email' => 'anna@example.com']);
    $other = AuthApi::loginOk('anna@example.com', deviceId: AuthApi::OTHER_DEVICE);

    AuthApi::logout(AuthApi::accessToken($first))->assertNoContent();

    AuthApi::me(AuthApi::accessToken($other))->assertOk();
    AuthApi::refreshOk(AuthApi::refreshToken($other), AuthApi::OTHER_DEVICE);

    expect(DB::table(PackageConfig::table(AccountStore::DEVICES))->pluck('device_id')->all())->toBe([AuthApi::OTHER_DEVICE]);
});

it('needs the access token', function (): void {
    SyncApi::assertError(AuthApi::send('POST', 'expenses.auth.logout'), 401, 'unauthenticated');
});
