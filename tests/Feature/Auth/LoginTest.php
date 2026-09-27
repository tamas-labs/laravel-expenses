<?php

declare(strict_types=1);

use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Auth\Throttle;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\RecordingHasher;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

use function Pest\Laravel\freezeSecond;

// Spec 07, 4.1, 4.5 and 5.2.

beforeEach(function (): void {
    Notification::fake();
});

/**
 * The devices of the account, by device id.
 *
 * @return list<string>
 */
function devicesOf(User $user): array
{
    return array_values(array_map(
        SyncApi::string(...),
        DB::table(PackageConfig::table(AccountStore::DEVICES))->where('user_id', $user->id)->orderBy('device_id')->pluck('device_id')->all(),
    ));
}

it('signs a device in with new tokens', function (): void {
    $registered = AuthApi::registerOk(['email' => 'anna@example.com']);

    $answer = AuthApi::loginOk('anna@example.com', deviceId: AuthApi::OTHER_DEVICE);

    expect(AuthApi::record($answer))->toEqual(AuthApi::record($registered))
        ->and(AuthApi::accessToken($answer))->not->toBe(AuthApi::accessToken($registered))
        ->and(devicesOf(AuthApi::user($answer)))->toEqualCanonicalizing([AuthApi::DEVICE, AuthApi::OTHER_DEVICE]);

    AuthApi::me(AuthApi::accessToken($answer))->assertOk();
    AuthApi::me(AuthApi::accessToken($registered))->assertOk();
});

it('normalizes the email as the registration does', function (): void {
    AuthApi::registerOk(['email' => 'anna@example.com']);

    AuthApi::loginOk(" ANNA@Example.com\t");
});

it('answers every failure with the same bytes', function (): void {
    AuthApi::registerOk(['email' => 'anna@example.com']);
    AuthApi::registerOk(['email' => 'bela@example.com', 'password' => 'bela horse battery', 'deviceId' => AuthApi::OTHER_DEVICE]);

    $responses = [
        'unknown account' => AuthApi::login('nobody@example.com'),
        'wrong password' => AuthApi::login('anna@example.com', 'wrong horse battery'),
        'another account\'s password' => AuthApi::login('anna@example.com', 'bela horse battery'),
    ];

    foreach ($responses as $response) {
        SyncApi::assertError($response, 401, 'invalid_credentials');
    }

    expect(array_unique(array_map(static fn ($response): string => (string) $response->getContent(), $responses)))->toHaveCount(1);
});

it('checks the password against a decoy hash when there is no such account', function (): void {
    $hasher = new RecordingHasher(app(HashManager::class));
    Hash::swap($hasher);

    SyncApi::assertError(AuthApi::login('nobody@example.com'), 401, 'invalid_credentials');

    expect($hasher->checked)->toHaveCount(1)
        ->and(Hash::info($hasher->checked[0])['algoName'] ?? null)->toBe('bcrypt');
});

it('refuses the sixth attempt after five failures for the address, even with the right password', function (): void {
    freezeSecond();
    AuthApi::registerOk(['email' => 'anna@example.com']);

    for ($i = 0; $i < Throttle::LOGIN_PER_EMAIL; $i++) {
        SyncApi::assertError(AuthApi::login('anna@example.com', 'wrong horse battery'), 401, 'invalid_credentials');
    }

    $response = AuthApi::login('anna@example.com');

    SyncApi::assertError($response, 429, 'too_many_attempts');
    $response->assertHeader('Retry-After', '60');
});

it('refuses an IP after twenty failures, whatever the address', function (): void {
    freezeSecond();

    for ($i = 0; $i < Throttle::LOGIN_PER_IP; $i++) {
        SyncApi::assertError(AuthApi::login("nobody{$i}@example.com"), 401, 'invalid_credentials');
    }

    SyncApi::assertError(AuthApi::login('somebody@example.com'), 429, 'too_many_attempts');
});

it('clears the address\'s failures on a successful sign-in', function (): void {
    AuthApi::registerOk(['email' => 'anna@example.com']);

    foreach ([4, 0, 4] as $failures) {
        for ($i = 0; $i < $failures; $i++) {
            SyncApi::assertError(AuthApi::login('anna@example.com', 'wrong horse battery'), 401, 'invalid_credentials');
        }

        AuthApi::loginOk('anna@example.com');
    }
});

it('moves a device bound to another account, revoking that account\'s tokens on it', function (): void {
    $anna = AuthApi::registerOk(['email' => 'anna@example.com']);
    $bela = AuthApi::registerOk(['email' => 'bela@example.com', 'deviceId' => AuthApi::OTHER_DEVICE]);

    $moved = AuthApi::loginOk('bela@example.com', deviceId: AuthApi::DEVICE);

    SyncApi::assertError(AuthApi::me(AuthApi::accessToken($anna)), 401, 'unauthenticated');
    SyncApi::assertError(AuthApi::refresh(AuthApi::refreshToken($anna)), 401, 'refresh_token_invalid');
    AuthApi::me(AuthApi::accessToken($moved))->assertOk();
    AuthApi::me(AuthApi::accessToken($bela))->assertOk();

    expect(devicesOf(AuthApi::user($anna)))->toBe([])
        ->and(devicesOf(AuthApi::user($bela)))->toEqualCanonicalizing([AuthApi::DEVICE, AuthApi::OTHER_DEVICE]);
});

it('replaces the device\'s tokens when it signs in again', function (): void {
    $first = AuthApi::registerOk(['email' => 'anna@example.com']);

    $second = AuthApi::loginOk('anna@example.com');

    SyncApi::assertError(AuthApi::me(AuthApi::accessToken($first)), 401, 'unauthenticated');
    SyncApi::assertError(AuthApi::refresh(AuthApi::refreshToken($first)), 401, 'refresh_token_invalid');
    AuthApi::me(AuthApi::accessToken($second))->assertOk();
});

it('completes the profile of an account made outside the package on its first sign-in', function (): void {
    $user = User::factory()->createOne(['email' => 'old@example.com']);

    $answer = AuthApi::loginOk('old@example.com', 'password');

    expect(AuthApi::record($answer)->id)->toBe($user->uuid)
        ->and($user->fresh()?->server_seq)->toBeInt();
});

it('refuses a body that breaks the document', function (): void {
    $error = SyncApi::assertError(AuthApi::send('POST', 'expenses.auth.login', ['email' => 'anna@example.com', 'password' => '']), 422, 'validation_failed');

    expect(SyncApi::issueKeys($error))->toContain('/password minLength');
});
