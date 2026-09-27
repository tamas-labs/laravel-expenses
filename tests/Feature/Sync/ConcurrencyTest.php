<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Contract\ContractVersion;
use TamasLabs\LaravelExpenses\Database\SyncSequence;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\Cursor;
use TamasLabs\LaravelExpenses\Sync\PullHandler;
use TamasLabs\LaravelExpenses\Sync\PushHandler;
use TamasLabs\LaravelExpenses\Sync\PushResult;
use TamasLabs\LaravelExpenses\Sync\RecordStatus;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\Records;

// Spec 06, 4.1–4.2 and 7 (ConcurrencyTest). Two real MySQL connections to a
// database of its own, whose commits the other connection sees: no
// transaction-wrapped test database here. The first connection runs a push;
// at a chosen query of it, while its transaction is open, the second
// connection runs its own work. A lock the second has to wait for makes it
// fail after a second (innodb_lock_wait_timeout), which proves the wait.

const CONCURRENCY_DATABASE = 'expenses_concurrency_test';

const MAIN_CONNECTION = 'concurrency_main';

const OTHER_CONNECTION = 'concurrency_other';

beforeEach(function (): void {
    DB::statement('DROP DATABASE IF EXISTS `'.CONCURRENCY_DATABASE.'`');
    DB::statement('CREATE DATABASE `'.CONCURRENCY_DATABASE.'`');

    foreach ([MAIN_CONNECTION, OTHER_CONNECTION] as $name) {
        Config::set("database.connections.{$name}", [...Config::array('database.connections.mysql'), 'database' => CONCURRENCY_DATABASE]);
    }

    Config::set('database.default', MAIN_CONNECTION);
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);

    DB::connection(OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

afterEach(function (): void {
    DB::purge(MAIN_CONNECTION);
    DB::purge(OTHER_CONNECTION);
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.CONCURRENCY_DATABASE.'`');
});

/**
 * Runs the callback once, on the first query of the main connection the
 * pattern matches, right after the query ran.
 */
function whenMainRuns(string $pattern, Closure $callback): void
{
    $fired = false;

    DB::listen(static function (QueryExecuted $query) use ($pattern, $callback, &$fired): void {
        if ($fired || $query->connectionName !== MAIN_CONNECTION || preg_match($pattern, $query->sql) !== 1) {
            return;
        }

        $fired = true;
        $callback();
    });
}

/**
 * Runs the callback with the other connection as the default one.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function onOtherConnection(Closure $callback): mixed
{
    DB::setDefaultConnection(OTHER_CONNECTION);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection(MAIN_CONNECTION);
    }
}

/**
 * @param  list<stdClass>  $categories
 */
function pushCategories(User $user, array $categories): PushResult
{
    return app(PushHandler::class)->push($user, Json::decode(Json::encode(['records' => ['category' => $categories]])), ContractVersion::current());
}

function waitsForLock(Closure $callback): bool
{
    try {
        $callback();
    } catch (QueryException $exception) {
        return str_contains($exception->getMessage(), 'Lock wait timeout exceeded');
    }

    return false;
}

function categorySeq(User $user, string $id): int
{
    return Category::query()->ownedBy($user)->whereKey($id)->sole()->server_seq;
}

it('runs the pushes of two users side by side, with distinct sequence numbers', function (): void {
    $first = User::factory()->createOne();
    $second = User::factory()->createOne();
    $firstCategory = Records::make('category');
    $secondCategory = Records::make('category');
    $secondResult = null;

    // The first push holds its user's lock: the second user's push runs to
    // its commit meanwhile, sequence numbers and all.
    whenMainRuns('/for update/', static function () use ($second, $secondCategory, &$secondResult): void {
        $secondResult = onOtherConnection(static fn (): PushResult => pushCategories($second, [$secondCategory]));
    });

    $firstResult = pushCategories($first, [$firstCategory]);

    expect($firstResult->results['category'][0]->status)->toBe(RecordStatus::Accepted)
        ->and($secondResult?->results['category'][0]->status)->toBe(RecordStatus::Accepted)
        ->and(categorySeq($second, RecordFields::id($secondCategory)))->toBe(4)
        ->and(categorySeq($first, RecordFields::id($firstCategory)))->toBe(5)
        ->and(SyncSequence::state()['value'])->toBe(5);
});

it('never lets a pull pass the rows of a push that has not committed yet', function (): void {
    $user = User::factory()->createOne();
    $other = User::factory()->createOne();
    pushCategories($user, [Records::make('category')]);
    $slow = Records::make('category');
    $pulled = null;
    $blocked = false;

    // The push has reserved its sequence number and not committed yet.
    whenMainRuns('/^update `'.PackageConfig::table(SyncSequence::TABLE).'`/', static function () use ($user, $other, &$pulled, &$blocked): void {
        // Another device of the user pulls: it gets what has committed, and a
        // cursor short of the pending push.
        $pulled = onOtherConnection(static fn () => app(PullHandler::class)->pull($user, Cursor::start(), 500, ContractVersion::current()));

        // No later number can commit before it: whoever reserves next waits.
        $blocked = waitsForLock(static fn () => onOtherConnection(static fn () => pushCategories($other, [Records::make('category')])));
    });

    pushCategories($user, [$slow]);
    $seq = categorySeq($user, RecordFields::id($slow));

    expect($blocked)->toBeTrue()
        ->and($pulled?->cursor->seq)->toBeLessThan($seq)
        ->and(array_column($pulled->records['category'] ?? [], 'id'))->not->toContain($slow->id);

    $next = app(PullHandler::class)->pull($user, $pulled->cursor ?? Cursor::start(), 500, ContractVersion::current());

    expect(array_column($next->records['category'] ?? [], 'id'))->toBe([$slow->id])
        ->and($next->cursor->seq)->toBe($seq);
});

it('runs two pushes of one user one after the other, and decides the second against the first', function (): void {
    $user = User::factory()->createOne();
    $base = Records::make('category', ['updatedAt' => '2026-09-01T00:00:00.000Z']);
    pushCategories($user, [$base]);

    $newer = Records::changed($base, ['name' => 'Newer', 'updatedAt' => '2026-09-03T00:00:00.000Z']);
    $older = Records::changed($base, ['name' => 'Older', 'updatedAt' => '2026-09-02T00:00:00.000Z']);
    $blocked = false;

    // The first push holds the user's lock: the second one waits for it.
    whenMainRuns('/for update/', static function () use ($user, $older, &$blocked): void {
        $blocked = waitsForLock(static fn () => onOtherConnection(static fn () => pushCategories($user, [$older])));
    });

    expect(pushCategories($user, [$newer])->results['category'][0]->status)->toBe(RecordStatus::Accepted)
        ->and($blocked)->toBeTrue();

    // Once the first committed, the second sees its version and loses to it.
    $second = onOtherConnection(static fn (): PushResult => pushCategories($user, [$older]));

    expect($second->results['category'][0]->status)->toBe(RecordStatus::Stale)
        ->and(Json::encode($second->results['category'][0]->record))->toBe(Json::encode($newer))
        ->and(Category::query()->ownedBy($user)->whereKey($base->id)->sole()->name)->toBe('Newer');
});

it('keeps a pull to the changes committed when it started, whatever commits between its queries', function (): void {
    $user = User::factory()->createOne();
    $category = Records::make('category');
    $paymentMethod = Records::make('payment-method');

    // The pull has read the categories and not yet the payment methods when
    // another device's push commits one of each.
    whenMainRuns('/from `'.PackageConfig::table('categories').'`/', static function () use ($user, $category, $paymentMethod): void {
        onOtherConnection(static fn (): PushResult => app(PushHandler::class)->push(
            $user,
            Json::decode(Json::encode(['records' => ['category' => [$category], 'payment-method' => [$paymentMethod]]])),
            ContractVersion::current(),
        ));
    });

    $first = app(PullHandler::class)->pull($user, Cursor::start(), 500, ContractVersion::current());
    $next = app(PullHandler::class)->pull($user, $first->cursor, 500, ContractVersion::current());

    // The payment method's later number must not carry the cursor past the
    // category the first page could not see.
    expect(array_keys($first->records))->toBe(['currency'])
        ->and(array_column($next->records['category'] ?? [], 'id'))->toBe([$category->id])
        ->and(array_column($next->records['payment-method'] ?? [], 'id'))->toBe([$paymentMethod->id]);
});
