<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Auth\Throttle;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;
use TamasLabs\LaravelExpenses\Tests\TestCase;

use function Pest\Laravel\freezeSecond;
use function Pest\Laravel\travelTo;

// Spec 07, 5.1.

beforeEach(function (): void {
    Notification::fake();
});

it('creates the account, binds the device and hands out tokens', function (): void {
    $now = CarbonImmutable::parse('2026-09-23T10:00:00.123Z');
    travelTo($now);

    $answer = AuthApi::registerOk(['email' => 'anna@example.com']);
    $record = AuthApi::record($answer);
    $user = AuthApi::user($answer);
    $stamp = $now->format(RecordMapper::TIMESTAMP_FORMAT);

    TestCase::assertMatchesContract('user', $record);

    expect($record->id)->toBe($user->uuid)
        ->and($record->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($record->email)->toBe('anna@example.com')
        ->and($record->displayName)->toBe('Anna')
        ->and($record->defaultCurrencyId)->toBe(Currencies::HUF)
        ->and([$record->registeredAt, $record->createdAt, $record->updatedAt])->toBe([$stamp, $stamp, $stamp])
        ->and($answer->account)->toEqual((object) ['emailVerified' => false])
        ->and(AuthApi::tokens($answer)->accessTokenExpiresAt)->toBe('2026-09-23T11:00:00.000Z')
        ->and(AuthApi::tokens($answer)->refreshTokenExpiresAt)->toBe('2026-12-22T10:00:00.123Z')
        ->and($user->server_seq)->toBeInt()
        ->and(DB::table(PackageConfig::table(AccountStore::DEVICES))->where('device_id', AuthApi::DEVICE)->value('user_id'))->toBe($user->id);

    AuthApi::ok(AuthApi::me(AuthApi::accessToken($answer)), 200, 'protocol/me-response');
});

it('never stores the password or the refresh token as sent', function (): void {
    $answer = AuthApi::registerOk();
    $user = AuthApi::user($answer);
    $stored = DB::table(PackageConfig::table(AccountStore::REFRESH_TOKENS))->where('user_id', $user->id)->value('token_hash');

    expect($user->getAuthPassword())->not->toBe(AuthApi::PASSWORD)
        ->and(password_verify(AuthApi::PASSWORD, $user->getAuthPassword()))->toBeTrue()
        ->and($stored)->toBe(hash('sha256', AuthApi::refreshToken($answer)));
});

it('hands out a 64-byte base64url refresh token', function (): void {
    $token = AuthApi::refreshToken(AuthApi::registerOk());

    expect($token)->toMatch('/^[A-Za-z0-9_-]{86}$/');
});

it('trims and lower-cases the email and trims the name', function (): void {
    $answer = AuthApi::registerOk(['email' => " Anna.Kiss@Example.COM \u{00A0}", 'displayName' => "  Anna \t"]);

    expect(AuthApi::record($answer)->email)->toBe('anna.kiss@example.com')
        ->and(AuthApi::record($answer)->displayName)->toBe('Anna');
});

it('shows the new account to the next pull', function (): void {
    $answer = AuthApi::registerOk();

    $page = AuthApi::ok(AuthApi::send('GET', 'expenses.sync.pull', token: AuthApi::accessToken($answer)), 200, 'protocol/pull-response');

    expect(SyncApi::group(SyncApi::records($page), 'user'))->toEqual([AuthApi::record($answer)]);
});

it('accepts a registration without a default currency', function (): void {
    expect(AuthApi::record(AuthApi::registerOk(['defaultCurrencyId' => null]))->defaultCurrencyId)->toBeNull();
});

it('refuses a field that breaks the document or the server\'s limits', function (array $overrides, string $key): void {
    $error = SyncApi::assertError(AuthApi::register($overrides), 422, 'validation_failed');

    expect(SyncApi::issueKeys($error))->toContain($key)
        ->and($error->resource)->toBe('register-request')
        ->and(User::query()->count())->toBe(0);
})->with([
    'null device id' => [['deviceId' => null], '/deviceId type'],
    'malformed email' => [['email' => 'anna.example.com'], '/email format'],
    // opis reports the address format, which caps the length too, before maxLength.
    'email over 254' => [['email' => str_repeat('a', 250).'@b.hu'], '/email format'],
    'blank name' => [['displayName' => '   '], '/displayName minLength'],
    'name over 60 after the trim' => [['displayName' => str_repeat('é', 61)], '/displayName maxLength'],
    'password under 8' => [['password' => 'short12'], '/password minLength'],
    'password over 72 bytes' => [['password' => str_repeat('é', 37)], '/password maxLength'],
    'upper-case device id' => [['deviceId' => strtoupper(AuthApi::DEVICE)], '/deviceId pattern'],
    'unknown currency' => [['defaultCurrencyId' => '00000000-0000-4000-8000-000000000099'], '/defaultCurrencyId reference'],
    'unknown field' => [['referrer' => 'friend'], '/ additionalProperties'],
]);

it('refuses a body without a field', function (): void {
    $body = AuthApi::registration();
    unset($body['deviceId']);

    $error = SyncApi::assertError(AuthApi::send('POST', 'expenses.auth.register', $body), 422, 'validation_failed');

    expect(SyncApi::issueKeys($error))->toBe(['/ required']);
});

it('takes a password of exactly 72 bytes', function (): void {
    AuthApi::registerOk(['password' => str_repeat('é', 36)]);
});

it('takes a name of 60 characters with spaces around it', function (): void {
    $answer = AuthApi::registerOk(['displayName' => ' '.str_repeat('é', 60).' ']);

    expect(AuthApi::record($answer)->displayName)->toBe(str_repeat('é', 60));
});

it('refuses a body that is not JSON', function (): void {
    $error = SyncApi::assertError(AuthApi::send('POST', 'expenses.auth.register', '{"email":'), 422, 'validation_failed');

    expect(SyncApi::issueKeys($error))->toBe(['/ json']);
});

it('refuses a retired currency', function (): void {
    DB::transaction(static fn () => Currency::query()->whereKey(Currencies::EUR)->update(['deleted_at' => '2026-09-01 00:00:00.000']));

    $error = SyncApi::assertError(AuthApi::register(['defaultCurrencyId' => Currencies::EUR]), 422, 'validation_failed');

    expect(SyncApi::issue($error)->params)->toEqual((object) ['id' => Currencies::EUR])
        ->and(SyncApi::issueKeys($error))->toBe(['/defaultCurrencyId retired']);
});

it('tells when the email is taken, whatever its case', function (): void {
    AuthApi::registerOk(['email' => 'anna@example.com']);

    SyncApi::assertError(AuthApi::register(['email' => 'ANNA@example.com', 'deviceId' => AuthApi::OTHER_DEVICE]), 422, 'email_taken');

    expect(User::query()->count())->toBe(1);
});

it('brakes registrations from one IP', function (): void {
    freezeSecond();

    for ($i = 0; $i < Throttle::REGISTER_PER_IP; $i++) {
        AuthApi::register(['deviceId' => Str::uuid()->toString()])->assertCreated();
    }

    $response = AuthApi::register(['deviceId' => Str::uuid()->toString()]);

    SyncApi::assertError($response, 429, 'too_many_attempts');
    $response->assertHeader('Retry-After', '3600');
});
