<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Database\SyncSequence;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\RecordStore;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 06, 3.3, 3.4, 4.4, 4.6 and 7 (pull).

beforeEach(function (): void {
    SyncApi::startSequenceAt(1_000_000);
});

/**
 * The ids of a page's records, per resource.
 *
 * @return array<string, list<string>>
 */
function pulledIds(stdClass $page): array
{
    $ids = [];

    foreach (get_object_vars(SyncApi::records($page)) as $resource => $records) {
        foreach (is_array($records) ? $records : [] as $record) {
            $ids[(string) $resource][] = $record instanceof stdClass && is_string($record->id ?? null) ? $record->id : '';
        }
    }

    return $ids;
}

/**
 * Pulls every page from the start, each of the given size.
 *
 * @return list<stdClass>
 */
function pullPages(User $user, int $limit): array
{
    $pages = [];
    $cursor = null;

    do {
        $page = SyncApi::pullOk($user, $cursor, $limit);
        $pages[] = $page;
        $cursor = SyncApi::cursor($page);
        expect(count($pages))->toBeLessThan(100);
    } while (($page->hasMore ?? false) === true);

    return $pages;
}

it('delivers everything from the start: the currencies, the account and the records', function (): void {
    $user = SyncApi::userWithProfile();
    $category = Records::make('category');
    $expense = Records::make('expense', ['mainCategoryId' => $category->id]);
    SyncApi::pushOk($user, ['category' => [$category], 'expense' => [$expense]]);

    $page = SyncApi::pullOk($user);

    expect(array_keys(get_object_vars(SyncApi::records($page))))->toBe(['user', 'currency', 'category', 'expense'])
        ->and(pulledIds($page)['currency'])->toBe([Currencies::HUF, Currencies::EUR, Currencies::USD])
        ->and(pulledIds($page)['user'])->toBe([$user->uuid])
        ->and(Json::encode(SyncApi::group(SyncApi::records($page), 'category')))->toBe(Json::encode([$category]))
        ->and(Json::encode(SyncApi::group(SyncApi::records($page), 'expense')))->toBe(Json::encode([$expense]))
        ->and($page->cursor)->toBe('s:'.SyncApi::sequenceValue())
        ->and($page->hasMore)->toBeFalse();
});

it('delivers only what changed after the cursor', function (): void {
    $user = User::factory()->createOne();
    SyncApi::pushOk($user, ['category' => [Records::make('category')]]);
    $cursor = SyncApi::cursor(SyncApi::pullOk($user));
    $later = Records::make('payment-method');
    SyncApi::pushOk($user, ['payment-method' => [$later]]);

    $page = SyncApi::pullOk($user, $cursor);

    expect(pulledIds($page))->toBe(['payment-method' => [$later->id]]);
});

it('keeps the cursor when nothing changed, with an empty records object', function (): void {
    $user = User::factory()->createOne();
    $cursor = SyncApi::cursor(SyncApi::pullOk($user));

    $response = SyncApi::pull($user, $cursor);

    expect($response->getContent())->toBe('{"records":{},"cursor":"'.$cursor.'","hasMore":false}');
});

it('pages through the changes without a duplicate or a gap', function (int $limit): void {
    $user = User::factory()->createOne();
    $push = [
        'category' => array_map(static fn (): stdClass => Records::make('category'), range(1, 7)),
        'payment-method' => array_map(static fn (): stdClass => Records::make('payment-method'), range(1, 4)),
        'shopping-list' => array_map(static fn (): stdClass => Records::make('shopping-list'), range(1, 5)),
    ];
    SyncApi::pushOk($user, $push);
    // Interleave the sequence numbers of the resources.
    SyncApi::pushOk($user, ['category' => [Records::changed($push['category'][0], ['name' => 'Renamed'])]]);

    $pages = pullPages($user, $limit);
    $seen = [];

    foreach ($pages as $number => $page) {
        $count = array_sum(array_map(count(...), pulledIds($page)));
        $last = $number === count($pages) - 1;

        expect($count)->toBeLessThanOrEqual($limit)
            ->and($page->hasMore)->toBe(! $last);

        if (! $last) {
            expect($count)->toBe($limit);
        }

        foreach (pulledIds($page) as $resource => $ids) {
            foreach ($ids as $id) {
                $seen[] = "{$resource} {$id}";
            }
        }
    }

    $expected = ['currency '.Currencies::HUF, 'currency '.Currencies::EUR, 'currency '.Currencies::USD];

    foreach ($push as $resource => $records) {
        foreach ($records as $record) {
            $expected[] = $resource.' '.RecordFields::id($record);
        }
    }

    sort($seen);
    sort($expected);

    // 3 currencies and 16 records: the renamed category comes once, in its new version.
    expect($seen)->toBe($expected)
        ->and(SyncApi::cursor($pages[count($pages) - 1] ?? new stdClass))->toBe('s:'.SyncApi::sequenceValue());
})->with([1, 2, 5, 18, 19, 20, 500]);

it('sends a record in its latest version, once', function (): void {
    $user = User::factory()->createOne();
    $category = Records::make('category');
    SyncApi::pushOk($user, ['category' => [$category]]);
    $renamed = Records::changed($category, ['name' => 'Renamed']);
    SyncApi::pushOk($user, ['category' => [$renamed]]);

    $page = SyncApi::pullOk($user);

    expect(Json::encode(SyncApi::group(SyncApi::records($page), 'category')))->toBe(Json::encode([$renamed]));
});

it('delivers tombstones like live records', function (): void {
    $user = User::factory()->createOne();
    $category = Records::make('category');
    SyncApi::pushOk($user, ['category' => [$category]]);
    $cursor = SyncApi::cursor(SyncApi::pullOk($user));
    $tombstone = Records::deleted($category);
    SyncApi::pushOk($user, ['category' => [$tombstone]]);

    $page = SyncApi::pullOk($user, $cursor);

    expect(Json::encode(SyncApi::group(SyncApi::records($page), 'category')))->toBe(Json::encode([$tombstone]));
});

it('delivers a pocket with its categories', function (): void {
    $user = User::factory()->createOne();
    $categories = [Records::make('category'), Records::make('category')];
    $ids = array_map(RecordFields::id(...), $categories);
    sort($ids);
    $pocket = Records::make('budget-pocket', ['categoryIds' => $ids]);
    SyncApi::pushOk($user, ['category' => $categories, 'budget-pocket' => [$pocket]]);

    expect(Json::encode(SyncApi::group(SyncApi::records(SyncApi::pullOk($user)), 'budget-pocket')))->toBe(Json::encode([$pocket]));
});

it('never delivers another user\'s rows, even under the same seed id', function (): void {
    $user = User::factory()->createOne();
    $other = User::factory()->createOne();
    $mine = Records::make('category');
    $theirs = Records::make('category', ['id' => $mine->id, 'name' => 'Theirs']);
    SyncApi::pushOk($other, ['category' => [$theirs, Records::make('category')], 'payment-method' => [Records::make('payment-method')]]);
    SyncApi::pushOk($user, ['category' => [$mine]]);

    $page = SyncApi::pullOk($user);

    expect(pulledIds($page))->toBe(['currency' => [Currencies::HUF, Currencies::EUR, Currencies::USD], 'category' => [$mine->id]])
        ->and(Json::encode(SyncApi::group(SyncApi::records($page), 'category')))->toBe(Json::encode([$mine]));
});

it('sends the account record once it changes', function (): void {
    $user = SyncApi::userWithProfile();
    $cursor = SyncApi::cursor(SyncApi::pullOk($user));

    expect(SyncApi::pullOk($user, $cursor)->records)->toEqual(new stdClass);

    DB::transaction(static function () use ($user): void {
        $user->forceFill(['name' => 'Anna', 'server_seq' => SyncSequence::reserve(1)])->save();
    });

    $page = SyncApi::pullOk($user, $cursor);

    expect(pulledIds($page))->toBe(['user' => [$user->uuid]])
        ->and(SyncApi::group(SyncApi::records($page), 'user')[0]->displayName ?? null)->toBe('Anna');
});

it('leaves out of a pull and refuses in a push what an older client does not know', function (): void {
    $user = User::factory()->createOne();
    SyncApi::pushOk($user, ['category' => [Records::make('category')]], '1.0');

    expect(array_keys(get_object_vars(SyncApi::records(SyncApi::pullOk($user, version: '1.0')))))->toBe(['category'])
        ->and(array_keys(get_object_vars(SyncApi::records(SyncApi::pullOk($user, version: '1.1')))))->toBe(['currency', 'category']);

    SyncApi::assertError(SyncApi::pushRaw($user, '{"records":{"currency":[{}]}}', '1.0'), 422, 'resource_not_writable');
});

it('sends a currency change to every user', function (): void {
    $user = User::factory()->createOne();
    $cursor = SyncApi::cursor(SyncApi::pullOk($user));

    DB::transaction(static function (): void {
        Currency::query()->whereKey(Currencies::USD)->update([
            'deleted_at' => '2026-09-20 00:00:00.000',
            'updated_at' => '2026-09-20 00:00:00.000',
            'server_seq' => SyncSequence::reserve(1),
        ]);
    });

    $page = SyncApi::pullOk($user, $cursor);

    expect(pulledIds($page))->toBe(['currency' => [Currencies::USD]])
        ->and(SyncApi::group(SyncApi::records($page), 'currency')[0]->deletedAt ?? null)->toBe('2026-09-20T00:00:00.000Z');
});

it('refuses a cursor the server did not issue', function (string $cursor): void {
    SyncApi::assertError(SyncApi::pull(User::factory()->createOne(), $cursor), 422, 'cursor_malformed');
})->with(['42', 's:', 's:-1', 's:01', 's:1.5', 's: 1', 'S:1', 't:1', 's:99999999999999999999']);

it('refuses a cursor older than the pruned tombstones', function (): void {
    $user = User::factory()->createOne();
    DB::table(PackageConfig::table(SyncSequence::TABLE))->update(['pruned_through' => 500]);

    SyncApi::assertError(SyncApi::pull($user, 's:499'), 410, 'cursor_expired');
    SyncApi::pullOk($user, 's:500');
    SyncApi::pullOk($user, 's:0');
    SyncApi::pullOk($user);
});

it('caps the page size at the configured maximum', function (): void {
    Config::set('expenses.sync.pull_max_limit', 2);
    $user = User::factory()->createOne();

    $page = SyncApi::pullOk($user, limit: 10);

    expect(pulledIds($page)['currency'])->toBe([Currencies::HUF, Currencies::EUR])
        ->and($page->hasMore)->toBeTrue();
    expect(SyncApi::pullOk($user, limit: 999999999999)->hasMore)->toBeTrue();
});

it('uses the default page size when none is asked for', function (): void {
    Config::set('expenses.sync.pull_default_limit', 1);

    expect(pulledIds(SyncApi::pullOk(User::factory()->createOne()))['currency'])->toBe([Currencies::HUF]);
});

it('refuses a page size that is not a positive integer', function (string $limit): void {
    SyncApi::assertError(SyncApi::pull(User::factory()->createOne(), null, $limit), 422, 'contract_violation');
})->with(['0', '-1', 'ten', '1.5']);

it('matches the stored rows', function (): void {
    $user = User::factory()->createOne();
    $stored = Records::stored('shopping-list', $user);

    expect(Json::encode(SyncApi::group(SyncApi::records(SyncApi::pullOk($user)), 'shopping-list')))
        ->toBe('['.RecordStore::encode('shopping-list', RecordStore::reload('shopping-list', RecordFields::id($stored), $user)).']');
});
