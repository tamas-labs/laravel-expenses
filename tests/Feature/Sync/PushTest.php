<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use TamasLabs\LaravelExpenses\Database\Factories\SyncModelFactory;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Rules\Batch\ReferencesExist;
use TamasLabs\LaravelExpenses\Rules\Batch\UniqueAmongLiving;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\Events\RecordsPushed;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\RecordStore;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

use function Pest\Laravel\postJson;

// Spec 06, 3.2, 5 and 7 (push).

const PUSH_SEQUENCE_START = 1_000_000;

beforeEach(function (): void {
    SyncApi::startSequenceAt(PUSH_SEQUENCE_START);
});

/**
 * The stored row of a record.
 */
function storedRow(string $resource, stdClass $record, User $user): SyncModel
{
    return RecordStore::reload($resource, RecordFields::id($record), $user);
}

/**
 * The stored row of a record, as the mapper sends it.
 */
function storedPayload(string $resource, stdClass $record, User $user): string
{
    return RecordStore::encode($resource, storedRow($resource, $record, $user));
}

/**
 * One record of every pushable resource per round, each referring to parents
 * pushed along with it.
 *
 * @return array<string, list<stdClass>>
 */
function fullPush(int $rounds): array
{
    $push = [];

    for ($round = 0; $round < $rounds; $round++) {
        $category = Records::make('category');
        $subcategory = Records::make('subcategory', ['categoryId' => $category->id]);
        $paymentMethod = Records::make('payment-method');
        $expense = Records::make('expense', [
            'mainCategoryId' => $category->id,
            'subCategoryId' => $subcategory->id,
            'paymentMethodId' => $paymentMethod->id,
        ]);
        $product = Records::make('product', ['mainCategoryId' => $category->id, 'subCategoryId' => $subcategory->id]);
        $list = Records::make('shopping-list');

        $push['category'][] = $category;
        $push['subcategory'][] = $subcategory;
        $push['payment-method'][] = $paymentMethod;
        $push['expense'][] = $expense;
        $push['product'][] = $product;
        $push['product-price'][] = Records::make('product-price', [
            'productId' => $product->id,
            'source' => 'expense',
            'sourceExpenseId' => $expense->id,
        ]);
        $push['shopping-list'][] = $list;
        $push['shopping-list-item'][] = Records::make('shopping-list-item', ['shoppingListId' => $list->id]);
        $push['budget-pocket'][] = Records::make('budget-pocket', ['categoryIds' => [$category->id]]);
    }

    return $push;
}

it('accepts a new record, numbers it and stores it as sent', function (): void {
    $user = User::factory()->createOne();
    $category = Records::make('category');

    $results = SyncApi::pushOk($user, ['category' => [$category]]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['accepted']]);

    $row = storedRow('category', $category, $user);

    expect($row->server_seq)->toBe(PUSH_SEQUENCE_START + 1)
        ->and($row->synced_at->diffInSeconds(now(), true))->toBeLessThan(60.0)
        ->and(RecordStore::encode('category', $row))->toBe(Json::encode($category))
        ->and(SyncApi::sequenceValue())->toBe(PUSH_SEQUENCE_START + 1);
});

it('stores every pushable resource as sent, the pocket with its categories', function (): void {
    $user = User::factory()->createOne();
    $push = fullPush(1);

    $results = SyncApi::pushOk($user, $push);

    foreach ($push as $resource => $records) {
        expect(SyncApi::statuses($results)[$resource])->toBe(['accepted'])
            ->and(storedPayload($resource, $records[0], $user))->toBe(Json::encode($records[0]));
    }
});

it('answers an older record with the server version and leaves the row alone', function (): void {
    $user = User::factory()->createOne();
    $stored = Records::stored('category', $user, ['updatedAt' => '2026-09-01T10:00:00.000Z']);
    $row = storedRow('category', $stored, $user);

    $results = SyncApi::pushOk($user, ['category' => [
        Records::changed($stored, ['name' => 'Renamed', 'updatedAt' => '2026-09-01T09:59:59.999Z']),
    ]]);

    $result = SyncApi::result($results, 'category');

    expect($result->status)->toBe('stale')
        ->and(Json::encode($result->record ?? null))->toBe(Json::encode($stored))
        ->and(storedPayload('category', $stored, $user))->toBe(Json::encode($stored))
        ->and(storedRow('category', $stored, $user)->server_seq)->toBe($row->server_seq);
});

it('accepts an unchanged record without writing or numbering it', function (): void {
    Event::fake([RecordsPushed::class]);
    $user = User::factory()->createOne();
    $stored = Records::stored('category', $user);
    $seq = storedRow('category', $stored, $user)->server_seq;

    $results = SyncApi::pushOk($user, ['category' => [$stored]]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['accepted']])
        ->and(storedRow('category', $stored, $user)->server_seq)->toBe($seq)
        ->and(SyncApi::sequenceValue())->toBe(PUSH_SEQUENCE_START);
    Event::assertNotDispatched(RecordsPushed::class);
});

it('takes the same instant written another way as unchanged', function (): void {
    $user = User::factory()->createOne();
    $stored = Records::stored('category', $user, ['updatedAt' => '2026-09-01T10:00:00.123Z']);

    $results = SyncApi::pushOk($user, ['category' => [
        Records::changed($stored, ['updatedAt' => '2026-09-01T12:00:00.1239+02:00']),
    ]]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['accepted']])
        ->and(SyncApi::sequenceValue())->toBe(PUSH_SEQUENCE_START);
});

it('keeps the server version on a tie with other content', function (): void {
    $user = User::factory()->createOne();
    $stored = Records::stored('category', $user);

    $results = SyncApi::pushOk($user, ['category' => [Records::changed($stored, ['name' => 'Other', 'updatedAt' => $stored->updatedAt])]]);

    expect(SyncApi::result($results, 'category')->status)->toBe('stale')
        ->and(storedPayload('category', $stored, $user))->toBe(Json::encode($stored));
});

it('writes a newer version with a new sequence number', function (): void {
    $user = User::factory()->createOne();
    $stored = Records::stored('category', $user);
    $newer = Records::changed($stored, ['name' => 'Renamed']);

    $results = SyncApi::pushOk($user, ['category' => [$newer]]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['accepted']])
        ->and(storedPayload('category', $stored, $user))->toBe(Json::encode($newer))
        ->and(storedRow('category', $stored, $user)->server_seq)->toBe(PUSH_SEQUENCE_START + 1);
});

it('deletes a live record with a newer tombstone and brings it back with a newer live version', function (): void {
    $user = User::factory()->createOne();
    $stored = Records::stored('category', $user);
    $tombstone = Records::deleted($stored);

    SyncApi::pushOk($user, ['category' => [$tombstone]]);

    expect(storedRow('category', $stored, $user)->deleted_at?->format('Y-m-d\TH:i:s.v\Z'))->toBe('2026-09-20T12:00:00.000Z');

    $alive = Records::changed($tombstone, ['deletedAt' => null, 'updatedAt' => '2026-09-21T08:00:00.000Z']);

    expect(SyncApi::statuses(SyncApi::pushOk($user, ['category' => [$alive]])))->toBe(['category' => ['accepted']])
        ->and(storedPayload('category', $stored, $user))->toBe(Json::encode($alive));
});

it('keeps a newer tombstone against an older live version', function (): void {
    $user = User::factory()->createOne();
    $tombstone = Records::deleted(Records::stored('category', $user));
    SyncApi::pushOk($user, ['category' => [$tombstone]]);

    $results = SyncApi::pushOk($user, ['category' => [Records::changed($tombstone, ['deletedAt' => null, 'updatedAt' => '2026-09-19T00:00:00.000Z'])]]);

    expect(SyncApi::result($results, 'category')->status)->toBe('stale')
        ->and(Json::encode(SyncApi::result($results, 'category')->record ?? null))->toBe(Json::encode($tombstone));
});

it('accepts a parent and its child in one push', function (): void {
    $user = User::factory()->createOne();
    $category = Records::make('category');
    $subcategory = Records::make('subcategory', ['categoryId' => $category->id]);

    // Given child first: the push applies parents first whatever the request's order.
    $results = SyncApi::pushOk($user, ['subcategory' => [$subcategory], 'category' => [$category]]);

    expect(SyncApi::statuses($results))->toBe(['subcategory' => ['accepted'], 'category' => ['accepted']]);
});

it('rejects the child of a parent the push rejected', function (): void {
    $user = User::factory()->createOne();
    $category = Records::make('category', ['color' => 'red']);
    $subcategory = Records::make('subcategory', ['categoryId' => $category->id]);

    $results = SyncApi::pushOk($user, ['category' => [$category], 'subcategory' => [$subcategory]]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['rejected'], 'subcategory' => ['rejected']])
        ->and(SyncApi::issue(SyncApi::result($results, 'category'))->keyword)->toBe('pattern')
        ->and(Json::encode(SyncApi::result($results, 'subcategory')->issues ?? null))->toBe(Json::encode([[
            'path' => '/categoryId',
            'keyword' => ReferencesExist::KEYWORD,
            'message' => 'The referenced category does not exist.',
            'params' => ['resource' => 'category', 'id' => $category->id],
        ]]))
        ->and(Category::query()->ownedBy($user)->count())->toBe(0);
});

it('rejects a record the server cannot store, without touching the rest', function (): void {
    $user = User::factory()->createOne();
    $leap = Records::make('category', ['createdAt' => '2016-12-31T23:59:60.000Z']);
    $fine = Records::make('category');

    $results = SyncApi::pushOk($user, ['category' => [$leap, $fine]]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['rejected', 'accepted']])
        ->and(SyncApi::issue(SyncApi::result($results, 'category'))->path)->toBe('/createdAt');
});

it('accepts a child of a stale parent', function (): void {
    $user = User::factory()->createOne();
    $stored = Records::stored('category', $user, ['updatedAt' => '2026-09-10T00:00:00.000Z']);
    $olderParent = Records::changed($stored, ['name' => 'Older', 'updatedAt' => '2026-09-01T00:00:00.000Z']);
    $child = Records::make('subcategory', ['categoryId' => $stored->id]);

    $results = SyncApi::pushOk($user, ['category' => [$olderParent], 'subcategory' => [$child]]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['stale'], 'subcategory' => ['accepted']]);
});

it('swaps the names of two categories in one push', function (): void {
    $user = User::factory()->createOne();
    $first = Records::stored('category', $user, ['name' => 'Food']);
    $second = Records::stored('category', $user, ['name' => 'Drinks']);
    $pushed = [Records::changed($first, ['name' => 'Drinks']), Records::changed($second, ['name' => 'Food'])];

    $results = SyncApi::pushOk($user, ['category' => $pushed]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['accepted', 'accepted']])
        ->and(storedPayload('category', $first, $user))->toBe(Json::encode($pushed[0]))
        ->and(storedPayload('category', $second, $user))->toBe(Json::encode($pushed[1]));
});

it('lets a new record take the name a tombstone of the same push frees', function (): void {
    $user = User::factory()->createOne();
    $old = Records::stored('category', $user, ['name' => 'Food']);
    $new = Records::make('category', ['name' => 'Food']);

    $results = SyncApi::pushOk($user, ['category' => [$new, Records::deleted($old)]]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['accepted', 'accepted']]);
});

it('does not free the name of a record the server version beats', function (): void {
    $user = User::factory()->createOne();
    $stored = Records::stored('category', $user, ['name' => 'Food', 'updatedAt' => '2026-09-10T00:00:00.000Z']);
    $olderRename = Records::changed($stored, ['name' => 'Groceries', 'updatedAt' => '2026-09-01T00:00:00.000Z']);
    $claimant = Records::make('category', ['name' => 'Food']);

    $results = SyncApi::pushOk($user, ['category' => [$olderRename, $claimant]]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['stale', 'rejected']])
        ->and(SyncApi::issue(SyncApi::result($results, 'category', 1))->keyword)->toBe(UniqueAmongLiving::KEYWORD)
        ->and(Json::encode(SyncApi::issue(SyncApi::result($results, 'category', 1))->params ?? null))->toBe(Json::encode(['conflictingId' => $stored->id]));
});

it('answers a resent push the same way and writes nothing the second time', function (): void {
    Event::fake([RecordsPushed::class]);
    $user = User::factory()->createOne();
    $push = fullPush(2);

    $first = SyncApi::push($user, $push);
    $sequence = SyncApi::sequenceValue();
    $second = SyncApi::push($user, $push);

    expect($second->getContent())->toBe($first->getContent())
        ->and(SyncApi::sequenceValue())->toBe($sequence)
        ->and($sequence)->toBe(PUSH_SEQUENCE_START + 18);
    Event::assertDispatchedTimes(RecordsPushed::class, 1);
});

it('mirrors the request: its groups, their order and the records in each', function (): void {
    $user = User::factory()->createOne();
    $stored = Records::stored('payment-method', $user, ['updatedAt' => '2026-09-10T00:00:00.000Z']);
    $request = [
        'payment-method' => [
            Records::make('payment-method'),
            Records::changed($stored, ['updatedAt' => '2026-09-01T00:00:00.000Z']),
            Records::make('payment-method', ['color' => 'blue']),
        ],
        'category' => [Records::make('category'), Records::make('category')],
    ];

    $results = SyncApi::pushOk($user, $request);

    expect(SyncApi::statuses($results))->toBe([
        'payment-method' => ['accepted', 'stale', 'rejected'],
        'category' => ['accepted', 'accepted'],
    ]);

    foreach ($request as $resource => $records) {
        foreach ($records as $index => $record) {
            expect(SyncApi::result($results, $resource, $index)->id)->toBe($record->id);
        }
    }
});

it('answers an empty push with no results', function (): void {
    $response = SyncApi::pushRaw(User::factory()->createOne(), '{"records":{}}');

    $response->assertOk();
    expect($response->getContent())->toBe('{"results":{}}');
});

it('tells the host what a push wrote, after the commit', function (): void {
    Event::fake([RecordsPushed::class]);
    $user = User::factory()->createOne();
    $stored = Records::stored('category', $user);

    SyncApi::pushOk($user, [
        'category' => [$stored, Records::make('category')],
        'payment-method' => [Records::make('payment-method')],
    ]);

    Event::assertDispatched(RecordsPushed::class, static fn (RecordsPushed $event): bool => $event->user->is($user)
        && $event->counts === ['category' => 1, 'payment-method' => 1]);
});

it('refuses an envelope that does not match the push request', function (string $body, string $path, string $keyword): void {
    $error = SyncApi::assertError(SyncApi::pushRaw(User::factory()->createOne(), $body), 422, 'contract_violation');

    expect($error->resource ?? null)->toBe('push-request')
        ->and(SyncApi::issueKeys($error))->toContain("{$path} {$keyword}")
        ->and(SyncApi::sequenceValue())->toBe(PUSH_SEQUENCE_START);
})->with([
    'not JSON' => ['{"records":', '/', 'json'],
    'not an object' => ['[]', '/', 'type'],
    'no records' => ['{}', '/', 'required'],
    'another field' => ['{"records":{},"device":"x"}', '/', 'additionalProperties'],
    'records not an object' => ['{"records":[]}', '/records', 'type'],
    'group not an array' => ['{"records":{"category":{}}}', '/records/category', 'type'],
    'empty group' => ['{"records":{"category":[]}}', '/records/category', 'minItems'],
    'record not an object' => ['{"records":{"category":[1]}}', '/records/category/0', 'type'],
    'record without an id' => ['{"records":{"category":[{"name":"Food"}]}}', '/records/category/0', 'required'],
    'uppercase id' => ['{"records":{"category":[{"id":"1B9D6BCD-BBFD-4B2D-9B5D-AB8DFBBD4BED"}]}}', '/records/category/0/id', 'pattern'],
]);

it('refuses a resource a client cannot push', function (string $resource): void {
    $error = SyncApi::assertError(SyncApi::pushRaw(User::factory()->createOne(), '{"records":{"'.$resource.'":[{}]}}'), 422, 'resource_not_writable');

    expect($error->resource ?? null)->toBe($resource);
})->with(['currency', 'user', 'unknown']);

it('refuses a record pushed twice', function (): void {
    $category = Records::make('category');

    $error = SyncApi::assertError(SyncApi::push(User::factory()->createOne(), ['category' => [$category, Records::changed($category, [])]]), 422, 'duplicate_record');

    expect($error->resource ?? null)->toBe('category');
});

it('lets the same id through in two resources', function (): void {
    $id = SyncModelFactory::uuid();

    $results = SyncApi::pushOk(User::factory()->createOne(), [
        'category' => [Records::make('category', ['id' => $id])],
        'payment-method' => [Records::make('payment-method', ['id' => $id])],
    ]);

    expect(SyncApi::statuses($results))->toBe(['category' => ['accepted'], 'payment-method' => ['accepted']]);
});

it('refuses more records than the configured maximum', function (): void {
    Config::set('expenses.sync.push_max_records', 2);

    SyncApi::assertError(SyncApi::push(User::factory()->createOne(), [
        'category' => [Records::make('category'), Records::make('category')],
        'payment-method' => [Records::make('payment-method')],
    ]), 422, 'too_many_records');
});

it('rolls the whole push back when a write fails', function (): void {
    $user = User::factory()->createOne();
    $push = fullPush(1);

    DB::listen(static function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'insert into `'.PackageConfig::table('expenses').'`')) {
            throw new RuntimeException('The disk is full.');
        }
    });

    SyncApi::push($user, $push)->assertStatus(500);

    expect(Category::query()->ownedBy($user)->count())->toBe(0)
        ->and(SyncApi::sequenceValue())->toBe(PUSH_SEQUENCE_START);
});

it('needs an authenticated user', function (): void {
    $response = postJson(route('expenses.sync.push'), ['records' => []], ['X-Expenses-Contract' => '1.2']);

    $response->assertUnauthorized()->assertHeader('X-Expenses-Contract', '1.2');
});

it('runs a query count independent of the number of records', function (): void {
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $small = fullPush(1);
    $large = fullPush(55);
    $counts = [];

    foreach ([$small, $large] as $push) {
        $user = User::factory()->createOne();
        $queries = 0;

        SyncApi::pushOk($user, $push);
        $counts[] = $queries;
    }

    expect(array_sum(array_map(count(...), $large)))->toBe(495)
        ->and($counts[1])->toBe($counts[0]);
});
