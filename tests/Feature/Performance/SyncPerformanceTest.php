<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 08, 3.8: the timings of the sync, against the targets of the CI's
// machine. A group of its own (`composer test:performance`): the CI runs it
// in a job that informs but does not block. The query count limits are in
// the normal tests, and they block.

beforeEach(function (): void {
    SyncApi::startSequenceAt(1_000_000);
    Config::set('expenses.rate_limits.push', 100_000);
    Config::set('expenses.rate_limits.pull', 100_000);
});

/**
 * 500 records of every pushable resource, the children referring to parents
 * pushed along with them.
 *
 * @return array<string, list<stdClass>>
 */
function mixedPush(): array
{
    $push = ['category' => array_map(static fn (): stdClass => Records::make('category'), range(1, 5))];

    for ($round = 0; $round < 55; $round++) {
        foreach (Records::graph() as $resource => $records) {
            $push[$resource] = [...$push[$resource] ?? [], ...$records];
        }
    }

    expect(array_sum(array_map(count(...), $push)))->toBe(500);

    return $push;
}

/**
 * The seconds the callback took, also written to the output of the run.
 */
function timed(string $label, Closure $callback): float
{
    $started = hrtime(true);
    $callback();
    $seconds = (hrtime(true) - $started) / 1e9;

    fwrite(STDERR, sprintf("\n  %s: %.3f s\n", $label, $seconds));

    return $seconds;
}

/**
 * The records a pull page holds.
 */
function pageSize(stdClass $page): int
{
    $records = SyncApi::records($page);

    return array_sum(array_map(static fn (string $resource): int => count(SyncApi::group($records, $resource)), array_keys(get_object_vars($records))));
}

it('pushes 500 mixed records into an empty account in under 1.5 s', function (): void {
    $user = SyncApi::userWithProfile();
    $push = mixedPush();

    $seconds = timed('push, 500 mixed records, empty account', static fn (): stdClass => SyncApi::pushOk($user, $push));

    expect($seconds)->toBeLessThan(1.5);
})->group('performance');

it('answers 500 unchanged records pushed again in under 0.5 s', function (): void {
    $user = SyncApi::userWithProfile();
    $push = mixedPush();
    SyncApi::pushOk($user, $push);

    $results = null;
    $seconds = timed('push, 500 unchanged records', static function () use ($user, $push, &$results): void {
        $results = SyncApi::pushOk($user, $push);
    });

    expect($seconds)->toBeLessThan(0.5)
        ->and(array_unique(array_merge(...array_values(SyncApi::statuses($results ?? new stdClass)))))->toBe(['accepted']);
})->group('performance');

it('pulls a page of 1000 records in under 0.5 s', function (): void {
    $user = SyncApi::userWithProfile();
    $cursor = 's:'.SyncApi::sequenceValue();
    SyncApi::pushOk($user, mixedPush());
    SyncApi::pushOk($user, mixedPush());

    $page = null;
    $seconds = timed('pull, 1000 records on a page', static function () use ($user, $cursor, &$page): void {
        $page = SyncApi::pullOk($user, $cursor, 1000);
    });

    expect($seconds)->toBeLessThan(0.5)
        ->and(pageSize($page ?? new stdClass))->toBe(1000);
})->group('performance');

it('pulls an account of 20 000 records, 1000 a page, in under 10 s', function (): void {
    $user = SyncApi::userWithProfile();

    for ($push = 0; $push < 40; $push++) {
        SyncApi::pushOk($user, mixedPush());
    }

    $records = 0;
    $pages = 0;
    $seconds = timed('full pull, 20 000 records, 1000 a page', static function () use ($user, &$records, &$pages): void {
        $cursor = null;

        do {
            $page = SyncApi::pullOk($user, $cursor, 1000);
            $cursor = SyncApi::cursor($page);
            $records += pageSize($page);
            $pages++;
        } while (($page->hasMore ?? false) === true);
    });

    // The account's own record and the currencies come along.
    expect($records)->toBe(20_000 + 4)
        ->and($pages)->toBe(21)
        ->and($seconds)->toBeLessThan(10.0);
})->group('performance');
