<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;
use TamasLabs\LaravelExpenses\Database\SyncSequence;
use TamasLabs\LaravelExpenses\Models\BudgetPocket;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Registry\Scope;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * Every read and write of the synced tables (spec 06, 5.4), each one filtered
 * by the owner: the one place that keeps users apart. Nothing else queries
 * the models (a test holds it).
 *
 * The queries are bulk: one per resource and call, whatever the number of
 * records. Bound as a singleton; it keeps no state.
 */
final class SyncStore
{
    /**
     * The pivot of the pockets' `categoryIds`, without the table prefix.
     */
    public const string POCKET_CATEGORIES = 'budget_pocket_categories';

    /**
     * Who refers to the rows of a resource: resource → the referring
     * resources and their columns; `true` when the column is in the pockets'
     * pivot. The foreign keys, as the pruning reads them (a test compares
     * the two).
     *
     * @var array<string, list<array{string, string, bool}>>
     */
    public const array REFERRERS = [
        'category' => [
            ['subcategory', 'category_id', false],
            ['expense', 'main_category_id', false],
            ['product', 'main_category_id', false],
            ['budget-pocket', 'category_id', true],
        ],
        'subcategory' => [['expense', 'sub_category_id', false], ['product', 'sub_category_id', false]],
        'payment-method' => [['expense', 'payment_method_id', false]],
        'expense' => [['product-price', 'source_expense_id', false]],
        'product' => [['product-price', 'product_id', false]],
        'shopping-list' => [['shopping-list-item', 'shopping_list_id', false]],
    ];

    public function __construct(private readonly ResourceRegistry $registry) {}

    /**
     * Locks the user's row until the transaction ends, so that one user's
     * writes run one after the other (spec 06, 4.2/1). Always taken before
     * the sequence.
     *
     * @throws LogicException When no transaction is open, or the user has no row.
     */
    public function lockUser(Authenticatable $user): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The user can only be locked inside a transaction.');
        }

        $key = $user->getAuthIdentifierName();

        $locked = DB::table(PackageConfig::usersTable())
            ->where($key, $user->getAuthIdentifier())
            ->lockForUpdate()
            ->value($key);

        if ($locked === null) {
            throw new LogicException('The user to lock has no row.');
        }
    }

    /**
     * The user's row as stored now: under {@see self::lockUser()}, the
     * version a profile change is decided against (spec 07, 5.5).
     *
     * @throws LogicException When the user has no row.
     */
    public function account(Authenticatable $user): Model
    {
        return PackageConfig::userModel()::query()->whereKey($user->getAuthIdentifier())->first()
            ?? throw new LogicException('The user has no row.');
    }

    /**
     * Saves the user's row — a new account or a changed profile — with a
     * reserved sequence number, so the pull carries it to the other devices.
     */
    public function saveAccount(Model $user, int $seq): void
    {
        $user->forceFill(['server_seq' => $seq])->save();
    }

    /**
     * Deletes every synced row of the user (spec 07, 5.6): the pockets'
     * pivot first, then the resources against the push order, children
     * before their parents, as the RESTRICT foreign keys ask.
     */
    public function deleteOwnedData(Authenticatable $user): void
    {
        DB::table(PackageConfig::table(self::POCKET_CATEGORIES))->where('user_id', $user->getAuthIdentifier())->delete();

        foreach (array_reverse($this->registry->pushable()) as $definition) {
            $this->owned($definition->name, $user)->delete();
        }
    }

    /**
     * The server's version of the given records of the user, by id; a pocket
     * with its categories loaded.
     *
     * @param  list<string>  $ids
     * @return array<string, SyncModel>
     */
    public function existing(string $resource, Authenticatable $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = [];

        foreach ($this->owned($resource, $user)->whereIn('id', $ids)->get() as $row) {
            $rows[$row->id] = $row;
        }

        $this->loadPocketCategories($user, $rows);

        return $rows;
    }

    /**
     * Which of the given ids name a record of the user, tombstones included.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    public function referencesExist(string $resource, Authenticatable $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = [];

        foreach ($this->owned($resource, $user)->whereIn('id', $ids)->pluck('id') as $id) {
            if (\is_string($id)) {
                $found[$id] = true;
            }
        }

        return $found;
    }

    /**
     * The user's live rows holding any of the given values, with the id and
     * the given columns: what the unique rule (S2) compares against.
     *
     * @param  array<string, list<string>>  $values  Column → the values looked for.
     * @param  list<string>  $columns  The columns to read.
     * @return list<SyncModel>
     */
    public function livingConflicts(string $resource, Authenticatable $user, array $values, array $columns): array
    {
        if ($values === []) {
            return [];
        }

        return array_values($this->owned($resource, $user)
            ->alive()
            ->where(static function (Builder $query) use ($values): void {
                foreach ($values as $column => $wanted) {
                    $query->orWhereIn($column, $wanted);
                }
            })
            ->get(['id', ...$columns])
            ->all());
    }

    /**
     * Every currency, retired or not.
     *
     * @return array<string, bool> id → retired
     */
    public function currencies(): array
    {
        $currencies = [];

        foreach (Currency::query()->get(['id', 'deleted_at']) as $currency) {
            $currencies[$currency->id] = $currency->deleted_at !== null;
        }

        return $currencies;
    }

    /**
     * Reserves `$count` sequence numbers (spec 06, 4.2/2); the counter stays
     * locked until the transaction commits.
     *
     * @return array{int, int} The first and the last number reserved.
     */
    public function allocateSequence(int $count): array
    {
        $last = SyncSequence::reserve($count);

        return [$last - $count + 1, $last];
    }

    /**
     * The last reserved sequence number and the pruning mark.
     *
     * @return array{value: int, prunedThrough: int}
     */
    public function sequenceState(): array
    {
        return SyncSequence::state();
    }

    /**
     * Raises the pruning mark to `$seq`; never lowers it.
     */
    public function raisePrunedThrough(int $seq): void
    {
        SyncSequence::raisePrunedThrough($seq);
    }

    /**
     * The next tombstones of a resource the pruning may delete (spec 08,
     * 3.3), in `server_seq` order after `$afterSeq`: stored before `$before`,
     * and no row refers to them. Read without a lock;
     * {@see self::deleteTombstones()} checks them again.
     *
     * @return list<array{int, int|string}> `server_seq` and the owner's key.
     */
    public function prunableTombstones(string $resource, CarbonImmutable $before, int $afterSeq, int $limit): array
    {
        $rows = $this->tombstones($resource, $before, false)
            ->where('t0.server_seq', '>', $afterSeq)
            ->orderBy('t0.server_seq')
            ->limit($limit)
            ->get(['t0.server_seq', 't0.user_id']);

        return array_values(array_map(static fn (object $row): array => [self::intColumn($row, 'server_seq'), self::keyColumn($row, 'user_id')], $rows->all()));
    }

    /**
     * How many tombstones of a resource one pruning would delete: those no
     * row refers to but tombstones deleted along with them, the way a
     * pruning run deletes the children before their parents.
     */
    public function countPrunableTombstones(string $resource, CarbonImmutable $before): int
    {
        return $this->tombstones($resource, $before, true)->count();
    }

    /**
     * Deletes the given tombstones that are still prunable, under the locks
     * of their owners: a push of theirs can neither revive nor refer to one
     * meanwhile (a pocket's categories go with it). Only inside a transaction.
     *
     * @param  list<int>  $seqs  The tombstones' `server_seq`s.
     * @param  list<int|string>  $userKeys  Their owners.
     * @return list<int> The `server_seq`s deleted.
     *
     * @throws LogicException When no transaction is open.
     */
    public function deleteTombstones(string $resource, CarbonImmutable $before, array $seqs, array $userKeys): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Tombstones can only be deleted inside a transaction.');
        }

        if ($seqs === []) {
            return [];
        }

        $this->lockUsers($userKeys);

        $rows = $this->tombstones($resource, $before, false)
            ->whereIn('t0.server_seq', $seqs)
            ->get(['t0.server_seq', 't0.user_id', 't0.id'])
            ->all();

        if ($rows === []) {
            return [];
        }

        if ($this->registry->ownedModel($resource) === BudgetPocket::class) {
            $pockets = [];

            foreach ($rows as $row) {
                $pockets[self::keyColumn($row, 'user_id')][] = self::stringColumn($row, 'id');
            }

            foreach ($pockets as $owner => $ids) {
                DB::table(PackageConfig::table(self::POCKET_CATEGORIES))->where('user_id', $owner)->whereIn('budget_pocket_id', $ids)->delete();
            }
        }

        $deleted = array_map(static fn (object $row): int => self::intColumn($row, 'server_seq'), $rows);

        DB::table($this->tableOf($resource))->whereIn('server_seq', $deleted)->delete();

        return array_values($deleted);
    }

    /**
     * Takes the given live rows out of their unique keys until the write
     * gives them their final values: a temporary `deleted_at` turns the
     * generated key columns NULL (spec 06, 5.3). Only inside the transaction
     * that writes them; no other transaction sees the interim state.
     *
     * @param  list<string>  $ids
     */
    public function releaseUniqueKeys(string $resource, Authenticatable $user, array $ids, CarbonImmutable $at): void
    {
        if ($ids === []) {
            return;
        }

        $this->owned($resource, $user)
            ->alive()
            ->whereIn('id', $ids)
            ->update(['deleted_at' => $at->utc()->format(UtcDateTime::FORMAT)]);
    }

    /**
     * Writes decided rows of the user with consecutive sequence numbers from
     * `$firstSeq`, in the given order.
     *
     * The rows' raw attributes are written, as the casts made them: the query
     * builder applies no casts (spec 03, 7/1). New rows go in with a plain
     * insert, so a key clash fails instead of turning into an update of
     * another row; existing ones with an upsert on `(user_id, id)`.
     *
     * @param  list<SyncModel>  $rows  A row that `exists` replaces the stored one.
     */
    public function write(string $resource, Authenticatable $user, array $rows, int $firstSeq, CarbonImmutable $syncedAt): void
    {
        $inserts = [];
        $updates = [];
        $seq = $firstSeq;
        $synced = $syncedAt->utc()->format(UtcDateTime::FORMAT);

        foreach ($rows as $row) {
            $values = [
                ...$row->getAttributes(),
                'user_id' => $user->getAuthIdentifier(),
                'server_seq' => $seq++,
                'synced_at' => $synced,
            ];

            if ($row->exists) {
                $updates[] = $values;
            } else {
                $inserts[] = $values;
            }
        }

        $model = $this->registry->ownedModel($resource);

        if ($inserts !== []) {
            $model::query()->insert($inserts);
        }

        if ($updates !== []) {
            $model::query()->upsert($updates, ['user_id', 'id'], array_values(array_diff(array_keys($updates[0]), ['user_id', 'id'])));
        }
    }

    /**
     * Replaces the categories of the given pockets in the pivot.
     *
     * @param  array<string, list<string>>  $categoryIds  Pocket id → its categories.
     */
    public function replacePocketCategories(Authenticatable $user, array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        $owner = $user->getAuthIdentifier();
        $pivot = DB::table(PackageConfig::table(self::POCKET_CATEGORIES));

        (clone $pivot)->where('user_id', $owner)->whereIn('budget_pocket_id', array_map(strval(...), array_keys($categoryIds)))->delete();

        $rows = [];

        foreach ($categoryIds as $pocketId => $ids) {
            foreach ($ids as $categoryId) {
                $rows[] = ['user_id' => $owner, 'budget_pocket_id' => (string) $pocketId, 'category_id' => $categoryId];
            }
        }

        if ($rows !== []) {
            (clone $pivot)->insert($rows);
        }
    }

    /**
     * The rows of a resource changed after `$cursor` up to `$through`, oldest
     * first, at most `$limit`: the user's own, the global currencies, or the
     * user's account row. Pockets come without their categories
     * ({@see self::pocketCategoryIds()}).
     *
     * @return list<Model>
     */
    public function changedSince(string $resource, Authenticatable $user, int $cursor, int $through, int $limit): array
    {
        $definition = $this->registry->get($resource);

        $query = match ($definition->scope) {
            Scope::Owned => $this->owned($resource, $user)->changedSince($cursor),
            Scope::Global => Currency::query()->changedSince($cursor),
            Scope::Account => $definition->model::query()
                ->whereKey($user->getAuthIdentifier())
                ->where('server_seq', '>', $cursor)
                ->orderBy('server_seq'),
        };

        return array_values($query->where('server_seq', '<=', $through)->limit($limit)->get()->all());
    }

    /**
     * The categories of the user's given pockets, with one query.
     *
     * @param  list<string>  $pocketIds
     * @return array<string, list<string>> Pocket id → category ids; `[]` for a pocket without any.
     */
    public function pocketCategoryIds(Authenticatable $user, array $pocketIds): array
    {
        if ($pocketIds === []) {
            return [];
        }

        $categoryIds = array_fill_keys($pocketIds, []);

        $links = DB::table(PackageConfig::table(self::POCKET_CATEGORIES))
            ->where('user_id', $user->getAuthIdentifier())
            ->whereIn('budget_pocket_id', $pocketIds)
            ->get(['budget_pocket_id', 'category_id']);

        foreach ($links as $link) {
            $pocketId = $link->budget_pocket_id ?? null;
            $categoryId = $link->category_id ?? null;

            if (! \is_string($pocketId) || ! \is_string($categoryId)) {
                throw new LogicException('A budget_pocket_categories row holds no ids.');
            }

            $categoryIds[$pocketId][] = $categoryId;
        }

        return $categoryIds;
    }

    /**
     * Loads the categories of the pockets among the rows.
     *
     * @param  array<array-key, Model>  $rows
     */
    public function loadPocketCategories(Authenticatable $user, array $rows): void
    {
        $pockets = array_values(array_filter($rows, static fn (Model $row): bool => $row instanceof BudgetPocket));

        if ($pockets === []) {
            return;
        }

        $categoryIds = $this->pocketCategoryIds($user, array_map(static fn (BudgetPocket $pocket): string => $pocket->id, $pockets));

        foreach ($pockets as $pocket) {
            $pocket->categoryIds = $categoryIds[$pocket->id];
        }
    }

    /**
     * Locks the users' rows until the transaction ends, in key order, as
     * {@see self::lockUser()} locks one.
     *
     * @param  list<int|string>  $keys
     */
    private function lockUsers(array $keys): void
    {
        $keys = array_values(array_unique($keys));
        sort($keys);
        $model = PackageConfig::userModel();
        $column = (new $model)->getKeyName();

        DB::table(PackageConfig::usersTable())->whereIn($column, $keys)->orderBy($column)->lockForUpdate()->pluck($column);
    }

    /**
     * The tombstones of a resource stored before `$before` that no row
     * refers to (`t0`). With `$cascade`, a referring tombstone that could be
     * pruned itself does not count.
     */
    private function tombstones(string $resource, CarbonImmutable $before, bool $cascade): QueryBuilder
    {
        $before = $before->utc()->format(UtcDateTime::FORMAT);
        $aliases = 0;

        $query = DB::table($this->tableOf($resource).' as t0')
            ->whereNotNull('t0.deleted_at')
            ->where('t0.synced_at', '<', $before);

        $this->whereUnreferenced($query, $resource, 't0', $before, $cascade, $aliases);

        return $query;
    }

    /**
     * Keeps the rows of `$alias` no row of the referring resources points
     * at; with `$cascade`, none but tombstones stored before `$before` that
     * are unreferenced in the same sense.
     */
    private function whereUnreferenced(QueryBuilder $query, string $resource, string $alias, string $before, bool $cascade, int &$aliases): void
    {
        foreach (self::referrersOf($resource) as [$child, $column, $viaPivot]) {
            $query->whereNotExists(function (QueryBuilder $referring) use ($child, $column, $viaPivot, $alias, $before, $cascade, &$aliases): void {
                $row = 't'.++$aliases;

                if ($viaPivot) {
                    $link = 't'.++$aliases;

                    $referring->from(PackageConfig::table(self::POCKET_CATEGORIES).' as '.$link)
                        ->join($this->tableOf($child).' as '.$row, static function (JoinClause $join) use ($row, $link): void {
                            $join->on("{$row}.user_id", '=', "{$link}.user_id")->on("{$row}.id", '=', "{$link}.budget_pocket_id");
                        })
                        ->whereColumn("{$link}.user_id", "{$alias}.user_id")
                        ->whereColumn("{$link}.{$column}", "{$alias}.id");
                } else {
                    $referring->from($this->tableOf($child).' as '.$row)
                        ->whereColumn("{$row}.user_id", "{$alias}.user_id")
                        ->whereColumn("{$row}.{$column}", "{$alias}.id");
                }

                if (! $cascade) {
                    return;
                }

                // Only a row that stays behind refers: alive, too young, or
                // referred to itself.
                $referring->where(function (QueryBuilder $stays) use ($child, $row, $before, &$aliases): void {
                    $stays->whereNull("{$row}.deleted_at")->orWhere("{$row}.synced_at", '>=', $before);

                    if (self::referrersOf($child) !== []) {
                        $stays->orWhereNot(function (QueryBuilder $unreferenced) use ($child, $row, $before, &$aliases): void {
                            $this->whereUnreferenced($unreferenced, $child, $row, $before, true, $aliases);
                        });
                    }
                });
            });
        }
    }

    /**
     * @return list<array{string, string, bool}>
     */
    private static function referrersOf(string $resource): array
    {
        return self::REFERRERS[$resource] ?? [];
    }

    private function tableOf(string $resource): string
    {
        return (new ($this->registry->ownedModel($resource)))->getTable();
    }

    /**
     * @throws LogicException When the column holds no integer.
     */
    private static function intColumn(object $row, string $column): int
    {
        $value = $row->{$column} ?? null;

        return \is_int($value) ? $value : throw new LogicException(sprintf('The %s column holds no integer.', $column));
    }

    /**
     * @throws LogicException When the column holds no string.
     */
    private static function stringColumn(object $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        return \is_string($value) ? $value : throw new LogicException(sprintf('The %s column holds no string.', $column));
    }

    /**
     * @throws LogicException When the column holds no key.
     */
    private static function keyColumn(object $row, string $column): int|string
    {
        $value = $row->{$column} ?? null;

        return \is_int($value) || \is_string($value) ? $value : throw new LogicException(sprintf('The %s column holds no key.', $column));
    }

    /**
     * @return Builder<SyncModel>
     *
     * @throws InvalidArgumentException When the resource's rows are not owned by a user.
     */
    private function owned(string $resource, Authenticatable $user): Builder
    {
        return $this->registry->ownedModel($resource)::query()->ownedBy($user);
    }
}
