<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Auth\DeleteAccount;
use TamasLabs\LaravelExpenses\Auth\Events\AccountDeleted;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\SyncStore;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 07, 5.6.

beforeEach(function (): void {
    Notification::fake();
});

/**
 * The rows of every table the account owns: the synced ones, the pockets'
 * pivot, the devices and the tokens, and its own user row.
 *
 * @return array<string, int>
 */
function ownedRows(int $userId): array
{
    $tables = [SyncStore::POCKET_CATEGORIES, AccountStore::DEVICES, AccountStore::REFRESH_TOKENS];

    foreach (app(ResourceRegistry::class)->pushable() as $definition) {
        $tables[] = (new $definition->model)->getTable();
    }

    $rows = [];

    foreach ($tables as $table) {
        $name = str_starts_with($table, PackageConfig::tablePrefix()) ? $table : PackageConfig::table($table);
        $rows[$name] = DB::table($name)->where('user_id', $userId)->count();
    }

    $rows['personal_access_tokens'] = PersonalAccessToken::query()->where('tokenable_id', $userId)->count();
    $rows['users'] = DB::table('users')->where('id', $userId)->count();

    return $rows;
}

/**
 * Pushes the same linked records — the same ids — as the account.
 *
 * @param  array<string, list<stdClass>>  $graph
 */
function pushGraph(stdClass $answer, array $graph): void
{
    $results = AuthApi::results(AuthApi::ok(AuthApi::send('POST', 'expenses.sync.push', ['records' => $graph], AuthApi::accessToken($answer)), 200, 'protocol/push-response'));

    expect(array_unique(array_merge(...array_values(SyncApi::statuses($results)))))->toBe(['accepted']);
}

it('deletes the account with every row it owns, and leaves the other accounts\' rows alone', function (): void {
    Event::fake([AccountDeleted::class]);
    $anna = AuthApi::registerOk(['email' => 'anna@example.com']);
    $bela = AuthApi::registerOk(['email' => 'bela@example.com', 'deviceId' => AuthApi::OTHER_DEVICE]);
    $graph = Records::graph();
    pushGraph($anna, $graph);
    pushGraph($bela, $graph);
    $annasId = AuthApi::user($anna)->id;
    $belasId = AuthApi::user($bela)->id;
    $belasRows = ownedRows($belasId);

    AuthApi::send('DELETE', 'expenses.me.destroy', ['password' => AuthApi::PASSWORD], AuthApi::accessToken($anna))->assertNoContent();

    expect(array_values(array_unique(ownedRows($annasId))))->toBe([0])
        ->and(ownedRows($belasId))->toBe($belasRows)
        ->and(array_unique($belasRows))->not->toContain(0);

    Event::assertDispatched(AccountDeleted::class, static fn (AccountDeleted $event): bool => $event->userUuid === AuthApi::record($anna)->id);
});

it('signs out the account\'s other devices', function (): void {
    $first = AuthApi::registerOk(['email' => 'anna@example.com']);
    $other = AuthApi::loginOk('anna@example.com', deviceId: AuthApi::OTHER_DEVICE);

    AuthApi::send('DELETE', 'expenses.me.destroy', ['password' => AuthApi::PASSWORD], AuthApi::accessToken($first))->assertNoContent();

    SyncApi::assertError(AuthApi::me(AuthApi::accessToken($other)), 401, 'unauthenticated');
    SyncApi::assertError(AuthApi::refresh(AuthApi::refreshToken($other), AuthApi::OTHER_DEVICE), 401, 'refresh_token_invalid');
});

it('asks for the password again', function (): void {
    Event::fake([AccountDeleted::class]);
    $answer = AuthApi::registerOk();

    SyncApi::assertError(AuthApi::send('DELETE', 'expenses.me.destroy', ['password' => 'wrong horse battery'], AuthApi::accessToken($answer)), 401, 'invalid_credentials');
    SyncApi::assertError(AuthApi::send('DELETE', 'expenses.me.destroy', '{}', AuthApi::accessToken($answer)), 422, 'validation_failed');

    AuthApi::me(AuthApi::accessToken($answer))->assertOk();
    Event::assertNotDispatched(AccountDeleted::class);
});

it('offers the deletion to the host as a service', function (): void {
    $answer = AuthApi::registerOk();
    pushGraph($answer, Records::graph());
    $user = AuthApi::user($answer);

    app(DeleteAccount::class)($user);

    expect(array_values(array_unique(ownedRows($user->id))))->toBe([0]);
});
