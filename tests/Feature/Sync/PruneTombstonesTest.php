<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use TamasLabs\LaravelExpenses\Database\Factories\BudgetPocketFactory;
use TamasLabs\LaravelExpenses\Database\Factories\CategoryFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ExpenseFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ProductFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ProductPriceFactory;
use TamasLabs\LaravelExpenses\Database\Factories\SubcategoryFactory;
use TamasLabs\LaravelExpenses\Database\SyncSequence;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Registry\ResourceDefinition;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\SyncStore;
use TamasLabs\LaravelExpenses\Sync\TombstonePruner;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\RecordStore;
use TamasLabs\LaravelExpenses\Tests\Support\SchemaInspector;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 08, 3.3 and 4: the tombstone pruning.

beforeEach(function (): void {
    SyncApi::startSequenceAt(1_000_000);
});

/**
 * A time the default retention (180 days) has passed since.
 */
function longAgo(): CarbonImmutable
{
    return CarbonImmutable::now('UTC')->subDays(181);
}

function lately(): CarbonImmutable
{
    return CarbonImmutable::now('UTC')->subDays(10);
}

/**
 * Runs the command and returns its exit code.
 *
 * @param  array<string, mixed>  $options
 */
function pruneTombstones(array $options = []): int
{
    return Artisan::call('expenses:prune-tombstones', $options);
}

/**
 * Whether the row is still stored.
 */
function stillStored(SyncModel $row): bool
{
    return $row::query()->where('user_id', $row->user_id)->whereKey($row->id)->exists();
}

function prunedThrough(): int
{
    return SyncSequence::state()['prunedThrough'];
}

/**
 * Puts the category in the pocket (the pivot row).
 */
function linkPocket(SyncModel $pocket, SyncModel $category): void
{
    DB::table(PackageConfig::table(SyncStore::POCKET_CATEGORIES))->insert([
        'user_id' => $pocket->user_id,
        'budget_pocket_id' => $pocket->id,
        'category_id' => $category->id,
    ]);
}

it('deletes the old tombstones no row refers to, and keeps the rest', function (): void {
    $user = User::factory()->createOne();
    $category = CategoryFactory::new()->ownedBy($user);

    $old = $category->deleted()->createOne(['synced_at' => longAgo()]);
    $young = $category->deleted()->createOne(['synced_at' => lately()]);
    $alive = $category->createOne(['synced_at' => longAgo()]);
    $referred = $category->deleted()->createOne(['synced_at' => longAgo()]);
    ExpenseFactory::new()->ownedBy($user)->createOne(['main_category_id' => $referred->id]);

    expect(pruneTombstones())->toBe(0)
        ->and(stillStored($old))->toBeFalse()
        ->and(stillStored($young))->toBeTrue()
        ->and(stillStored($alive))->toBeTrue()
        ->and(stillStored($referred))->toBeTrue();
});

it('judges by the time the server stored the tombstone, not by the client\'s deletedAt', function (): void {
    $user = User::factory()->createOne();
    $tombstone = CategoryFactory::new()->ownedBy($user)->createOne([
        'updated_at' => CarbonImmutable::parse('2020-01-01T00:00:00Z'),
        'deleted_at' => CarbonImmutable::parse('2020-01-01T00:00:00Z'),
        'synced_at' => lately(),
    ]);

    pruneTombstones();

    expect($tombstone->deleted_at?->year)->toBe(2020)
        ->and(stillStored($tombstone))->toBeTrue();
});

it('keeps a tombstone another tombstone refers to while that one stays', function (): void {
    $user = User::factory()->createOne();
    $parent = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    $child = SubcategoryFactory::new()->ownedBy($user)->deleted()->createOne(['category_id' => $parent->id, 'synced_at' => lately()]);

    pruneTombstones();

    expect(stillStored($child))->toBeTrue()
        ->and(stillStored($parent))->toBeTrue();
});

it('deletes a chain of tombstones in one run, the children first', function (): void {
    $user = User::factory()->createOne();
    $category = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    $subcategory = SubcategoryFactory::new()->ownedBy($user)->deleted()->createOne(['category_id' => $category->id, 'synced_at' => longAgo()]);
    $product = ProductFactory::new()->ownedBy($user)->deleted()->createOne([
        'main_category_id' => $category->id,
        'sub_category_id' => $subcategory->id,
        'synced_at' => longAgo(),
    ]);
    $price = ProductPriceFactory::new()->ownedBy($user)->deleted()->createOne(['product_id' => $product->id, 'synced_at' => longAgo()]);

    $report = app(TombstonePruner::class)->prune(180);

    expect(array_filter($report['deleted']))->toBe(['product-price' => 1, 'product' => 1, 'subcategory' => 1, 'category' => 1])
        ->and(array_map(stillStored(...), [$price, $product, $subcategory, $category]))->toBe([false, false, false, false]);
});

it('deletes the categories of a pocket along with it, freeing the category too', function (): void {
    $user = User::factory()->createOne();
    $category = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    $pocket = BudgetPocketFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    linkPocket($pocket, $category);

    pruneTombstones();

    expect(stillStored($pocket))->toBeFalse()
        ->and(stillStored($category))->toBeFalse()
        ->and(DB::table(PackageConfig::table(SyncStore::POCKET_CATEGORIES))->count())->toBe(0);
});

it('keeps a category a living pocket holds', function (): void {
    $user = User::factory()->createOne();
    $category = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    linkPocket(BudgetPocketFactory::new()->ownedBy($user)->createOne(), $category);

    pruneTombstones();

    expect(stillStored($category))->toBeTrue();
});

it('keeps the users apart: another user\'s rows under the same id refer to nothing of this one', function (): void {
    [$anna, $bela] = User::factory()->count(2)->create()->all();
    $seed = CategoryFactory::new()->seed(1);

    $annas = $seed->ownedBy($anna)->deleted()->createOne(['synced_at' => longAgo()]);
    $belas = $seed->ownedBy($bela)->deleted()->createOne(['synced_at' => longAgo()]);
    ExpenseFactory::new()->ownedBy($bela)->createOne(['main_category_id' => $belas->id]);

    pruneTombstones();

    expect(stillStored($annas))->toBeFalse()
        ->and(stillStored($belas))->toBeTrue();
});

it('raises pruned_through to the highest sequence number deleted, and never lowers it', function (): void {
    $user = User::factory()->createOne();
    $category = CategoryFactory::new()->ownedBy($user)->deleted();
    $first = $category->createOne(['synced_at' => longAgo()]);
    $last = $category->createOne(['synced_at' => longAgo()]);
    $kept = $category->createOne(['synced_at' => lately()]);

    pruneTombstones();

    expect(prunedThrough())->toBe($last->server_seq)
        ->and($first->server_seq)->toBeLessThan($last->server_seq)
        ->and($kept->server_seq)->toBeGreaterThan($last->server_seq);

    DB::table(PackageConfig::table(SyncSequence::TABLE))->update(['pruned_through' => 999_999]);
    $category->createOne(['synced_at' => longAgo()]);

    pruneTombstones();

    expect(prunedThrough())->toBe(999_999);
});

it('sends an older cursor a 410, a fresh one its changes', function (): void {
    $user = SyncApi::userWithProfile();
    $tombstone = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    $later = CategoryFactory::new()->ownedBy($user)->createOne();

    pruneTombstones();

    SyncApi::assertError(SyncApi::pull($user, 's:'.($tombstone->server_seq - 1)), 410, 'cursor_expired');

    $page = SyncApi::pullOk($user, 's:'.$tombstone->server_seq);

    expect(array_column(SyncApi::group(SyncApi::records($page), 'category'), 'id'))->toBe([$later->id]);
});

it('counts without deleting on a dry run, as many as a run deletes', function (): void {
    $user = User::factory()->createOne();
    $category = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    SubcategoryFactory::new()->ownedBy($user)->deleted()->createOne(['category_id' => $category->id, 'synced_at' => longAgo()]);
    $held = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    SubcategoryFactory::new()->ownedBy($user)->deleted()->createOne(['category_id' => $held->id, 'synced_at' => lately()]);
    $writes = 0;

    DB::listen(static function (QueryExecuted $query) use (&$writes): void {
        $writes += preg_match('/^\s*(delete|update|insert)/i', $query->sql);
    });

    $dryRun = app(TombstonePruner::class)->prune(180, dryRun: true);

    expect($writes)->toBe(0)
        ->and(Category::query()->count())->toBe(2)
        ->and(prunedThrough())->toBe(0)
        ->and(array_filter($dryRun['deleted']))->toBe(['subcategory' => 1, 'category' => 1]);

    expect(app(TombstonePruner::class)->prune(180)['deleted'])->toBe($dryRun['deleted']);
});

it('keeps tombstones younger than --days', function (): void {
    $user = User::factory()->createOne();
    $tombstone = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => lately()]);

    pruneTombstones(['--days' => '30']);
    expect(stillStored($tombstone))->toBeTrue();

    pruneTombstones(['--days' => '5']);
    expect(stillStored($tombstone))->toBeFalse();
});

it('takes the retention from the config', function (): void {
    Config::set('expenses.pruning.tombstone_days', 5);
    $tombstone = CategoryFactory::new()->ownedBy(User::factory()->createOne())->deleted()->createOne(['synced_at' => lately()]);

    pruneTombstones();

    expect(stillStored($tombstone))->toBeFalse();
});

it('refuses a --days that is not a positive integer', function (string $days): void {
    expect(pruneTombstones(['--days' => $days]))->toBe(2)
        ->and(Artisan::output())->toContain('The --days option must be a positive integer.');
})->with(['0', '-1', 'x', '1.5']);

it('deletes in batches of a transaction each', function (): void {
    $user = User::factory()->createOne();
    CategoryFactory::new()->ownedBy($user)->deleted()->count(TombstonePruner::BATCH + 1)->create(['synced_at' => longAgo()]);
    $deletes = 0;
    $table = PackageConfig::table('categories');

    DB::listen(static function (QueryExecuted $query) use (&$deletes, $table): void {
        $deletes += (int) str_starts_with($query->sql, "delete from `{$table}`");
    });

    pruneTombstones();

    expect(Category::query()->count())->toBe(0)
        ->and($deletes)->toBe(2);
});

it('writes a log line with the counts and the new mark', function (): void {
    $user = User::factory()->createOne();
    $tombstone = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    /** @var list<MessageLogged> $logged */
    $logged = [];
    Event::listen(static function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message;
    });

    pruneTombstones();

    expect($logged)->toHaveCount(1)
        ->and($logged[0]->level)->toBe('info')
        ->and($logged[0]->message)->toBe('Expenses tombstones pruned.')
        ->and($logged[0]->context)->toHaveKey('deleted.category', 1)
        ->and($logged[0]->context)->toHaveKey('prunedThrough', $tombstone->server_seq);
});

it('leaves a tombstone a push revived after it was picked', function (): void {
    $user = User::factory()->createOne();
    $tombstone = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    $store = app(SyncStore::class);
    $picked = $store->prunableTombstones('category', lately(), 0, 10);

    Category::query()->ownedBy($user)->whereKey($tombstone->id)->update(['deleted_at' => null]);

    $deleted = DB::transaction(static fn (): array => $store->deleteTombstones('category', lately(), array_column($picked, 0), array_column($picked, 1)));

    expect($picked)->toBe([[$tombstone->server_seq, $user->getKey()]])
        ->and($deleted)->toBe([])
        ->and(stillStored($tombstone))->toBeTrue();
});

it('sends a 410 to a pull the pruning overtakes between its queries', function (): void {
    $user = SyncApi::userWithProfile();
    $tombstone = CategoryFactory::new()->ownedBy($user)->deleted()->createOne(['synced_at' => longAgo()]);
    $pruned = false;

    // Right after the pull read the currencies, before it reads the categories.
    DB::listen(static function (QueryExecuted $query) use (&$pruned): void {
        if (! $pruned && str_contains($query->sql, 'from `'.PackageConfig::table('currencies').'`')) {
            $pruned = true;
            app(TombstonePruner::class)->prune(180);
        }
    });

    SyncApi::assertError(SyncApi::pull($user, 's:'.($tombstone->server_seq - 1)), 410, 'cursor_expired');

    expect($pruned)->toBeTrue()
        ->and(stillStored($tombstone))->toBeFalse();
});

it('matches the foreign keys that point at the synced rows', function (): void {
    $registry = RecordStore::registry();
    $table = static fn (string $resource): string => (new ($registry->ownedModel($resource)))->getTable();
    $expected = [];

    foreach (SyncStore::REFERRERS as $parent => $referrers) {
        foreach ($referrers as [$child, $column, $viaPivot]) {
            $expected[] = ($viaPivot ? PackageConfig::table(SyncStore::POCKET_CATEGORIES) : $table($child))
                ."(user_id,{$column}) -> ".$table($parent).'(user_id,id)';
        }
    }

    $owned = array_map(static fn (ResourceDefinition $definition): string => $table($definition->name), $registry->pushable());
    $actual = [];

    // `table(cols) -> parent(cols) RESTRICT/RESTRICT`, without the rules.
    foreach (SchemaInspector::current()->foreignKeys() as $foreignKey) {
        $key = implode(' ', array_slice(explode(' ', $foreignKey), 0, 3));

        if (preg_match('/-> (\w+)\(user_id,id\)$/', $key, $match) === 1 && in_array($match[1], $owned, true)
            && ! str_starts_with($key, PackageConfig::table(SyncStore::POCKET_CATEGORIES).'(user_id,budget_pocket_id)')) {
            $actual[] = $key;
        }
    }

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected);
});
