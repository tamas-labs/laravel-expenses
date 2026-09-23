<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use TamasLabs\LaravelExpenses\Models\Concerns\HasSyncBookkeeping;

/**
 * A record owned by one user, identified by `(user_id, id)`.
 *
 * The client creates its seed records (default categories, payment methods,
 * products) with the same fixed UUIDs for every user, so `id` alone is not
 * unique. Eloquent only knows single-column keys: `$primaryKey` stays `id`,
 * and the queries that address this row (save, update, delete, increment,
 * refresh, fresh) also get `user_id` in their WHERE.
 *
 * Never look a row up by `id` alone — no `find()`, no route model binding:
 * query through `ownedBy()`. For the same reason there are no Eloquent
 * relations between user rows; a `belongsTo` would only match on `id`.
 *
 * @property string $id
 * @property int|string $user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property int $server_seq
 * @property CarbonImmutable $synced_at
 */
abstract class SyncModel extends Model
{
    use HasSyncBookkeeping;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        parent::setKeysForSaveQuery($query)->where('user_id', '=', $this->ownerForQuery());

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        parent::setKeysForSelectQuery($query)->where('user_id', '=', $this->ownerForQuery());

        return $query;
    }

    /**
     * Rows of the given user.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function ownedBy(Builder $query, Authenticatable $user): void
    {
        $query->where($query->qualifyColumn('user_id'), $user->getAuthIdentifier());
    }

    /**
     * @throws LogicException Always: an id alone may match rows of several users.
     */
    public function resolveRouteBinding($value, $field = null): never
    {
        throw new LogicException(sprintf(
            '%s has no route model binding: its id is only unique per user. Query it through ownedBy().',
            static::class,
        ));
    }

    /**
     * @throws LogicException Always: an id alone may match rows of several users.
     */
    public function resolveChildRouteBinding($childType, $value, $field): never
    {
        $this->resolveRouteBinding($value, $field);
    }

    /**
     * The owner the row was loaded with, as the key uses the original id.
     */
    private function ownerForQuery(): mixed
    {
        return $this->original['user_id'] ?? $this->getAttribute('user_id');
    }
}
