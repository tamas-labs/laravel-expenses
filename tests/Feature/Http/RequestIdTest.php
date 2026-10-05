<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use TamasLabs\LaravelExpenses\Http\Middleware\AssignRequestId;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\LogFile;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 08, 3.6 and 4: the request id.

const LOWERCASE_UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

beforeEach(function (): void {
    SyncApi::startSequenceAt(1_000_000);
    LogFile::capture();
});

afterEach(function (): void {
    LogFile::forget();
});

it('makes up an id and sends it back', function (): void {
    $first = AuthApi::login('nobody@example.com')->headers->get(AssignRequestId::HEADER);
    $second = AuthApi::login('nobody@example.com')->headers->get(AssignRequestId::HEADER);

    expect($first)->toMatch(LOWERCASE_UUID)
        ->and($second)->toMatch(LOWERCASE_UUID)
        ->and($second)->not->toBe($first);
});

it('takes the client\'s id when it is a UUID, lowercased', function (): void {
    $id = 'A3BB189E-8BF9-4888-9912-ACE4E6543002';

    $response = AuthApi::send('POST', 'expenses.auth.login', ['email' => 'x'], headers: [AssignRequestId::HEADER => $id]);

    expect($response->headers->get(AssignRequestId::HEADER))->toBe(Str::lower($id));
});

it('makes up its own for anything else', function (string $sent): void {
    $response = AuthApi::send('POST', 'expenses.auth.login', ['email' => 'x'], headers: [AssignRequestId::HEADER => $sent]);

    $id = $response->headers->get(AssignRequestId::HEADER);

    expect($id)->toMatch(LOWERCASE_UUID)
        ->and($id)->not->toBe($sent);
})->with(['abc', '12345', 'a3bb189e-8bf9-4888-9912-ace4e654300', "a3bb189e-8bf9-4888-9912-ace4e6543002\n"]);

it('answers every error with it too, even one of the contract header', function (): void {
    $response = AuthApi::send('POST', 'expenses.auth.login', ['email' => 'x'], headers: ['X-Expenses-Contract' => '9.9']);

    $response->assertStatus(400);
    expect($response->headers->get(AssignRequestId::HEADER))->toMatch(LOWERCASE_UUID);
});

it('puts it on every log line of the request', function (): void {
    $user = SyncApi::userWithProfile();
    $id = '5e6f7a8b-9c0d-4e1f-a2b3-c4d5e6f7a8b9';

    $response = SyncApi::push($user, ['category' => [Records::make('category')]], requestId: $id);

    expect($response->headers->get(AssignRequestId::HEADER))->toBe($id)
        ->and(LogFile::extra(LogFile::line('Expenses push.')))->toMatchArray([AssignRequestId::CONTEXT_KEY => $id]);
});
