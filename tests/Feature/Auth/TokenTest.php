<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

// Spec 07, 4.2 and 5.3.

beforeEach(function (): void {
    Notification::fake();
});

/**
 * The refresh token's row.
 */
function storedRefreshToken(string $token): stdClass
{
    $row = DB::table(PackageConfig::table(AccountStore::REFRESH_TOKENS))->where('token_hash', hash('sha256', $token))->first();

    return $row instanceof stdClass ? $row : throw new RuntimeException('No such refresh token.');
}

function deviceLastSeen(string $deviceId): mixed
{
    return DB::table(PackageConfig::table(AccountStore::DEVICES))->where('device_id', $deviceId)->value('last_seen_at');
}

it('names the access token after the device and gives it the sync ability', function (): void {
    $answer = AuthApi::registerOk();
    $token = PersonalAccessToken::findToken(AuthApi::accessToken($answer));

    expect($token?->getAttribute('name'))->toBe('device:'.AuthApi::DEVICE)
        ->and($token?->getAttribute('abilities'))->toBe([DeviceSessions::ABILITY]);
});

it('refuses an expired access token', function (): void {
    $token = AuthApi::accessToken(AuthApi::registerOk());

    travel(59)->minutes();
    AuthApi::me($token)->assertOk();

    travel(2)->minutes();
    SyncApi::assertError(AuthApi::me($token), 401, 'unauthenticated');
});

it('keeps the two tokens apart', function (): void {
    $answer = AuthApi::registerOk();

    SyncApi::assertError(AuthApi::me(AuthApi::refreshToken($answer)), 401, 'unauthenticated');
    SyncApi::assertError(AuthApi::refresh(AuthApi::accessToken($answer)), 401, 'refresh_token_invalid');
});

it('rotates the refresh token: a new pair in the same family, the old token used', function (): void {
    $answer = AuthApi::registerOk();
    $old = AuthApi::refreshToken($answer);

    $rotated = AuthApi::refreshOk($old);

    expect(AuthApi::refreshToken($rotated))->not->toBe($old)
        ->and(storedRefreshToken($old)->used_at)->not->toBeNull()
        ->and(storedRefreshToken(AuthApi::refreshToken($rotated))->used_at)->toBeNull()
        ->and(storedRefreshToken(AuthApi::refreshToken($rotated))->family)->toBe(storedRefreshToken($old)->family);

    AuthApi::me(AuthApi::accessToken($rotated))->assertOk();
    AuthApi::refreshOk(AuthApi::refreshToken($rotated));
});

it('slides the refresh token\'s expiry with each rotation', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-23T10:00:00.000Z'));
    $token = AuthApi::refreshToken(AuthApi::registerOk());

    travel(89)->days();
    $rotated = AuthApi::refreshOk($token);

    expect(AuthApi::tokens($rotated)->refreshTokenExpiresAt)->toBe('2027-03-21T10:00:00.000Z');
});

it('revokes the family and the device\'s access tokens when a used refresh token comes back', function (): void {
    $answer = AuthApi::registerOk();
    $rotated = AuthApi::refreshOk(AuthApi::refreshToken($answer));

    SyncApi::assertError(AuthApi::refresh(AuthApi::refreshToken($answer)), 401, 'refresh_token_reused');

    SyncApi::assertError(AuthApi::refresh(AuthApi::refreshToken($rotated)), 401, 'refresh_token_invalid');
    SyncApi::assertError(AuthApi::me(AuthApi::accessToken($rotated)), 401, 'unauthenticated');
    SyncApi::assertError(AuthApi::me(AuthApi::accessToken($answer)), 401, 'unauthenticated');
});

it('leaves the account\'s other devices alone on a reuse', function (): void {
    $first = AuthApi::registerOk(['email' => 'anna@example.com']);
    $other = AuthApi::loginOk('anna@example.com', deviceId: AuthApi::OTHER_DEVICE);
    AuthApi::refreshOk(AuthApi::refreshToken($first));

    SyncApi::assertError(AuthApi::refresh(AuthApi::refreshToken($first)), 401, 'refresh_token_reused');

    AuthApi::me(AuthApi::accessToken($other))->assertOk();
    AuthApi::refreshOk(AuthApi::refreshToken($other), AuthApi::OTHER_DEVICE);
});

it('refuses a refresh token from another device', function (): void {
    $answer = AuthApi::registerOk();

    SyncApi::assertError(AuthApi::refresh(AuthApi::refreshToken($answer), AuthApi::OTHER_DEVICE), 401, 'refresh_token_invalid');

    AuthApi::refreshOk(AuthApi::refreshToken($answer));
});

it('refuses an expired refresh token', function (): void {
    $token = AuthApi::refreshToken(AuthApi::registerOk());

    travel(PackageConfig::refreshTtl())->minutes();

    SyncApi::assertError(AuthApi::refresh($token), 401, 'refresh_token_invalid');
});

it('refuses an unknown refresh token', function (): void {
    SyncApi::assertError(AuthApi::refresh('c2VjcmV0LXJlZnJlc2gtdG9rZW4tZXhhbXBsZQ'), 401, 'refresh_token_invalid');
});

it('marks the device as seen on a refresh and on a push', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-23T10:00:00.000Z'));
    $answer = AuthApi::registerOk();

    travelTo(CarbonImmutable::parse('2026-09-24T10:00:00.000Z'));
    $rotated = AuthApi::refreshOk(AuthApi::refreshToken($answer));

    expect(deviceLastSeen(AuthApi::DEVICE))->toBe('2026-09-24 10:00:00.000');

    travelTo(CarbonImmutable::parse('2026-09-24T10:30:00.000Z'));
    AuthApi::ok(AuthApi::send('POST', 'expenses.sync.push', ['records' => ['category' => [Records::make('category')]]], AuthApi::accessToken($rotated)), 200, 'protocol/push-response');

    expect(deviceLastSeen(AuthApi::DEVICE))->toBe('2026-09-24 10:30:00.000');
});
