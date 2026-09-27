<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
     * @return Builder<SyncModel>
     *
     * @throws InvalidArgumentException When the resource's rows are not owned by a user.
     */
    private function owned(string $resource, Authenticatable $user): Builder
    {
        return $this->registry->ownedModel($resource)::query()->ownedBy($user);
    }
}
