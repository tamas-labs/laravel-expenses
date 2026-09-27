<?php

declare(strict_types=1);

use TamasLabs\LaravelExpenses\Models\BudgetPocket;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\ContractSchema;
use TamasLabs\LaravelExpenses\Tests\Support\RecordStore;
use TamasLabs\LaravelExpenses\Tests\Support\SimulatedClient;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 06, 7 (ConvergenceTest): devices of one user edit, delete and bring
// back records, go offline and sync in a random order, with clocks that
// disagree (two of them to the millisecond, so updatedAt ties happen). After
// a last round of pushes and pulls every device holds, byte for byte, what
// the server holds.

const CONVERGENCE_RESOURCES = ['category', 'payment-method', 'expense', 'shopping-list', 'shopping-list-item', 'budget-pocket'];

/**
 * A UUID from the seeded generator, so a seed replays the same run.
 */
function seededUuid(): string
{
    return sprintf(
        '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
        mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFF),
        mt_rand(0, 0x3FFF) | 0x8000, mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF),
    );
}

/**
 * @template T
 *
 * @param  list<T>  $items
 * @return T|null
 */
function pick(array $items): mixed
{
    return $items === [] ? null : $items[mt_rand(0, count($items) - 1)];
}

function seededName(): string
{
    return 'Name '.mt_rand(1, PHP_INT_MAX);
}

/**
 * A new record of the resource: the schema's example, a fresh id, unique
 * names, and references to records the device knows.
 */
function newRecord(SimulatedClient $client, string $resource): ?stdClass
{
    $record = ContractSchema::example($resource);
    $record->id = seededUuid();
    $category = pick($client->records('category'));

    switch ($resource) {
        case 'category':
        case 'payment-method':
        case 'budget-pocket':
            $record->name = seededName();
            break;
        case 'expense':
            $record->name = seededName();
            $record->mainCategoryId = mt_rand(0, 1) === 1 ? $category?->id : null;
            $record->subCategoryId = null;
            $record->paymentMethodId = pick($client->records('payment-method'))?->id;
            $record->amountMinor = mt_rand(1, 100_000);
            $record->items = [];
            break;
        case 'shopping-list':
            $record->name = seededName();
            $record->status = 'active';
            $record->archivedAt = null;
            break;
        case 'shopping-list-item':
            $list = pick($client->records('shopping-list'));

            if ($list === null) {
                return null;
            }

            $record->shoppingListId = $list->id;
            $record->name = seededName();
            break;
    }

    if ($resource === 'budget-pocket') {
        $record->categoryIds = pocketCategories($client);
    }

    return $record;
}

/**
 * @return list<string>
 */
function pocketCategories(SimulatedClient $client): array
{
    $ids = array_values(array_unique(array_filter(array_map(
        static fn (): ?string => ($category = pick($client->records('category'))) === null ? null : RecordFields::id($category),
        range(1, mt_rand(0, 3)),
    ))));
    sort($ids);

    return $ids;
}

/**
 * Fields of an edit to a record of the resource.
 *
 * @return array<string, mixed>
 */
function edit(SimulatedClient $client, string $resource, stdClass $record): array
{
    return match ($resource) {
        'expense' => mt_rand(0, 1) === 1 ? ['note' => seededName()] : ['amountMinor' => mt_rand(1, 100_000)],
        'shopping-list' => $record->status === 'active'
            ? ['status' => 'archived', 'archivedAt' => $client->now()]
            : ['status' => 'active', 'archivedAt' => null],
        'shopping-list-item' => ['isChecked' => ! $record->isChecked],
        'budget-pocket' => mt_rand(0, 1) === 1 ? ['categoryIds' => pocketCategories($client)] : ['name' => seededName()],
        default => ['name' => seededName()],
    };
}

/**
 * What the server holds for the user, per resource, encoded as the mapper
 * sends it: read from the tables, not through the pull.
 *
 * @return array<string, array<string, string>>
 */
function serverState(User $user): array
{
    $registry = app(ResourceRegistry::class);
    $state = [];

    foreach (CONVERGENCE_RESOURCES as $resource) {
        foreach ($registry->ownedModel($resource)::query()->ownedBy($user)->get() as $row) {
            if ($row instanceof BudgetPocket) {
                RecordStore::loadCategoryIds($row);
            }

            $state[$resource][$row->id] = Json::encode($registry->mapper($resource)->toPayload($row));
        }

        if (isset($state[$resource])) {
            ksort($state[$resource]);
        }
    }

    ksort($state);

    return $state;
}

/**
 * @param  array<string, array<string, string>>  $state
 * @return array<string, array<string, string>>
 */
function owned(array $state): array
{
    return array_intersect_key($state, array_flip(CONVERGENCE_RESOURCES));
}

it('brings every device to the server\'s state', function (int $seed): void {
    SyncApi::startSequenceAt(1_000_000);
    $user = User::factory()->createOne();

    // Seeded after the factory, whose Faker draws from the same generator.
    mt_srand($seed);
    $time = 1_790_000_000_000;
    $clock = static function () use (&$time): int {
        return $time;
    };

    $clients = [
        new SimulatedClient($user, $clock, 0, 7),
        new SimulatedClient($user, $clock, 0, 500),
        new SimulatedClient($user, $clock, -250, 3),
    ];
    $online = [true, true, true];
    $counts = ['create' => 0, 'edit' => 0, 'delete' => 0, 'restore' => 0, 'push' => 0, 'pull' => 0];

    for ($step = 0; $step < 400; $step++) {
        // Often not at all: devices then act within the same millisecond.
        $time += pick([0, 0, 1, 2, 50]) ?? 0;
        $device = mt_rand(0, count($clients) - 1);
        $client = $clients[$device];
        $resource = pick(CONVERGENCE_RESOURCES) ?? 'category';
        $roll = mt_rand(1, 100);

        if ($roll <= 25) {
            $record = newRecord($client, $resource);

            if ($record !== null) {
                $client->create($resource, $record);
                $counts['create']++;
            }
        } elseif ($roll <= 50) {
            $record = pick($client->records($resource, alive: true));

            if ($record !== null) {
                $client->change($resource, RecordFields::id($record), edit($client, $resource, $record));
                $counts['edit']++;
            }
        } elseif ($roll <= 58) {
            $record = pick($client->records($resource, alive: true));

            if ($record !== null) {
                $client->delete($resource, RecordFields::id($record));
                $counts['delete']++;
            }
        } elseif ($roll <= 62) {
            $record = pick($client->records($resource, alive: false));

            if ($record !== null) {
                $client->change($resource, RecordFields::id($record), ['deletedAt' => null]);
                $counts['restore']++;
            }
        } elseif ($roll <= 67) {
            $online[$device] = ! $online[$device];
        } elseif ($online[$device]) {
            $order = mt_rand(0, 2);

            if ($order !== 1) {
                $client->push();
                $counts['push']++;
            }

            if ($order !== 0) {
                $client->pull();
                $counts['pull']++;
            }

            if ($order === 2) {
                $client->push();
            }
        }
    }

    // The last round: everyone online, every change pushed, then pulled.
    foreach ($clients as $client) {
        $client->push();
    }

    foreach ($clients as $client) {
        $client->pull();
    }

    $server = serverState($user);

    $branches = array_map(
        static fn (string $branch): int => array_sum(array_map(static fn (SimulatedClient $client): int => $client->branches[$branch], $clients)),
        ['stale' => 'stale', 'keptLocal' => 'keptLocal', 'lostTie' => 'lostTie'],
    );

    // The run went through every kind of change, and the devices ran into
    // each other's changes (each branch alone is pinned by the tests below).
    expect($counts['create'])->toBeGreaterThan(30)
        ->and($counts['edit'])->toBeGreaterThan(30)
        ->and($counts['delete'])->toBeGreaterThan(5)
        ->and($counts['restore'])->toBeGreaterThan(0)
        ->and($counts['push'] + $counts['pull'])->toBeGreaterThan(30)
        ->and(array_sum($branches))->toBeGreaterThan(0);

    foreach ($clients as $index => $client) {
        expect($client->rejections)->toBe([], "Device {$index} had records rejected.")
            ->and($client->hasDirty())->toBeFalse()
            ->and(owned($client->state()))->toBe($server, "Device {$index} differs from the server.")
            ->and($client->state())->toBe($clients[0]->state());
    }
})->with([1, 2, 3, 42, 2026]);

it('settles a tie the same way on both sides: the server\'s version stays', function (bool $pullFirst): void {
    SyncApi::startSequenceAt(1_000_000);
    $user = User::factory()->createOne();
    mt_srand(7);
    $time = 1_790_000_000_000;
    $clock = static function () use (&$time): int {
        return $time;
    };
    [$first, $second] = [new SimulatedClient($user, $clock, 0, 500), new SimulatedClient($user, $clock, 0, 500)];

    $category = $first->create('category', newRecord($first, 'category') ?? new stdClass);
    $first->push();
    $second->pull();

    // Both devices edit it in the same millisecond, differently.
    $time += 1000;
    $first->change('category', RecordFields::id($category), ['name' => 'First']);
    $second->change('category', RecordFields::id($category), ['name' => 'Second']);
    $first->push();

    // The second device learns of the other edit by a pull, or by a push.
    if ($pullFirst) {
        $second->pull();
    } else {
        $second->push();
    }

    $second->push();
    $first->pull();
    $second->pull();

    expect($pullFirst ? $second->branches['lostTie'] : $second->branches['stale'])->toBe(1)
        ->and(owned($first->state()))->toBe(serverState($user))
        ->and(owned($second->state()))->toBe(serverState($user))
        ->and(serverState($user)['category'][RecordFields::id($category)] ?? null)->toContain('"name":"First"');
})->with(['pull first' => true, 'push first' => false]);

it('keeps a later local edit over an older one it pulls, and pushes it', function (): void {
    SyncApi::startSequenceAt(1_000_000);
    $user = User::factory()->createOne();
    mt_srand(11);
    $time = 1_790_000_000_000;
    $clock = static function () use (&$time): int {
        return $time;
    };
    // The second device's clock runs a minute ahead.
    [$first, $second] = [new SimulatedClient($user, $clock, 0, 500), new SimulatedClient($user, $clock, 60_000, 500)];

    $category = $first->create('category', newRecord($first, 'category') ?? new stdClass);
    $first->push();
    $second->pull();

    $time += 1000;
    $second->change('category', RecordFields::id($category), ['name' => 'Second']);
    $time += 1000;
    $first->change('category', RecordFields::id($category), ['name' => 'First']);
    $first->push();

    $second->pull();

    expect($second->branches['keptLocal'])->toBe(1)
        ->and($second->hasDirty())->toBeTrue();

    $second->push();
    $first->pull();

    expect($first->branches['keptLocal'] + $first->branches['lostTie'])->toBe(0)
        ->and(owned($first->state()))->toBe(serverState($user))
        ->and(owned($second->state()))->toBe(serverState($user))
        ->and(serverState($user)['category'][RecordFields::id($category)] ?? null)->toContain('"name":"Second"');
});
