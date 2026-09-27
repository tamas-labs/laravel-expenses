<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

use function Pest\Laravel\travelTo;

// Spec 07, 5.5.

beforeEach(function (): void {
    Notification::fake();
    travelTo(CarbonImmutable::parse('2026-09-23T10:00:00.000Z'));
});

/**
 * The registered record with some fields changed.
 *
 * @param  array<string, mixed>  $fields
 */
function changedRecord(stdClass $answer, array $fields): stdClass
{
    $record = clone AuthApi::record($answer);

    foreach ($fields as $field => $value) {
        $record->{$field} = $value;
    }

    return $record;
}

function patchOk(stdClass $answer, stdClass $record): stdClass
{
    return AuthApi::ok(AuthApi::patchMe(AuthApi::accessToken($answer), $record), 200, 'protocol/me-update-result');
}

function currentRecord(stdClass $answer): stdClass
{
    return AuthApi::record(AuthApi::ok(AuthApi::me(AuthApi::accessToken($answer)), 200, 'protocol/me-response'));
}

it('shows the account in the contract\'s shape', function (): void {
    $answer = AuthApi::registerOk();

    $me = AuthApi::ok(AuthApi::me(AuthApi::accessToken($answer)), 200, 'protocol/me-response');

    expect($me->user)->toEqual(AuthApi::record($answer))
        ->and($me->account)->toEqual((object) ['emailVerified' => false]);
});

it('writes a newer profile and numbers it for the other devices', function (): void {
    $answer = AuthApi::registerOk();
    $seq = AuthApi::user($answer)->server_seq;
    assert(is_int($seq));
    $record = changedRecord($answer, ['displayName' => 'Anna Kiss', 'defaultCurrencyId' => Currencies::EUR, 'updatedAt' => '2026-09-23T10:00:00.001Z']);

    expect(patchOk($answer, $record))->toEqual((object) ['status' => 'accepted'])
        ->and(currentRecord($answer))->toEqual($record)
        ->and(AuthApi::user($answer)->server_seq)->toBeGreaterThan($seq);

    $page = AuthApi::ok(AuthApi::send('GET', 'expenses.sync.pull', token: AuthApi::accessToken($answer)), 200, 'protocol/pull-response');

    expect(SyncApi::group(SyncApi::records($page), 'user'))->toEqual([$record]);
});

it('answers an older profile with the server\'s', function (): void {
    $answer = AuthApi::registerOk();

    $result = patchOk($answer, changedRecord($answer, ['displayName' => 'Old name', 'updatedAt' => '2026-09-23T09:59:59.999Z']));

    expect($result)->toEqual((object) ['status' => 'stale', 'record' => AuthApi::record($answer)])
        ->and(currentRecord($answer))->toEqual(AuthApi::record($answer));
});

it('accepts the same profile again without writing it', function (): void {
    $answer = AuthApi::registerOk();
    $seq = AuthApi::user($answer)->server_seq;

    expect(patchOk($answer, AuthApi::record($answer)))->toEqual((object) ['status' => 'accepted'])
        ->and(AuthApi::user($answer)->server_seq)->toBe($seq);
});

it('keeps the server\'s profile on a tie with other content', function (): void {
    $answer = AuthApi::registerOk();

    $result = patchOk($answer, changedRecord($answer, ['displayName' => 'Same time']));

    expect($result)->toEqual((object) ['status' => 'stale', 'record' => AuthApi::record($answer)]);
});

it('refuses to change a read-only field', function (string $field, string $value): void {
    $answer = AuthApi::registerOk();
    $record = changedRecord($answer, [$field => $value, 'updatedAt' => '2026-09-24T10:00:00.000Z']);

    $error = SyncApi::assertError(AuthApi::patchMe(AuthApi::accessToken($answer), $record), 422, 'field_not_writable');

    expect(SyncApi::issueKeys($error))->toBe(["/{$field} readOnly"])
        ->and(currentRecord($answer))->toEqual(AuthApi::record($answer));
})->with([
    'id' => ['id', '7c9e6679-7425-40de-944b-e07fc1f90ae7'],
    'email' => ['email', 'other@example.com'],
    'registeredAt' => ['registeredAt', '2026-09-01T00:00:00.000Z'],
    'createdAt' => ['createdAt', '2026-03-01T10:00:00.000Z'],
]);

it('takes a read-only timestamp written another way as the same', function (): void {
    $answer = AuthApi::registerOk();

    $result = patchOk($answer, changedRecord($answer, ['createdAt' => '2026-09-23T12:00:00+02:00', 'displayName' => 'Anna Kiss', 'updatedAt' => '2026-09-24T10:00:00.000Z']));

    expect($result)->toEqual((object) ['status' => 'accepted']);
});

it('refuses a retired or unknown currency as a new choice', function (string $currencyId, string $key): void {
    DB::transaction(static fn () => Currency::query()->whereKey(Currencies::EUR)->update(['deleted_at' => '2026-09-01 00:00:00.000']));
    $answer = AuthApi::registerOk();

    $error = SyncApi::assertError(AuthApi::patchMe(AuthApi::accessToken($answer), changedRecord($answer, ['defaultCurrencyId' => $currencyId, 'updatedAt' => '2026-09-24T10:00:00.000Z'])), 422, 'validation_failed');

    expect(SyncApi::issueKeys($error))->toBe([$key]);
})->with([
    'retired' => [Currencies::EUR, '/defaultCurrencyId retired'],
    'unknown' => ['00000000-0000-4000-8000-000000000099', '/defaultCurrencyId reference'],
]);

it('lets the account keep a currency retired since', function (): void {
    $answer = AuthApi::registerOk(['defaultCurrencyId' => Currencies::EUR]);
    DB::transaction(static fn () => Currency::query()->whereKey(Currencies::EUR)->update(['deleted_at' => '2026-09-01 00:00:00.000']));

    $result = patchOk($answer, changedRecord($answer, ['displayName' => 'Anna Kiss', 'updatedAt' => '2026-09-24T10:00:00.000Z']));

    expect($result)->toEqual((object) ['status' => 'accepted']);
});

it('refuses a body that is not a user record', function (string $body, string $key): void {
    $answer = AuthApi::registerOk();

    $error = SyncApi::assertError(AuthApi::patchMe(AuthApi::accessToken($answer), $body), 422, 'contract_violation');

    expect($error->resource)->toBe('user')
        ->and(SyncApi::issueKeys($error))->toContain($key);
})->with([
    'not JSON' => ['{"id":', '/ json'],
    'partial' => ['{"displayName":"Anna"}', '/ required'],
]);

it('refuses a blank name', function (): void {
    $answer = AuthApi::registerOk();

    $error = SyncApi::assertError(AuthApi::patchMe(AuthApi::accessToken($answer), changedRecord($answer, ['displayName' => ''])), 422, 'contract_violation');

    expect(SyncApi::issueKeys($error))->toBe(['/displayName minLength']);
});
